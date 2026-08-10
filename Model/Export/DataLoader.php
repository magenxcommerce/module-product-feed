<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Export\Loader\CategoryLoader;
use Magenx\ProductFeed\Model\Export\Loader\GalleryLoader;
use Magenx\ProductFeed\Model\Export\Loader\InventorySourceLoader;
use Magenx\ProductFeed\Model\Export\Loader\LoadScope;
use Magenx\ProductFeed\Model\Export\Loader\PriceLoader;
use Magenx\ProductFeed\Model\Export\Loader\RelationLoader;
use Magenx\ProductFeed\Model\Export\Loader\ReviewLoader;
use Magenx\ProductFeed\Model\Export\Loader\StockLoader;
use Magenx\ProductFeed\Model\Export\Loader\TierPriceLoader;
use Magenx\ProductFeed\Model\Template\Requirements;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Turns a page of products into the plain nested arrays a template renders
 * against.
 *
 * THE CONTRACT THAT KEEPS THIS FAST: every loader below runs at most ONCE per
 * batch, and only when the template actually referenced its data. A feed of
 * sku/name/price therefore costs the collection query plus one price query - not
 * the nine a load-everything export would pay - and adding a review loop to the
 * template is what turns the review query on, nothing else.
 *
 * THE CONTRACT THAT KEEPS THIS SAFE: the records produced here are nested arrays
 * of scalars. No Magento model reaches the render context, which is what makes
 * "a template cannot call a method" true rather than merely intended.
 */
class DataLoader
{
    public function __construct(
        private readonly PriceLoader $priceLoader,
        private readonly StockLoader $stockLoader,
        private readonly CategoryLoader $categoryLoader,
        private readonly GalleryLoader $galleryLoader,
        private readonly RelationLoader $relationLoader,
        private readonly ReviewLoader $reviewLoader,
        private readonly TierPriceLoader $tierPriceLoader,
        private readonly InventorySourceLoader $inventorySourceLoader,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param Product[] $products
     * @return array<int, array<string, mixed>> productId => record
     */
    public function buildRecords(array $products, Requirements $requirements, LoadScope $scope): array
    {
        if ($products === []) {
            return [];
        }

        $records = [];
        foreach ($products as $product) {
            $records[(int) $product->getId()] = $this->baseRecord($product, $requirements);
        }

        $this->mergeIf($records, $requirements->needsPrices, fn (): array => $this->priceLoader->load($scope));
        $this->mergeIf($records, $requirements->needsStock, fn (): array => $this->stockLoader->load($scope));
        $this->mergeIf($records, $requirements->needsCategories, fn (): array => $this->categoryLoader->load($scope));
        $this->mergeIf($records, $requirements->needsGallery, fn (): array => $this->galleryLoader->load($scope));
        $this->mergeIf($records, $requirements->needsReviews, fn (): array => $this->reviewLoader->load($scope));
        $this->mergeIf(
            $records,
            $requirements->needsInventorySources,
            fn (): array => $this->inventorySourceLoader->load($scope)
        );

        if ($requirements->needsTierPrices) {
            foreach ($this->tierPriceLoader->load($scope) as $productId => $rows) {
                if (isset($records[$productId])) {
                    $records[$productId]['tier_prices'] = $rows;
                }
            }
        }

        if ($requirements->needsParent) {
            $this->attachParents($records, $requirements, $scope);
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param callable(): array<int, array<string, mixed>> $load
     */
    private function mergeIf(array &$records, bool $needed, callable $load): void
    {
        if (!$needed) {
            return;
        }

        foreach ($load() as $productId => $data) {
            if (isset($records[$productId])) {
                $records[$productId] = array_merge($records[$productId], $data);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecord(Product $product, Requirements $requirements): array
    {
        $record = [
            'entity_id' => (string) (int) $product->getId(),
            'sku' => (string) $product->getSku(),
            'type_id' => (string) $product->getTypeId(),
            'attribute_set_id' => (string) (int) $product->getAttributeSetId(),
            // Sensible empties so a template referencing a group its feed does not
            // load renders blank rather than the literal path.
            'gallery' => [],
            'images' => '',
            'tier_prices' => [],
            'reviews' => [],
            'inventory' => [],
            'source_items' => [],
        ];

        foreach ($requirements->attributes as $code) {
            $record[$code] = $this->attributeValue($product, $code);
        }

        if ($requirements->needsUrl) {
            $record['url'] = (string) $product->getProductUrl();
        }

        return $record;
    }

    /**
     * Resolve an attribute to its display value.
     *
     * getAttributeText() is used where a source model exists, so a select or
     * multiselect exports its LABEL rather than its option id - a feed full of
     * "217" instead of "Blue" is one of the most common and most silently damaging
     * feed defects. A multiselect returns an array, which is kept as an array so a
     * template can join it however the target wants; stringifying it here would
     * produce the literal word "Array".
     */
    private function attributeValue(Product $product, string $code): mixed
    {
        try {
            $attribute = $product->getResource()->getAttribute($code);
        } catch (\Throwable) {
            $attribute = null;
        }

        if ($attribute !== null && $attribute->usesSource()) {
            try {
                $text = $product->getAttributeText($code);
                if ($text !== false && $text !== null && $text !== '') {
                    return is_array($text) ? array_map('strval', $text) : (string) $text;
                }
            } catch (\Throwable) {
                // Fall through to the raw value below.
            }
        }

        $value = $product->getData($code);

        if (is_array($value)) {
            return array_map('strval', $value);
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Attach each child's parent record under `parent`.
     *
     * ONE extra collection query for the whole batch, loading the same attributes
     * the children use so {{ product.parent.<anything the template asks for> }}
     * resolves. Loading parents per child - the obvious implementation - is one
     * query per product and the single easiest way to make an export unusable.
     *
     * @param array<int, array<string, mixed>> $records
     */
    private function attachParents(array &$records, Requirements $requirements, LoadScope $scope): void
    {
        $parentMap = $this->relationLoader->loadParentMap($scope);
        if ($parentMap === []) {
            return;
        }

        $parentIds = array_values(array_unique(array_values($parentMap)));

        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($scope->storeId);
        $collection->addAttributeToSelect(
            array_values(array_unique(array_merge(['sku', 'name'], $requirements->attributes)))
        );
        $collection->addFieldToFilter('entity_id', ['in' => $parentIds]);

        if ($requirements->needsUrl) {
            $collection->addUrlRewrite();
        }

        $parentRecords = [];
        foreach ($collection as $parent) {
            $parentRecords[(int) $parent->getId()] = $this->baseRecord($parent, $requirements);
        }

        // Parents get their own prices: a child's price is not the parent's, and a
        // feed keyed on the parent URL usually wants the parent's "from" price.
        if ($requirements->needsPrices && $parentIds !== []) {
            $parentScope = new LoadScope(
                $parentIds,
                [],
                $scope->storeId,
                $scope->websiteId,
                $scope->mediaBaseUrl,
                $scope->customerGroupId
            );
            foreach ($this->priceLoader->load($parentScope) as $parentId => $prices) {
                if (isset($parentRecords[$parentId])) {
                    $parentRecords[$parentId] = array_merge($parentRecords[$parentId], $prices);
                }
            }
        }

        foreach ($parentMap as $childId => $parentId) {
            if (isset($records[$childId], $parentRecords[$parentId])) {
                $records[$childId]['parent'] = $parentRecords[$parentId];
            }
        }
    }
}
