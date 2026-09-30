<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Acp;

use Magenx\ProductFeed\Model\Export\Writer\ValueTyper;

/**
 * Turns flat OpenAI-feed rows into the nested Product objects of the ACP
 * Products API (PATCH /product_feeds/{id}/products).
 *
 * Spec: https://developers.openai.com/commerce/specs/api/products
 *
 * The file feed and the API describe the same catalog in two shapes. The file
 * has one row per purchasable item with money as "79.99 USD"; the API has one
 * Product per listing holding its Variants, with money as integer MINOR units
 * ({"amount": 7999, "currency": "USD"}). Rows are grouped by `group_id` (a row
 * without one is a product with a single variant), and each row becomes one
 * variant. Building from the file feed's column names means one field map
 * serves both deliveries, and the file feed's validation rules check the data
 * that reaches the API too.
 *
 * Product-level title, url and description are only set when every variant
 * agrees on them: the API treats product-level values as applying across all
 * variants, and guessing one from the first row would be wrong for the rest.
 */
class ProductPayloadBuilder
{
    /** ISO 4217 currencies whose minor unit is not 1/100. Default exponent is 2. */
    private const CURRENCY_EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0,
        'XPF' => 0, 'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** Feed availability values a shopper can act on now or by ordering ahead. */
    private const PURCHASABLE_STATES = ['in_stock', 'backorder', 'pre_order'];

    /** The file feed spells it pre_order; the API's examples spell it preorder. */
    private const STATUS_MAP = [
        'in_stock' => 'in_stock',
        'out_of_stock' => 'out_of_stock',
        'backorder' => 'backorder',
        'pre_order' => 'preorder',
    ];

    /** File-feed condition values to the API's vocabulary. */
    private const CONDITION_MAP = [
        'new' => 'new',
        'used' => 'secondhand',
        'refurbished' => 'refurbished',
    ];

    /** Seller link types, keyed by the file-feed column that carries each URL. */
    private const SELLER_LINKS = [
        'seller_privacy_policy' => 'privacy_policy',
        'seller_tos' => 'terms_of_service',
        'return_policy' => 'refund_policy',
    ];

    public function __construct(
        private readonly ValueTyper $valueTyper
    ) {
    }

    /**
     * Key rows are grouped on. Prefixed, so an ungrouped item id can never merge
     * with a group that happens to share its value.
     *
     * @param array<string, mixed> $row
     */
    public function groupKey(array $row): string
    {
        $group = $this->text($row, 'group_id');

        return $group !== '' ? 'g:' . $group : 'i:' . $this->text($row, 'item_id');
    }

    /**
     * @param array<int, array<string, mixed>> $rows Every row of one group
     * @return array<string, mixed>
     */
    public function buildProduct(array $rows): array
    {
        $rows = array_values($rows);
        $first = $rows[0] ?? [];

        $group = $this->text($first, 'group_id');
        $product = ['id' => $group !== '' ? $group : $this->text($first, 'item_id')];

        $title = $this->shared($rows, 'title');
        if ($title !== '') {
            $product['title'] = $title;
        }

        $description = $this->shared($rows, 'description');
        if ($description !== '') {
            $product['description'] = ['plain' => $description];
        }

        $url = $this->shared($rows, 'url');
        if ($url !== '') {
            $product['url'] = $url;
        }

        $product['variants'] = array_map(fn (array $row): array => $this->buildVariant($row), $rows);

        return $product;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function buildVariant(array $row): array
    {
        $itemId = $this->text($row, 'item_id');
        $title = $this->text($row, 'title');

        $variant = [
            'id' => $itemId,
            // Required by the API; an untitled row is already a validation error
            // in the file feed, and the id is a better fallback than rejection
            // of the whole product and every sibling variant with it.
            'title' => $title !== '' ? $title : $itemId,
        ];

        $description = $this->text($row, 'description');
        if ($description !== '') {
            $variant['description'] = ['plain' => $description];
        }

        $url = $this->text($row, 'url');
        if ($url !== '') {
            $variant['url'] = $url;
        }

        $gtin = $this->text($row, 'gtin');
        if ($gtin !== '') {
            $variant['barcodes'] = [['type' => 'gtin', 'value' => $gtin]];
        }

        $variant += $this->buildPrices($row);

        $availability = $this->buildAvailability($this->text($row, 'availability'));
        if ($availability !== []) {
            $variant['availability'] = $availability;
        }

        $category = $this->text($row, 'product_category');
        if ($category !== '') {
            $variant['categories'] = [['value' => $category, 'taxonomy' => 'merchant']];
        }

        $condition = self::CONDITION_MAP[strtolower($this->text($row, 'condition'))] ?? null;
        if ($condition !== null) {
            $variant['condition'] = [$condition];
        }

        $options = $this->buildOptions($row['variant_dict'] ?? null);
        if ($options !== []) {
            $variant['variant_options'] = $options;
        }

        $media = $this->buildMedia($row);
        if ($media !== []) {
            $variant['media'] = $media;
        }

        $seller = $this->buildSeller($row);
        if ($seller !== []) {
            $variant['seller'] = $seller;
        }

        return $variant;
    }

    /**
     * "79.99 USD" to {"amount": 7999, "currency": "USD"}, or null when the value
     * is not money.
     *
     * @return array{amount: int, currency: string}|null
     */
    public function toPrice(string $money): ?array
    {
        if (preg_match('/^(\d+)(?:\.(\d+))? ([A-Z]{3})$/', trim($money), $match) !== 1) {
            return null;
        }

        $currency = $match[3];
        $exponent = self::CURRENCY_EXPONENTS[$currency] ?? 2;

        // String arithmetic, not float: 0.29 * 100 is 28.999999999999996 in a
        // double, and truncating that bills a customer a cent short.
        $fraction = substr(str_pad($match[2] ?? '', $exponent, '0'), 0, $exponent);
        $rest = substr($match[2] ?? '', $exponent);
        $amount = (int) ltrim($match[1] . $fraction, '0');

        // More fraction digits than the currency has: round half up on the first
        // dropped digit rather than silently truncating.
        if ($rest !== '' && (int) $rest[0] >= 5) {
            $amount++;
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    /**
     * price is the ACTIVE price in the API and list_price the reference price.
     * The file feed's `price` is the regular price and `sale_price` the reduced
     * one, valid only when strictly lower in the same currency - the same rule
     * the file feed applies.
     *
     * @param array<string, mixed> $row
     * @return array<string, array{amount: int, currency: string}>
     */
    private function buildPrices(array $row): array
    {
        $regular = $this->toPrice($this->text($row, 'price'));
        if ($regular === null) {
            return [];
        }

        $sale = $this->toPrice($this->text($row, 'sale_price'));
        if ($sale !== null
            && $sale['currency'] === $regular['currency']
            && $sale['amount'] > 0
            && $sale['amount'] < $regular['amount']
        ) {
            return ['price' => $sale, 'list_price' => $regular];
        }

        return ['price' => $regular];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAvailability(string $state): array
    {
        $state = strtolower($state);
        if ($state === '' || $state === 'unknown') {
            return [];
        }

        $availability = ['available' => in_array($state, self::PURCHASABLE_STATES, true)];
        if (isset(self::STATUS_MAP[$state])) {
            $availability['status'] = self::STATUS_MAP[$state];
        }

        return $availability;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function buildOptions(mixed $dict): array
    {
        if (is_string($dict)) {
            $dict = json_decode($dict, true);
        } elseif ($dict instanceof \stdClass) {
            $dict = (array) $dict;
        }

        if (!is_array($dict)) {
            return [];
        }

        $options = [];
        foreach ($dict as $name => $value) {
            $name = trim((string) $name);
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($name !== '' && $value !== '') {
                $options[] = ['name' => $name, 'value' => $value];
            }
        }

        return $options;
    }

    /**
     * Main image first - the API treats the first entry as primary.
     *
     * @param array<string, mixed> $row
     * @return array<int, array{type: string, url: string}>
     */
    private function buildMedia(array $row): array
    {
        $urls = [];

        $main = $this->text($row, 'image_url');
        if ($main !== '') {
            $urls[] = $main;
        }

        $additional = $this->valueTyper->convert($row['additional_image_urls'] ?? '', ValueTyper::TYPE_LIST);
        if (is_array($additional)) {
            foreach ($additional as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $urls[] = trim($url);
                }
            }
        }

        return array_map(
            static fn (string $url): array => ['type' => 'image', 'url' => $url],
            array_values(array_unique($urls))
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildSeller(array $row): array
    {
        $seller = [];

        $name = $this->text($row, 'seller_name');
        if ($name !== '') {
            $seller['name'] = $name;
        }

        $links = [];
        foreach (self::SELLER_LINKS as $column => $type) {
            $url = $this->text($row, $column);
            if ($url !== '') {
                $links[] = ['type' => $type, 'url' => $url];
            }
        }
        if ($links !== []) {
            $seller['links'] = $links;
        }

        return $seller;
    }

    /**
     * The value every row agrees on, or '' when they differ or it is missing.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function shared(array $rows, string $column): string
    {
        $values = array_unique(array_map(fn (array $row): string => $this->text($row, $column), $rows));

        return count($values) === 1 ? (string) reset($values) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
