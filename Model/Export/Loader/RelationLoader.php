<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Framework\App\ResourceConnection;

/**
 * Maps each product in the batch to its parent, for the {{ product.parent.* }}
 * pattern.
 *
 * WHY THIS MATTERS: a simple product that belongs to a configurable is usually
 * "Not Visible Individually", so it has no storefront URL of its own. Exporting
 * its own URL yields a 404 on the marketplace; exporting the parent's yields the
 * page a shopper can actually buy from. Google's item_group_id works the same
 * way - the group is the parent.
 *
 * ONE query for the whole batch. catalog_product_relation is the right table:
 * it covers configurable, grouped and bundle children alike, whereas
 * catalog_product_super_link covers only configurables.
 *
 * A child with several parents (legitimately possible - the same simple can sit
 * under two configurables) resolves to the lowest parent id, chosen only because
 * it is stable across runs. A feed that flip-flopped between two parents would
 * churn item_group_id on every export, which marketplaces treat as a new product
 * each time.
 */
class RelationLoader
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, int> childProductId => parentProductId
     */
    public function loadParentMap(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_relation'),
                ['child_id', 'parent_id']
            )
            ->where('child_id IN (?)', $scope->productIds)
            ->order('parent_id ASC');

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $childId = (int) $row['child_id'];
            if (!isset($out[$childId])) {
                $out[$childId] = (int) $row['parent_id'];
            }
        }

        return $out;
    }
}
