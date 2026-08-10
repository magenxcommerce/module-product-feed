<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Framework\App\ResourceConnection;

/**
 * Tier prices for a whole batch, for {% for tier_price in product.tier_prices %}.
 *
 * ONE query. Rows are filtered to the batch's website (or the "all websites" row
 * 0) so a multi-website store does not export another site's pricing.
 */
class TierPriceLoader
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array<int, array<string, string>>> productId => tier rows
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_entity_tier_price'),
                ['entity_id', 'all_groups', 'customer_group_id', 'qty', 'value', 'percentage_value', 'website_id']
            )
            ->where('entity_id IN (?)', $scope->productIds)
            ->where('website_id IN (?)', [0, $scope->websiteId])
            ->order('entity_id')
            ->order('qty ASC');

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $isPercent = $row['percentage_value'] !== null;

            $out[(int) $row['entity_id']][] = [
                // A percentage tier has no absolute amount stored; exporting the
                // percentage in a `price` field would publish "10" as a price.
                'price' => $isPercent ? '' : number_format((float) $row['value'], 2, '.', ''),
                'percentage' => $isPercent ? number_format((float) $row['percentage_value'], 2, '.', '') : '',
                'price_type' => $isPercent ? 'percent' : 'fixed',
                'quantity' => number_format((float) $row['qty'], 2, '.', ''),
                'customer_group' => (int) $row['all_groups'] === 1 ? 'all' : (string) (int) $row['customer_group_id'],
                'website_id' => (string) (int) $row['website_id'],
            ];
        }

        return $out;
    }
}
