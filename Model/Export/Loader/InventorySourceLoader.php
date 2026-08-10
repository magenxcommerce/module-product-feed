<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Per-source quantities (MSI), for {{ product.inventory:<source_code> }} and
 * {% for source in product.source_items %}.
 *
 * ONE query, keyed on SKU because that is what inventory_source_item stores -
 * MSI deliberately references products by sku rather than entity id.
 *
 * FAILS SOFT AND SILENTLY-ISH: MSI is a bundled but removable set of modules,
 * and inventory_source_item simply does not exist on a store where it has been
 * disabled. A missing table there must degrade to "no per-source data", not
 * abort every feed on the install - so the query is guarded and logged once.
 */
class InventorySourceLoader
{
    private const TABLE = 'inventory_source_item';

    private bool $tableChecked = false;
    private bool $tableExists = false;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> productId => ['inventory' => map, 'source_items' => list]
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty() || !$this->hasTable()) {
            return [];
        }

        $skus = array_values(array_filter($scope->skusById));
        if ($skus === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['source_code', 'sku', 'quantity', 'status']
            )
            ->where('sku IN (?)', $skus);

        $bySku = [];
        foreach ($connection->fetchAll($select) as $row) {
            $bySku[(string) $row['sku']][] = [
                'code' => (string) $row['source_code'],
                'quantity' => number_format((float) $row['quantity'], 2, '.', ''),
                'status' => (int) $row['status'] === 1 ? '1' : '0',
            ];
        }

        $out = [];
        foreach ($scope->skusById as $productId => $sku) {
            $rows = $bySku[$sku] ?? null;
            if ($rows === null) {
                continue;
            }

            $map = [];
            foreach ($rows as $row) {
                $map[$row['code']] = $row['quantity'];
            }

            $out[$productId] = [
                'inventory' => $map,
                'source_items' => $rows,
            ];
        }

        return $out;
    }

    private function hasTable(): bool
    {
        if ($this->tableChecked) {
            return $this->tableExists;
        }

        $this->tableChecked = true;

        try {
            $this->tableExists = $this->resource->getConnection()
                ->isTableExists($this->resource->getTableName(self::TABLE));
        } catch (\Throwable $e) {
            $this->tableExists = false;
        }

        if (!$this->tableExists) {
            $this->logger->info(
                'Magenx_ProductFeed: inventory_source_item is not present (MSI disabled); '
                . 'per-source quantities will export empty.'
            );
        }

        return $this->tableExists;
    }
}
