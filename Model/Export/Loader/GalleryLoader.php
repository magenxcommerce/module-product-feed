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
 * Gallery image URLs for a whole batch.
 *
 * ONE query. The alternative - $product->getMediaGalleryImages() - lazily loads a
 * gallery per product, which on a 24-item page is 24 round trips and on a
 * 50k-product export is 50,000.
 *
 * Disabled images are excluded: an image hidden on the storefront but published
 * in the feed is a support ticket, and on Google it is a policy violation when
 * the image no longer matches the listing.
 */
class GalleryLoader
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> productId => ['gallery' => string[], 'images' => string, 'image' => string]
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        try {
            $attributeId = (int) $this->eavConfig
                ->getAttribute(\Magento\Catalog\Model\Product::ENTITY, 'media_gallery')
                ->getAttributeId();
        } catch (\Throwable) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(
                ['g' => $this->resource->getTableName('catalog_product_entity_media_gallery')],
                ['value']
            )
            ->join(
                ['gte' => $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity')],
                'gte.value_id = g.value_id',
                ['entity_id']
            )
            // Store-view row first, default row as the fallback: an image whose
            // position or disabled flag was never overridden per store view has no
            // store-view row at all, and an inner join would silently drop it.
            ->joinLeft(
                ['gvs' => $this->resource->getTableName('catalog_product_entity_media_gallery_value')],
                'gvs.value_id = g.value_id AND gvs.entity_id = gte.entity_id AND gvs.store_id = ' . $scope->storeId,
                []
            )
            ->joinLeft(
                ['gvd' => $this->resource->getTableName('catalog_product_entity_media_gallery_value')],
                'gvd.value_id = g.value_id AND gvd.entity_id = gte.entity_id AND gvd.store_id = 0',
                []
            )
            ->columns([
                'position' => new \Zend_Db_Expr('IFNULL(gvs.position, gvd.position)'),
                'disabled' => new \Zend_Db_Expr('IFNULL(gvs.disabled, gvd.disabled)'),
            ])
            ->where('g.attribute_id = ?', $attributeId)
            ->where('gte.entity_id IN (?)', $scope->productIds)
            ->where('IFNULL(gvs.disabled, IFNULL(gvd.disabled, 0)) = 0')
            ->order('gte.entity_id')
            ->order(new \Zend_Db_Expr('IFNULL(gvs.position, IFNULL(gvd.position, 0)) ASC'));

        $base = rtrim($scope->mediaBaseUrl, '/') . '/catalog/product';

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $file = (string) $row['value'];
            if ($file === '') {
                continue;
            }
            $out[(int) $row['entity_id']][] = $base . '/' . ltrim($file, '/');
        }

        $result = [];
        foreach ($out as $productId => $urls) {
            $urls = array_values(array_unique($urls));
            $result[$productId] = [
                'gallery' => $urls,
                'images' => implode(',', $urls),
                // `image` is a synthetic field (Parser never selects it as an
                // attribute), so it has to be filled here or it always renders
                // empty. First enabled image by position.
                'image' => $urls[0] ?? '',
            ];
        }

        return $result;
    }
}
