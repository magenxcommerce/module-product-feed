<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Category assignments and the deepest category path per product.
 *
 * Two queries per batch, not one per product:
 *   1. every (product, category) assignment for the batch
 *   2. every distinct category in those assignments, with its name and path
 *
 * WHICH CATEGORY IS "THE" CATEGORY: a product is usually in several. The rule
 * here matches what shopping feeds expect - the DEEPEST category wins, and among
 * equally deep ones the product's own position breaks the tie. Exporting an
 * arbitrary one instead puts products under "Root Catalog" on the marketplace,
 * which is a common and hard-to-spot cause of poor placement.
 */
class CategoryLoader
{
    private const SEPARATOR = ' > ';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> productId => category fields
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $assignments = $this->loadAssignments($scope);
        if ($assignments === []) {
            return [];
        }

        $categoryIds = [];
        foreach ($assignments as $rows) {
            foreach ($rows as $row) {
                $categoryIds[$row['category_id']] = true;
            }
        }

        $categories = $this->loadCategories(array_keys($categoryIds), $scope->storeId);

        $out = [];
        foreach ($assignments as $productId => $rows) {
            $best = null;
            $names = [];
            $ids = [];
            $paths = [];

            foreach ($rows as $row) {
                $categoryId = $row['category_id'];
                $category = $categories[$categoryId] ?? null;
                if ($category === null) {
                    continue;
                }

                $ids[] = $categoryId;
                $names[] = $category['name'];
                $paths[] = $category['path_names'];

                if ($best === null
                    || $category['depth'] > $best['depth']
                    || ($category['depth'] === $best['depth'] && $row['position'] < $best['position'])
                ) {
                    $best = $category + ['position' => $row['position']];
                }
            }

            if ($best === null) {
                continue;
            }

            $out[$productId] = [
                'category' => [
                    'entity_id' => (string) $best['entity_id'],
                    'name' => $best['name'],
                    'path' => $best['path_names'],
                ],
                'category_ids' => implode(',', $ids),
                'categories' => $names,
                'category_paths' => $paths,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<int, array{category_id: int, position: int}>>
     */
    private function loadAssignments(LoadScope $scope): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_category_product'),
                ['product_id', 'category_id', 'position']
            )
            ->where('product_id IN (?)', $scope->productIds);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['product_id']][] = [
                'category_id' => (int) $row['category_id'],
                'position' => (int) $row['position'],
            ];
        }

        return $out;
    }

    /**
     * Names and depths for every category referenced by the batch.
     *
     * Store-scoped names are resolved with a LEFT JOIN onto the store-view row and
     * a fallback to the default-scope row - the usual EAV pattern. Without the
     * fallback, a category never overridden at store-view level exports as empty.
     *
     * @param int[] $categoryIds
     * @return array<int, array{entity_id: int, name: string, depth: int, path_names: string}>
     */
    private function loadCategories(array $categoryIds, int $storeId): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $entityTable = $this->resource->getTableName('catalog_category_entity');
        $varcharTable = $this->resource->getTableName('catalog_category_entity_varchar');

        try {
            $nameAttributeId = (int) $this->eavConfig
                ->getAttribute(\Magento\Catalog\Model\Category::ENTITY, 'name')
                ->getAttributeId();
        } catch (\Throwable) {
            return [];
        }

        // Every ancestor is needed too, so the path can be rendered as names.
        $allIds = $this->expandWithAncestors($categoryIds);

        $select = $connection->select()
            ->from(['e' => $entityTable], ['entity_id', 'path', 'level'])
            ->joinLeft(
                ['vd' => $varcharTable],
                'vd.entity_id = e.entity_id AND vd.attribute_id = ' . $nameAttributeId . ' AND vd.store_id = 0',
                []
            )
            ->joinLeft(
                ['vs' => $varcharTable],
                'vs.entity_id = e.entity_id AND vs.attribute_id = ' . $nameAttributeId
                . ' AND vs.store_id = ' . $storeId,
                []
            )
            ->columns(['name' => new \Zend_Db_Expr('IFNULL(vs.value, vd.value)')])
            ->where('e.entity_id IN (?)', $allIds);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int) $row['entity_id']] = [
                'entity_id' => (int) $row['entity_id'],
                'name' => (string) ($row['name'] ?? ''),
                'level' => (int) $row['level'],
                'path' => (string) $row['path'],
            ];
        }

        $out = [];
        foreach ($categoryIds as $categoryId) {
            if (!isset($rows[$categoryId])) {
                continue;
            }
            $row = $rows[$categoryId];

            $out[$categoryId] = [
                'entity_id' => $categoryId,
                'name' => $row['name'],
                'depth' => $row['level'],
                'path_names' => $this->buildPathNames($row['path'], $rows),
            ];
        }

        return $out;
    }

    /**
     * The two synthetic roots (1 = root, 2 = default category) are dropped from
     * the rendered path: no marketplace wants "Root Catalog > Default Category >
     * Shoes".
     *
     * @param array<int, array{name: string, level: int, path: string}> $rows
     */
    private function buildPathNames(string $path, array $rows): string
    {
        $names = [];
        foreach (explode('/', $path) as $segment) {
            $id = (int) $segment;
            if ($id <= 2 || !isset($rows[$id])) {
                continue;
            }
            $name = $rows[$id]['name'];
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(self::SEPARATOR, $names);
    }

    /**
     * Ancestor ids are not known until the paths are read, so this is a cheap
     * over-fetch: ask for the batch's categories, then for their ancestors in the
     * same IN list on the next pass. Categories are few and heavily cached, so a
     * second round trip here is not worth avoiding with a recursive CTE.
     *
     * @param int[] $categoryIds
     * @return int[]
     */
    private function expandWithAncestors(array $categoryIds): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_category_entity'), ['path'])
            ->where('entity_id IN (?)', $categoryIds);

        $ids = $categoryIds;
        foreach ($connection->fetchCol($select) as $path) {
            foreach (explode('/', (string) $path) as $segment) {
                $ids[] = (int) $segment;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
