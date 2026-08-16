<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Api\DelivererInterface;
use Magenx\ProductFeed\Model\Config;
use Magenx\ProductFeed\Model\Google\MerchantApiClient;
use Magenx\ProductFeed\Model\Google\ServiceAccountAuthenticator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the feed as a FETCH data source in Google Merchant Center and asks
 * Google to pull it.
 *
 * The file is not uploaded. Google fetches it from the feed's public URL, on its
 * own schedule and again immediately after each run - which is why the File
 * destination (or an nginx rule making /media/magenx-feed/ reachable) is a
 * prerequisite, and why the deliverer refuses to run when the URL is not public.
 *
 * TIMELINE THIS EXISTS FOR: the Content API for Shopping v2.1 sunsets on
 * 2026-08-18 and its endpoints then return 410 Gone; the Merchant API's own
 * v1beta was shut down on 2026-02-28. Only v1 remains, which is what
 * MerchantApiClient targets.
 *
 * Failures that are really configuration - no merchant id, no key, project not
 * registered - are reported as SKIPPED or as an explaining error rather than as
 * a generic failure, because every one of them has a different fix and the raw
 * API messages point at none of them.
 */
class GoogleDataSourceDeliverer implements DelivererInterface
{
    /**
     * Google will not fetch from a URL it cannot reach. A localhost or private
     * address is the single most common reason a first setup silently never
     * updates, so it is caught here rather than left to a fetch that just never
     * happens.
     */
    private const UNREACHABLE_HOST_PATTERNS = [
        '/^localhost$/i',
        '/^127\./',
        '/^10\./',
        '/^192\.168\./',
        '/^172\.(1[6-9]|2\d|3[01])\./',
        '/^169\.254\./',
        '/\.local$/i',
        '/\.internal$/i',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly MerchantApiClient $client,
        private readonly ServiceAccountAuthenticator $authenticator,
        private readonly TimezoneInterface $localeDate,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getLabel(): string
    {
        return (string) __('Google Merchant Center (Merchant API)');
    }

    public function isAvailable(DeliveryContext $context): DeliveryResult
    {
        $storeId = $context->feed->getStoreId();

        if ($this->config->getGoogleMerchantId($storeId) === '') {
            return DeliveryResult::skipped(
                __(
                    'No Merchant Center account ID is configured for this store view '
                    . '(Stores > Configuration > Magenx > Product Feeds > Google Merchant Center).'
                )->render()
            );
        }

        if ($this->config->getGoogleServiceAccountKeyFile($storeId) === '') {
            return DeliveryResult::skipped(
                __('No Google service account key is configured for this store view.')->render()
            );
        }

        return DeliveryResult::success('Available.');
    }

    public function testConnection(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        $storeId = $context->feed->getStoreId();
        $keyFile = $this->config->getGoogleServiceAccountKeyFile($storeId);
        $accountId = $this->config->getGoogleMerchantId($storeId);

        try {
            // Listing is the cheapest call that exercises the whole chain: key file,
            // JWT signing, token exchange, account permission and project
            // registration. It changes nothing.
            $this->client->findDataSourceByDisplayName($keyFile, $accountId, $this->displayName($context));

            $email = $this->authenticator->getClientEmail($keyFile);

            return DeliveryResult::success(
                __('Connected to Merchant Center account %1 as %2. Nothing was uploaded.', $accountId, $email)->render()
            );
        } catch (\Throwable $e) {
            return DeliveryResult::error($e->getMessage());
        }
    }

    public function deliver(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        $unreachable = $this->checkPubliclyFetchable($context->publicUrl);
        if ($unreachable !== null) {
            return DeliveryResult::error($unreachable);
        }

        $storeId = $context->feed->getStoreId();
        $keyFile = $this->config->getGoogleServiceAccountKeyFile($storeId);
        $accountId = $this->config->getGoogleMerchantId($storeId);
        $displayName = $this->displayName($context);

        try {
            $body = $this->client->buildPrimaryProductDataSource(
                $displayName,
                $context->filename,
                $context->publicUrl,
                $context->getString('feed_label'),
                $this->resolveContentLanguage($context),
                $this->resolveCountries($context),
                $this->resolveFrequency($context),
                $this->localeDate->getConfigTimezone(null, (string) $storeId)
            );

            $existing = $this->client->findDataSourceByDisplayName($keyFile, $accountId, $displayName);

            if ($existing === null) {
                $created = $this->client->createDataSource($keyFile, $accountId, $body);
                $name = (string) ($created['name'] ?? '');
                $action = __('Created data source');
            } else {
                $name = (string) ($existing['name'] ?? '');
                $this->client->updateDataSource(
                    $keyFile,
                    $name,
                    $body,
                    // Only the fields we own. A bare updateMask of "*" would wipe
                    // rules and destinations the merchant set in the Merchant Center
                    // UI, which this module has no business touching.
                    ['displayName', 'fileInput']
                );
                $action = __('Updated data source');
            }

            if ($name === '') {
                return DeliveryResult::error(
                    __('Google accepted the data source but returned no resource name.')->render()
                );
            }

            $this->client->fetchDataSource($keyFile, $name);

            return DeliveryResult::success(
                __('%1 "%2" and requested a fetch of %3.', $action, $displayName, $context->publicUrl)->render(),
                ['data_source' => $name, 'url' => $context->publicUrl]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'Magenx_ProductFeed: Google delivery of feed "%s" failed: %s',
                    $context->feed->getCode(),
                    $e->getMessage()
                )
            );

            return DeliveryResult::error($e->getMessage());
        }
    }

    /**
     * The data source is identified by its display name, so this must be stable
     * across runs - changing it creates a SECOND data source in the merchant's
     * account rather than updating the first, and the two then fight over the same
     * products.
     */
    private function displayName(DeliveryContext $context): string
    {
        $configured = $context->getString('display_name');
        if ($configured !== '') {
            return $configured;
        }

        return sprintf('Magento - %s', $context->feed->getCode());
    }

    private function checkPubliclyFetchable(string $url): ?string
    {
        if ($url === '') {
            return (string) __('The feed has no public URL for Google to fetch.');
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- parsing a configured endpoint URL, not a store URL.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return (string) __('The feed URL "%1" is not a valid absolute URL.', $url);
        }

        foreach (self::UNREACHABLE_HOST_PATTERNS as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return (string) __(
                    'The feed URL "%1" is not reachable from the public internet, so Google cannot fetch it. '
                    . 'Set the store\'s base media URL to its public hostname and make sure the web server '
                    . 'serves /media/magenx-feed/.',
                    $url
                );
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function resolveCountries(DeliveryContext $context): array
    {
        $raw = $context->getString('countries');
        if ($raw === '') {
            return [];
        }

        $codes = array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', $raw)
        );

        return array_values(array_filter($codes, static fn (string $c): bool => strlen($c) === 2));
    }

    /**
     * BCP-47 language for the landing pages.
     *
     * Derived from the store view's own locale by default. A mismatch between the
     * declared language and the actual page content is a Merchant Center policy
     * issue, and the store view knows its language better than a merchant filling
     * in a form does - so this is only overridden when explicitly set.
     */
    private function resolveContentLanguage(DeliveryContext $context): string
    {
        $configured = $context->getString('content_language');
        if ($configured !== '') {
            return $configured;
        }

        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $context->feed->getStoreId()
        );

        if ($locale === '') {
            return '';
        }

        // en_GB -> en. Google wants the language, and takes a region only where it
        // is meaningful (pt-BR); the bare language is always accepted.
        return strtolower(substr(str_replace('_', '-', $locale), 0, 2));
    }

    private function resolveFrequency(DeliveryContext $context): string
    {
        return match ($context->getString('fetch_frequency')) {
            'weekly' => MerchantApiClient::FREQUENCY_WEEKLY,
            'monthly' => MerchantApiClient::FREQUENCY_MONTHLY,
            default => MerchantApiClient::FREQUENCY_DAILY,
        };
    }
}
