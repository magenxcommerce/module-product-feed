<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Google;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Thin REST client for the Merchant API data sources service.
 *
 * VERSION: v1. The v1beta surface this API launched with was SHUT DOWN on
 * 2026-02-28, and the older Content API for Shopping v2.1 sunsets on 2026-08-18,
 * after which its endpoints return 410 Gone. v1 is the only supported path, and
 * "datasources/v1" is a sub-API path segment rather than a host - the base URL is
 * shared across the Merchant API's services.
 *
 * The request and response shapes below were taken from the service's own
 * discovery document rather than from prose documentation, which is why, for
 * example, PrimaryProductDataSource carries `destinations` and not the `channel`
 * field older guides describe.
 */
class MerchantApiClient
{
    private const BASE_URL = 'https://merchantapi.googleapis.com/datasources/v1';

    public const FREQUENCY_DAILY = 'FREQUENCY_DAILY';
    public const FREQUENCY_WEEKLY = 'FREQUENCY_WEEKLY';
    public const FREQUENCY_MONTHLY = 'FREQUENCY_MONTHLY';

    public const INPUT_FILE = 'FILE';
    public const FILE_INPUT_FETCH = 'FETCH';

    public function __construct(
        private readonly ServiceAccountAuthenticator $authenticator,
        private readonly CurlFactory $curlFactory
    ) {
    }

    /**
     * Find a data source by its display name.
     *
     * Matching on displayName rather than storing the returned id is deliberate:
     * a merchant who deletes the data source in the Merchant Center UI would
     * otherwise leave us holding an id that no longer resolves, and every
     * subsequent upload would fail with NOT_FOUND until someone cleared the stored
     * value by hand. Looking it up each time makes deletion self-healing - the
     * next run simply creates it again.
     *
     * @return array<string, mixed>|null
     * @throws LocalizedException
     */
    public function findDataSourceByDisplayName(string $keyFile, string $accountId, string $displayName): ?array
    {
        $pageToken = null;

        do {
            $query = ['pageSize' => 100];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->request(
                $keyFile,
                'GET',
                sprintf('/accounts/%s/dataSources?%s', rawurlencode($accountId), http_build_query($query))
            );

            foreach ($response['dataSources'] ?? [] as $dataSource) {
                if (($dataSource['displayName'] ?? null) === $displayName) {
                    return $dataSource;
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while (is_string($pageToken) && $pageToken !== '');

        return null;
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function createDataSource(string $keyFile, string $accountId, array $dataSource): array
    {
        return $this->request(
            $keyFile,
            'POST',
            sprintf('/accounts/%s/dataSources', rawurlencode($accountId)),
            $dataSource
        );
    }

    /**
     * @param string $name Full resource name, accounts/{account}/dataSources/{id}
     * @param array<string, mixed> $dataSource
     * @param string[] $updateMask
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function updateDataSource(string $keyFile, string $name, array $dataSource, array $updateMask): array
    {
        return $this->request(
            $keyFile,
            'PATCH',
            sprintf('/%s?updateMask=%s', $name, rawurlencode(implode(',', $updateMask))),
            $dataSource
        );
    }

    /**
     * Ask Google to fetch the file now, outside its own schedule.
     *
     * @param string $name Full resource name
     * @throws LocalizedException
     */
    public function fetchDataSource(string $keyFile, string $name): void
    {
        // The API requires an empty JSON object as the body, not an absent one.
        $this->request($keyFile, 'POST', sprintf('/%s:fetch', $name), []);
    }

    /**
     * Build the DataSource body for a fetch-based primary product feed.
     *
     * @param string[] $countries
     * @return array<string, mixed>
     */
    public function buildPrimaryProductDataSource(
        string $displayName,
        string $fileName,
        string $fetchUri,
        string $feedLabel,
        string $contentLanguage,
        array $countries,
        string $frequency,
        string $timeZone
    ): array {
        $primary = [];

        // Every one of these is optional, and an EMPTY value must be omitted
        // rather than sent: Google validates feedLabel and contentLanguage
        // strictly, and sending "" is rejected where sending nothing means
        // "inherit the account default".
        if ($feedLabel !== '') {
            $primary['feedLabel'] = $feedLabel;
        }
        if ($contentLanguage !== '') {
            $primary['contentLanguage'] = $contentLanguage;
        }
        if ($countries !== []) {
            $primary['countries'] = array_values($countries);
        }

        return [
            'displayName' => $displayName,
            'primaryProductDataSource' => $primary,
            'input' => self::INPUT_FILE,
            'fileInput' => [
                'fileName' => $fileName,
                'fileInputType' => self::FILE_INPUT_FETCH,
                'fetchSettings' => [
                    'enabled' => true,
                    'frequency' => $frequency,
                    'fetchUri' => $fetchUri,
                    'timeZone' => $timeZone,
                    // No timeOfDay: omitted, Google picks a slot. Pinning one makes
                    // every store on the same platform fetch at the same minute.
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function request(string $keyFile, string $method, string $path, ?array $body = null): array
    {
        $token = $this->authenticator->getAccessToken($keyFile);

        // A fresh client per call: Curl accumulates headers across requests, so a
        // shared instance would send the previous call's headers again.
        $curl = $this->curlFactory->create();
        $curl->setTimeout(60);
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');

        $url = self::BASE_URL . $path;
        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);

        try {
            switch ($method) {
                case 'GET':
                    $curl->get($url);
                    break;
                case 'POST':
                    $curl->post($url, $payload);
                    break;
                case 'PATCH':
                    $curl->setOptions([CURLOPT_CUSTOMREQUEST => 'PATCH']);
                    $curl->post($url, $payload);
                    break;
                default:
                    throw new LocalizedException(__('Unsupported HTTP method "%1".', $method));
            }
        } catch (\Throwable $e) {
            throw new LocalizedException(__('Could not reach the Google Merchant API: %1', $e->getMessage()), $e);
        }

        $status = $curl->getStatus();
        $raw = (string) $curl->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }

        throw new LocalizedException(__($this->explain($status, is_array($decoded) ? $decoded : [])));
    }

    /**
     * Turn an API error into something a merchant can act on.
     *
     * The raw messages are accurate and useless: "The caller does not have
     * permission" does not say that the fix is to add the service account as a
     * user, and a bare NOT_FOUND right after creating a data source is usually
     * propagation rather than a real absence.
     *
     * @param array<string, mixed> $body
     */
    private function explain(int $status, array $body): string
    {
        $message = (string) ($body['error']['message'] ?? sprintf('HTTP %d', $status));
        $reason = (string) ($body['error']['status'] ?? '');

        return match (true) {
            $status === 401 => sprintf(
                'Google rejected the credentials (%s). The service account key may have been revoked.',
                $message
            ),
            $status === 403 => sprintf(
                'Google denied access (%s). Add the service account as a user on the Merchant Center account '
                . '(Settings > Users), and register the Cloud project with that account under '
                . 'Settings > Developer registration - the Merchant API rejects every call until it is registered.',
                $message
            ),
            $reason === 'NOT_FOUND' || $status === 404 => sprintf(
                'Google could not find the requested resource (%s). If the data source was just created, '
                . 'wait a few minutes and retry - registration takes a short while to propagate.',
                $message
            ),
            $status === 429 => sprintf('Google is rate limiting this account (%s). The next run will retry.', $message),
            $status >= 500 => sprintf('The Google Merchant API is unavailable (%s). The next run will retry.', $message),
            default => sprintf('The Google Merchant API returned an error (%s).', $message),
        };
    }
}
