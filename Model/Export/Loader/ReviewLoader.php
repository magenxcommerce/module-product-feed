<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;

/**
 * Approved reviews and the rating summary for a whole batch.
 *
 * Two queries: the reviews themselves, and the aggregate summary.
 *
 * Only APPROVED reviews are exported. Pending and rejected review text must
 * never reach a marketplace - it has not been moderated, and on a product-review
 * feed it would be published verbatim under the merchant's name.
 *
 * Reviews are capped per product. A handful of products with thousands of
 * reviews would otherwise dominate both the memory of a batch and the size of
 * the file, and no review feed consumes an unbounded list.
 */
class ReviewLoader
{
    private const MAX_REVIEWS_PER_PRODUCT = 50;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> productId => ['reviews' => list, 'rating_summary' => string, ...]
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $out = $this->loadSummaries($scope);

        foreach ($this->loadReviews($scope) as $productId => $reviews) {
            $out[$productId]['reviews'] = $reviews;
        }

        return $out;
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private function loadReviews(LoadScope $scope): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(
                ['r' => $this->resource->getTableName('review')],
                ['review_id', 'entity_pk_value', 'created_at']
            )
            ->join(
                ['rd' => $this->resource->getTableName('review_detail')],
                'rd.review_id = r.review_id',
                ['title', 'detail', 'nickname']
            )
            ->join(
                ['rs' => $this->resource->getTableName('review_store')],
                'rs.review_id = r.review_id',
                []
            )
            ->where('r.entity_pk_value IN (?)', $scope->productIds)
            ->where('r.status_id = ?', Review::STATUS_APPROVED)
            ->where('rs.store_id IN (?)', [0, $scope->storeId])
            ->group('r.review_id')
            ->order('r.entity_pk_value')
            ->order('r.created_at DESC');

        $out = [];
        $counts = [];

        foreach ($connection->fetchAll($select) as $row) {
            $productId = (int) $row['entity_pk_value'];
            $counts[$productId] = ($counts[$productId] ?? 0) + 1;

            if ($counts[$productId] > self::MAX_REVIEWS_PER_PRODUCT) {
                continue;
            }

            $out[$productId][] = [
                'id' => (string) (int) $row['review_id'],
                'title' => (string) $row['title'],
                'detail' => (string) $row['detail'],
                'nickname' => (string) $row['nickname'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSummaries(LoadScope $scope): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(
                $this->resource->getTableName('review_entity_summary'),
                ['entity_pk_value', 'reviews_count', 'rating_summary']
            )
            ->where('entity_pk_value IN (?)', $scope->productIds)
            ->where('store_id = ?', $scope->storeId);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $percent = (float) $row['rating_summary'];

            $out[(int) $row['entity_pk_value']] = [
                'reviews_count' => (string) (int) $row['reviews_count'],
                // review_entity_summary stores a PERCENTAGE (0-100). Every shopping
                // feed wants a star value, so it is converted here rather than in a
                // template, where getting it wrong publishes "94" out of 5.
                'rating_summary' => number_format($percent / 20, 1, '.', ''),
                'rating_percent' => number_format($percent, 0, '.', ''),
                'reviews' => [],
            ];
        }

        return $out;
    }
}
