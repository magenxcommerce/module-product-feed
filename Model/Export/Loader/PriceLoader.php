<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Framework\App\ResourceConnection;

/**
 * Prices for a whole batch, from the price index.
 *
 * ONE query per batch. The index is read directly rather than through
 * $product->getFinalPrice(), which triggers a price-model load and a catalog-rule
 * evaluation per product - the single most expensive thing an export can do
 * per-row.
 *
 * min_price / max_price matter for composite products: a configurable or bundle
 * has final_price = 0 in this table, and min_price is the "from" price the
 * storefront shows. A feed that exports final_price blindly publishes 0.00 for
 * every configurable, which a marketplace either rejects or - worse - accepts.
 */
class PriceLoader
{
    private const TABLE = 'catalog_product_index_price';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> productId => price fields
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['entity_id', 'price', 'final_price', 'min_price', 'max_price', 'tier_price']
            )
            ->where('entity_id IN (?)', $scope->productIds)
            ->where('customer_group_id = ?', $scope->customerGroupId)
            ->where('website_id = ?', $scope->websiteId);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $regular = (float) $row['price'];
            $final = (float) $row['final_price'];
            $min = (float) $row['min_price'];

            // A composite parent indexes final_price = 0; its advertised price is
            // min_price, the cheapest child's final price.
            $effective = $final > 0 ? $final : $min;

            $out[(int) $row['entity_id']] = [
                'regular_price' => $this->decimal($regular > 0 ? $regular : $min),
                'final_price' => $this->decimal($effective),
                'min_price' => $this->decimal($min),
                'max_price' => $this->decimal((float) $row['max_price']),
                'tier_price' => $row['tier_price'] === null ? '' : $this->decimal((float) $row['tier_price']),
            ];
        }

        return $out;
    }

    /**
     * Machine format: dot separator, no thousands grouping. "1,299.00" is read by
     * a marketplace as either malformed or as 1.299.
     */
    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
