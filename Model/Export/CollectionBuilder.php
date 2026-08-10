<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\Template\Requirements;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Rule\Model\Condition\Sql\Builder as SqlBuilder;

/**
 * Builds the filtered, store-scoped product collection one page at a time.
 *
 * Two things here decide whether an export takes seconds or hours.
 *
 * 1. THE FILTER IS PUSHED INTO SQL. The feed's condition tree is attached with
 *    Magento\Rule\Model\Condition\Sql\Builder, which turns the whole tree into
 *    one WHERE clause. The alternative - loading every product and calling
 *    validate() on it - is how most feed extensions do it, and it is minutes per
 *    feed on a real catalog.
 *
 *    KNOWN COST, inherited from the builder: an attribute it cannot map to a SQL
 *    field is SKIPPED rather than reported. Inside an all/AND group that only
 *    over-selects (harmless - the product is exported when it should not have
 *    been). Inside an any/OR group it silently UNDER-selects. This is the reason
 *    Feed::getConditionsInstance() must keep returning the CatalogWidget combine:
 *    the CatalogRule flavour maps nothing at all.
 *
 * 2. PAGING IS BY ENTITY ID, NOT BY OFFSET. setPage()/LIMIT with a growing OFFSET
 *    makes MySQL walk and discard every preceding row, so the last page of a
 *    100k-product export costs a full scan. Seeking on entity_id > cursor is
 *    constant-cost per page and - the part that matters here - is stable when a
 *    product is added or deleted mid-run, which an OFFSET is not: a deletion
 *    shifts every later row up one and silently skips a product.
 */
class CollectionBuilder
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SqlBuilder $sqlBuilder
    ) {
    }

    /**
     * One page of products, in entity_id order, starting after $afterId.
     */
    public function createPage(Feed $feed, Requirements $requirements, int $afterId, int $pageSize): Collection
    {
        $collection = $this->createBase($feed, $requirements);

        $collection->getSelect()->where('e.entity_id > ?', $afterId);
        $collection->getSelect()->order('e.entity_id ASC');
        $collection->getSelect()->limit($pageSize);

        return $collection;
    }

    /**
     * How many products the feed's filter selects. Used for progress reporting and
     * for the admin preview, never inside the export loop.
     */
    public function countMatching(Feed $feed, Requirements $requirements): int
    {
        return $this->createBase($feed, $requirements)->getSize();
    }

    private function createBase(Feed $feed, Requirements $requirements): Collection
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();

        $collection->setStoreId($feed->getStoreId());
        $collection->addStoreFilter($feed->getStoreId());

        // sku and name are always selected: sku identifies the row in every log
        // and error message, and name is what makes a validation finding legible.
        $attributes = array_values(array_unique(array_merge(['sku', 'name'], $requirements->attributes)));
        $collection->addAttributeToSelect($attributes);

        if ($requirements->needsUrl) {
            // Preloads request paths for the whole page, so getProductUrl() does not
            // issue a rewrite lookup per product.
            $collection->addUrlRewrite();
        }

        $this->applyConditions($feed, $collection);

        return $collection;
    }

    private function applyConditions(Feed $feed, Collection $collection): void
    {
        $serialized = (string) $feed->getData('conditions_serialized');
        if (trim($serialized) === '' || $serialized === '[]') {
            return;
        }

        try {
            $conditions = $feed->getConditions();
            if ($conditions === null) {
                return;
            }

            $this->sqlBuilder->attachConditionToCollection($collection, $conditions);
        } catch (\Throwable $e) {
            // A filter that cannot be applied must not become a filter that is
            // silently ignored: exporting the WHOLE catalog to a marketplace because
            // a condition failed to parse is far worse than exporting nothing.
            throw new \RuntimeException(
                sprintf(
                    'Feed "%s": product filter could not be applied (%s). '
                    . 'Refusing to export an unfiltered catalog.',
                    $feed->getCode(),
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }
}
