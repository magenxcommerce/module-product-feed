<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Preset;

use Magenx\ProductFeed\Model\Export\Writer\ValueTyper;
use Magenx\ProductFeed\Model\Feed;

/**
 * Starter mapping for the OpenAI product feed (Agentic Commerce Protocol).
 *
 * Spec: https://developers.openai.com/commerce/specs/file-upload/products
 *
 * Applied by the feed form when the channel is "AI / agentic shopping" and the
 * field map and template are both empty, so a merchant starts from a feed that
 * passes ingestion instead of a blank box. It is only a starting point: the map
 * and rules are ordinary feed data afterwards, edited like any other.
 *
 * Choices that are not obvious from the spec:
 *
 *  - item_id IS THE SKU of the purchasable row. The MagenX storefront's ACP
 *    checkout (/api/acp) adds exactly the item id it is given to a Magento cart
 *    by sku, so anything else breaks checkout. That is also why the preset turns
 *    on "purchasable only": a configurable parent's sku cannot be added.
 *
 *  - url points at the HEADLESS storefront when context.store.url is set
 *    (<storefront>/product/<url_key>, the MagenX Commerce route) and falls back
 *    to Magento's own product URL otherwise. Variants use the parent's page.
 *
 *  - brand is Magento's `manufacturer` attribute, which is what the storefront's
 *    brand pages read, then the parent's, then the configured fallback brand.
 *
 *  - Only attributes every Magento install has are referenced. The export aborts
 *    on an attribute code that does not exist, so gtin, mpn, condition, color
 *    and similar are left for the merchant to add against their own attributes.
 */
class AcpPreset
{
    /**
     * Money in the spec's "79.99 USD" form.
     */
    private const MONEY_SUFFIX = ' {{ context.currency }}';

    /**
     * @return array<int, array{column: string, value: string, type?: string}>
     */
    public function getFieldMap(): array
    {
        return [
            // ---- required ------------------------------------------------------
            $this->column('item_id', '{{ product.sku }}'),
            $this->column('title', '{{ product.name | stripHtml | truncate: 150 }}'),
            $this->column(
                'description',
                '{% if product.description %}{{ product.description | stripHtml | truncate: 5000 }}'
                . '{% elsif product.short_description %}{{ product.short_description | stripHtml | truncate: 5000 }}'
                . '{% else %}{{ product.parent.description | stripHtml | truncate: 5000 }}{% endif %}'
            ),
            $this->column(
                'url',
                '{% if context.store.url %}{{ context.store.url }}/product/{{ product.parent.url_key }}'
                . '{% else %}{{ product.parent.url }}{% endif %}'
            ),
            $this->column(
                'brand',
                '{% if product.manufacturer %}{{ product.manufacturer }}'
                . '{% elsif product.parent.manufacturer %}{{ product.parent.manufacturer }}'
                . '{% else %}{{ context.store.brand }}{% endif %}'
            ),
            $this->column('seller_name', '{{ context.store.name }}'),
            $this->column(
                'image_url',
                '{% if product.image %}{{ product.image }}{% else %}{{ product.parent.image }}{% endif %}'
            ),
            $this->column(
                'availability',
                '{% if product.is_in_stock == 1 %}in_stock{% else %}out_of_stock{% endif %}'
            ),
            $this->column('price', '{{ product.regular_price | price }}' . self::MONEY_SUFFIX),

            // ---- price ---------------------------------------------------------
            // Only when it is a real reduction: the spec ignores a sale price that
            // is not strictly below the regular one.
            $this->column(
                'sale_price',
                '{% if product.final_price < product.regular_price %}{% if product.final_price > 0 %}'
                . '{{ product.final_price | price }}' . self::MONEY_SUFFIX . '{% endif %}{% endif %}'
            ),

            // ---- variants ------------------------------------------------------
            $this->column('group_id', '{{ product.variant_group_id }}'),
            $this->column(
                'listing_has_variations',
                '{% if product.variant_group_id %}true{% endif %}',
                ValueTyper::TYPE_BOOL
            ),
            $this->column(
                'variant_dict',
                '{% if product.variant_group_id %}{{ product.variant_dict | json }}{% endif %}',
                ValueTyper::TYPE_JSON
            ),

            // ---- media ---------------------------------------------------------
            $this->column(
                'additional_image_urls',
                '{% if product.image %}{{ product.gallery | slice: 1 | json }}'
                . '{% else %}{{ product.parent.gallery | slice: 1 | json }}{% endif %}',
                ValueTyper::TYPE_LIST
            ),

            // ---- item information ---------------------------------------------
            $this->column('product_category', '{{ product.category.path }}'),
            $this->column(
                'weight',
                '{% if product.weight > 0 %}{{ product.weight | round: 3 }}{% endif %}'
            ),
            $this->column(
                'item_weight_unit',
                '{% if product.weight > 0 %}{{ context.store.weight_unit }}{% endif %}'
            ),

            // ---- reviews: the product page's aggregate, shared by its variants --
            $this->column('review_count', '{{ product.parent.reviews_count }}', ValueTyper::TYPE_INT),
            $this->column(
                'star_rating',
                '{% if product.parent.reviews_count > 0 %}{{ product.parent.rating_summary | round: 2 }}{% endif %}'
            ),

            // ---- seller, returns, checkout (Stores > Configuration) ------------
            $this->column('seller_url', '{{ context.store.url }}'),
            $this->column('return_policy', '{{ context.store.return_policy }}'),
            $this->column('accepts_returns', '{{ context.store.accepts_returns }}', ValueTyper::TYPE_BOOL),
            $this->column('return_deadline_in_days', '{{ context.store.return_days }}', ValueTyper::TYPE_INT),
            $this->column('is_eligible_checkout', '{{ context.store.checkout }}', ValueTyper::TYPE_BOOL),
            $this->column('seller_privacy_policy', '{{ context.store.privacy_policy }}'),
            $this->column('seller_tos', '{{ context.store.terms }}'),
        ];
    }

    /**
     * Rules mirroring what the spec says rejects a row (error) or degrades it
     * (warning).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getValidationRules(): array
    {
        $rules = [];

        foreach (['item_id', 'title', 'description', 'url', 'brand', 'seller_name', 'image_url',
                     'availability', 'price'] as $field) {
            $rules[] = $this->rule($field, 'required', 'error', sprintf('%s is required by the OpenAI feed.', $field));
        }

        return array_merge($rules, [
            $this->rule('item_id', 'unique', 'error', 'item_id must be unique within the feed.'),
            $this->rule('title', 'max_length', 'warning', 'Keep title within 150 characters.', ['length' => 150]),
            $this->rule(
                'description',
                'max_length',
                'warning',
                'Keep description within 5,000 characters.',
                ['length' => 5000]
            ),
            $this->rule('description', 'without_html', 'warning', 'description should be plain text.'),
            $this->rule('url', 'url', 'error', 'url must be an absolute http(s) URL.'),
            $this->rule('image_url', 'url', 'error', 'image_url must be an absolute http(s) URL.'),
            $this->rule(
                'availability',
                'is_one_of',
                'error',
                'availability must be in_stock, out_of_stock, pre_order, backorder or unknown.',
                ['values' => ['in_stock', 'out_of_stock', 'pre_order', 'backorder', 'unknown']]
            ),
            $this->rule('price', 'money', 'error', 'price must look like "79.99 USD" and be above zero.'),
            $this->rule('sale_price', 'money', 'warning', 'sale_price must look like "59.99 USD".'),
            $this->rule(
                'sale_price',
                'less_than_field',
                'warning',
                'sale_price must be below price, in the same currency, or it is ignored.',
                ['other' => 'price']
            ),
            $this->rule('gtin', 'gtin', 'warning', 'gtin must be 8, 12, 13 or 14 digits with a valid check digit.'),
            $this->rule(
                'group_id',
                'not_equal_field',
                'error',
                'group_id must differ from item_id.',
                ['other' => 'item_id']
            ),
            $this->rule(
                'variant_dict',
                'required_if',
                'warning',
                'A variant row (group_id set) should carry variant_dict.',
                ['other' => 'group_id']
            ),
            $this->rule(
                'variant_dict',
                'json_object',
                'error',
                'variant_dict must be an object of non-empty string values.'
            ),
            $this->rule(
                'variant_dict',
                'variant_group',
                'error',
                'Variants of one group must share option names and differ in their combination.',
                ['group_by' => 'group_id']
            ),
            $this->rule(
                'additional_image_urls',
                'regex',
                'warning',
                'additional_image_urls should only hold http(s) URLs.',
                ['pattern' => '^(\\[\\]$|\\[?"?https?://)']
            ),
            $this->rule(
                'weight',
                'decimal',
                'warning',
                'weight must be a positive decimal.',
                ['min' => 0.0001]
            ),
            $this->rule(
                'item_weight_unit',
                'required_if',
                'warning',
                'weight needs item_weight_unit (g, kg, oz or lb).',
                ['other' => 'weight']
            ),
            $this->rule(
                'item_weight_unit',
                'is_one_of',
                'warning',
                'item_weight_unit must be g, kg, oz or lb.',
                ['values' => ['g', 'kg', 'oz', 'lb']]
            ),
            $this->rule('review_count', 'integer', 'warning', 'review_count must be a whole number.', ['min' => 0]),
            $this->rule(
                'star_rating',
                'decimal',
                'warning',
                'star_rating must be 0-5 with at most two decimals.',
                ['min' => 0, 'max' => 5, 'decimals' => 2]
            ),
            $this->rule('seller_url', 'url', 'warning', 'seller_url must be an absolute http(s) URL.'),
            $this->rule('return_policy', 'url', 'warning', 'return_policy must be an absolute http(s) URL.'),
            $this->rule(
                'return_deadline_in_days',
                'only_if',
                'warning',
                'Supply return_deadline_in_days only with accepts_returns=true.',
                ['other' => 'accepts_returns', 'equals' => true]
            ),
            $this->rule(
                'return_deadline_in_days',
                'integer',
                'warning',
                'return_deadline_in_days must be a positive whole number.',
                ['min' => 1]
            ),
            $this->rule('accepts_returns', 'boolean', 'error', 'accepts_returns must be true or false.'),
            $this->rule('listing_has_variations', 'boolean', 'error', 'listing_has_variations must be true or false.'),
            $this->rule('is_eligible_checkout', 'boolean', 'error', 'is_eligible_checkout must be true or false.'),
            $this->rule(
                'seller_privacy_policy',
                'required_if',
                'error',
                'Checkout needs seller_privacy_policy (Stores > Configuration > Product Feeds > Agentic Commerce).',
                ['other' => 'is_eligible_checkout', 'equals' => true]
            ),
            $this->rule(
                'seller_tos',
                'required_if',
                'error',
                'Checkout needs seller_tos (Stores > Configuration > Product Feeds > Agentic Commerce).',
                ['other' => 'is_eligible_checkout', 'equals' => true]
            ),
            $this->rule('seller_privacy_policy', 'url', 'warning', 'seller_privacy_policy must be a URL.'),
            $this->rule('seller_tos', 'url', 'warning', 'seller_tos must be a URL.'),
        ]);
    }

    /**
     * Feed settings the OpenAI file upload expects: gzip-compressed JSONL, empty
     * values omitted, one row per purchasable sku.
     *
     * @return array<string, mixed>
     */
    public function getFeedDefaults(): array
    {
        return [
            'format' => Feed::FORMAT_JSONL,
            'omit_empty' => 1,
            'compression' => Feed::COMPRESSION_GZIP,
            'purchasable_only' => 1,
        ];
    }

    /**
     * @return array{column: string, value: string, type?: string}
     */
    private function column(string $column, string $value, string $type = ValueTyper::TYPE_STRING): array
    {
        $row = ['column' => $column, 'value' => $value];
        if ($type !== ValueTyper::TYPE_STRING) {
            $row['type'] = $type;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rule(string $field, string $type, string $severity, string $message, array $params = []): array
    {
        return ['field' => $field, 'type' => $type, 'severity' => $severity, 'message' => $message] + $params;
    }
}
