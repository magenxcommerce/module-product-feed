<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Framework\App\ResourceConnection;

/**
 * Quantity and stock status for a whole batch.
 *
 * ONE query, against the legacy cataloginventory_stock_item table.
 *
 * That choice is deliberate. Magento's MSI stack keeps this table in sync for
 * the default stock, and it is the only source that is present and correct on
 * every install regardless of whether MSI is enabled, whether custom sources
 * exist, or which stock is assigned to the feed's website. The per-source view
 * (inventory_stock_<id>) is not queried here because a feed's "is this in stock"
 * question is about the website's aggregate, and resolving that per website
 * costs a stock-resolution call this loader exists to avoid.
 *
 * A merchant who needs per-source quantities gets them from the explicit
 * {{ product.inventory:<source_code> }} pattern, which InventorySourceLoader
 * serves from inventory_source_item.
 */
class StockLoader
{
    private const TABLE = 'cataloginventory_stock_item';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> productId => stock fields
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
                ['product_id', 'qty', 'is_in_stock']
            )
            ->where('product_id IN (?)', $scope->productIds);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $inStock = (int) $row['is_in_stock'] === 1;

            $out[(int) $row['product_id']] = [
                'qty' => number_format((float) $row['qty'], 2, '.', ''),
                'is_in_stock' => $inStock ? '1' : '0',
                // The human-readable form most shopping feeds want verbatim.
                'stock_status' => $inStock ? 'in stock' : 'out of stock',
            ];
        }

        return $out;
    }
}
