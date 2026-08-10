<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magenx\ProductFeed\Model\ResourceModel\History as HistoryResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Writes and prunes the run log.
 *
 * Recording history must never be able to fail a run. A feed that generated
 * correctly and then threw while writing its own audit row would be reported as
 * failed, the work file would be discarded, and the next tick would start over -
 * so every write here is wrapped and logged instead.
 */
class FeedHistory
{
    public const TYPE_GENERATE = 'generate';
    public const TYPE_DELIVER = 'deliver';
    public const TYPE_VALIDATE = 'validate';

    public function __construct(
        private readonly HistoryFactory $historyFactory,
        private readonly HistoryResource $historyResource,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(
        int $feedId,
        string $type,
        string $status,
        string $message,
        ?int $productCount = null,
        ?int $durationMs = null,
        array $details = []
    ): void {
        try {
            $history = $this->historyFactory->create();
            $history->setData([
                'feed_id' => $feedId,
                'type' => $type,
                'status' => $status,
                // A stack trace or an upstream API body can be arbitrarily long, and
                // `message` is a TEXT column read in a grid.
                'message' => $this->truncate($message, 2000),
                'product_count' => $productCount,
                'duration_ms' => $durationMs,
                'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
                'created_at' => $this->dateTime->gmtDate(),
            ]);

            $this->historyResource->save($history);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Magenx_ProductFeed: could not write history for feed %d: %s', $feedId, $e->getMessage())
            );
        }
    }

    /**
     * Drop rows older than the retention window.
     *
     * @return int rows removed
     */
    public function prune(int $retentionDays): int
    {
        try {
            $connection = $this->resource->getConnection();
            $cutoff = $this->dateTime->gmtDate(null, strtotime(sprintf('-%d days', max(1, $retentionDays))));

            return (int) $connection->delete(
                $this->resource->getTableName('magenx_feed_history'),
                ['created_at < ?' => $cutoff]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Magenx_ProductFeed: history prune failed: ' . $e->getMessage());

            return 0;
        }
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 3) . '...';
    }
}
