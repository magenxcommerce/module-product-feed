<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magenx\ProductFeed\Model\Config\Source\AcceptsReturns;

/**
 * Typed reader over magenx_product_feed/*.
 *
 * Every getter takes an optional store id, because a feed is bound to one store
 * view and the Google credentials in particular are routinely different per
 * website (one Merchant Center account per country or brand).
 */
class Config
{
    public const XML_PATH_ENABLED = 'magenx_product_feed/general/enabled';

    public const XML_PATH_BATCH_SIZE = 'magenx_product_feed/generation/batch_size';
    public const XML_PATH_MAX_EXECUTION = 'magenx_product_feed/generation/max_execution_seconds';
    public const XML_PATH_VALIDATE = 'magenx_product_feed/generation/validate_after_generation';
    public const XML_PATH_HISTORY_DAYS = 'magenx_product_feed/generation/history_retention_days';

    public const XML_PATH_GOOGLE_MERCHANT_ID = 'magenx_product_feed/google/merchant_id';
    public const XML_PATH_GOOGLE_KEY_FILE = 'magenx_product_feed/google/service_account_key';

    public const XML_PATH_AGENTIC = 'magenx_product_feed/agentic/';
    public const XML_PATH_STORE_NAME = 'general/store_information/name';
    public const XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';

    public const XML_PATH_NOTIFY_ENABLED = 'magenx_product_feed/notifications/enabled';
    public const XML_PATH_NOTIFY_RECIPIENT = 'magenx_product_feed/notifications/recipient';
    public const XML_PATH_NOTIFY_TEMPLATE = 'magenx_product_feed/notifications/failure_template';

    /**
     * Hard floor and ceiling on the configurable batch size.
     *
     * Zero or a negative value would make the export loop forever without
     * advancing its cursor; an unbounded value would load the whole catalog into
     * memory in one batch, which is the exact failure the chunking exists to
     * prevent. Neither is reachable through the admin (the field validates as a
     * positive integer) but both are reachable through a direct core_config_data
     * write, so they are clamped rather than trusted.
     */
    private const BATCH_SIZE_MIN = 1;
    private const BATCH_SIZE_MAX = 5000;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_ENABLED, $storeId);
    }

    public function getBatchSize(?int $storeId = null): int
    {
        $value = (int) $this->value(self::XML_PATH_BATCH_SIZE, $storeId);

        return max(self::BATCH_SIZE_MIN, min(self::BATCH_SIZE_MAX, $value ?: 200));
    }

    public function getMaxExecutionSeconds(?int $storeId = null): int
    {
        return max(1, (int) $this->value(self::XML_PATH_MAX_EXECUTION, $storeId) ?: 50);
    }

    public function shouldValidateAfterGeneration(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_VALIDATE, $storeId);
    }

    public function getHistoryRetentionDays(?int $storeId = null): int
    {
        return max(1, (int) $this->value(self::XML_PATH_HISTORY_DAYS, $storeId) ?: 30);
    }

    public function getGoogleMerchantId(?int $storeId = null): string
    {
        return trim((string) $this->value(self::XML_PATH_GOOGLE_MERCHANT_ID, $storeId));
    }

    /**
     * File NAME of the service-account key, not a path.
     *
     * It is always resolved inside var/magenx_feed/gmc/ by GoogleCredentials, so a
     * traversal in this value cannot escape that directory. Never move this file
     * under pub/media: that directory is web-served and this key grants
     * programmatic access to the merchant's Merchant Center account.
     */
    public function getGoogleServiceAccountKeyFile(?int $storeId = null): string
    {
        return trim((string) $this->value(self::XML_PATH_GOOGLE_KEY_FILE, $storeId));
    }

    public function isNotificationEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_NOTIFY_ENABLED, $storeId);
    }

    public function getNotificationRecipient(?int $storeId = null): string
    {
        return trim((string) $this->value(self::XML_PATH_NOTIFY_RECIPIENT, $storeId));
    }

    public function getNotificationTemplate(?int $storeId = null): string
    {
        return (string) $this->value(self::XML_PATH_NOTIFY_TEMPLATE, $storeId)
            ?: 'magenx_product_feed_notifications_failure_template';
    }

    /**
     * Store-level facts an agentic-commerce feed repeats on every row, exposed to
     * templates as context.store.*.
     *
     * Kept in configuration rather than in the field map because they are facts
     * about the SELLER, identical across products and different per store view:
     * a merchant should state their returns policy once, not once per feed.
     *
     * Flags come out as the strings "true" / "false" / "" so a template can drop
     * them straight into a bool-typed column; the return window is only given
     * when returns are accepted, because the spec says to supply it only then.
     *
     * @return array<string, string>
     */
    public function getAgenticContext(?int $storeId = null): array
    {
        $get = fn (string $field): string => trim((string) $this->value(self::XML_PATH_AGENTIC . $field, $storeId));

        $accepts = $get('accepts_returns');
        $days = (int) $get('return_days');

        return [
            'name' => $get('seller_name') ?: trim((string) $this->value(self::XML_PATH_STORE_NAME, $storeId)),
            'brand' => $get('default_brand'),
            'url' => rtrim($get('storefront_url'), '/'),
            'privacy_policy' => $get('privacy_policy_url'),
            'terms' => $get('terms_url'),
            'return_policy' => $get('return_policy_url'),
            'accepts_returns' => match ($accepts) {
                AcceptsReturns::YES => 'true',
                AcceptsReturns::NO => 'false',
                default => '',
            },
            'return_days' => $accepts === AcceptsReturns::YES && $days > 0 ? (string) $days : '',
            'checkout' => $this->flag(self::XML_PATH_AGENTIC . 'checkout_enabled', $storeId) ? 'true' : 'false',
            'weight_unit' => match (strtolower(trim((string) $this->value(self::XML_PATH_WEIGHT_UNIT, $storeId)))) {
                'lbs', 'lb' => 'lb',
                'kgs', 'kg' => 'kg',
                default => '',
            },
        ];
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
