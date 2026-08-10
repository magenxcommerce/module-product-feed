<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Cron;

use Magenx\ProductFeed\Model\Config;
use Magenx\ProductFeed\Model\FeedHistory;
use Magenx\ProductFeed\Model\FeedManager;
use Magenx\ProductFeed\Model\Notifier;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory;
use Magenx\ProductFeed\Model\Schedule;
use Psr\Log\LoggerInterface;

/**
 * Ticks, generates whichever feeds are due, and prunes the run log.
 *
 * A DISPATCHER RATHER THAN A JOB PER FEED: a feed's schedule (days of the week x
 * times of day, in the store's timezone) lives on its own row and cannot be
 * expressed as a cron <config_path>, so this runs often and asks Schedule about
 * each active feed.
 *
 * Everything is caught and logged. One feed with a broken template must not take
 * down the cron group, and Magento retries a failed job - which for an export
 * that already published a file would mean doing the whole catalog again.
 */
class DispatchGeneration
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $feedCollectionFactory,
        private readonly Schedule $schedule,
        private readonly FeedManager $feedManager,
        private readonly FeedHistory $feedHistory,
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->dispatch();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Magenx_ProductFeed: generation dispatcher failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        try {
            $removed = $this->feedHistory->prune($this->config->getHistoryRetentionDays());
            if ($removed > 0) {
                $this->logger->info(sprintf('Magenx_ProductFeed: pruned %d history rows.', $removed));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Magenx_ProductFeed: history prune failed: ' . $e->getMessage());
        }
    }

    private function dispatch(): void
    {
        $collection = $this->feedCollectionFactory->create();
        $collection->addActiveFilter();

        foreach ($collection as $feed) {
            try {
                if (!$this->schedule->isDue($feed)) {
                    continue;
                }

                $result = $this->feedManager->process($feed);

                if ($result->failed) {
                    $this->logger->error(
                        sprintf('Magenx_ProductFeed: feed "%s" failed: %s', $feed->getCode(), $result->message)
                    );
                    $this->notifier->notifyFailure($feed, $result->message);
                } elseif ($result->completed) {
                    $this->logger->info(
                        sprintf(
                            'Magenx_ProductFeed: feed "%s" generated %d products in %d ms.',
                            $feed->getCode(),
                            $result->productCount,
                            $result->durationMs
                        )
                    );
                }
            } catch (\Throwable $e) {
                // Per feed, so the next one still runs.
                $this->logger->error(
                    sprintf('Magenx_ProductFeed: feed "%s" threw: %s', $feed->getCode(), $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }
}
