<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magenx\ProductFeed\Model\Delivery\DeliveryManager;
use Magenx\ProductFeed\Model\Export\RunResult;
use Magenx\ProductFeed\Model\Export\Runner;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Psr\Log\LoggerInterface;

/**
 * The single entry point for "generate this feed, then deliver it".
 *
 * Everything that can start a run - the cron dispatcher, the CLI, the admin
 * button - goes through here, so none of them can drift on the order of
 * operations or on when delivery is allowed to happen.
 *
 * THE RULE THAT MATTERS: delivery only ever follows a COMPLETED generation. A run
 * that used up its time budget has a valid but partial .part file and no
 * published file at all; delivering then would either send yesterday's file as
 * though it were fresh or, worse for a push destination, send half a catalog and
 * let the marketplace treat every absent product as delisted.
 */
class FeedManager
{
    public function __construct(
        private readonly Runner $runner,
        private readonly DeliveryManager $deliveryManager,
        private readonly FeedResource $feedResource,
        private readonly FeedFactory $feedFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Generate one feed and, if it completed, deliver it.
     */
    public function process(Feed $feed): RunResult
    {
        if (!$this->config->isEnabled($feed->getStoreId())) {
            return RunResult::skipped('Product feeds are disabled for this store view.');
        }

        // Push destinations are prepared before the run so they can be fed records
        // as products are exported, rather than after a file exists.
        $consumers = $this->deliveryManager->getRecordConsumers($feed);

        $result = $this->runner->run($feed, $consumers);

        if ($result->completed) {
            // Reload so the deliverers see last_filename and product_count as the
            // run just wrote them - the in-memory model predates those updates,
            // which are applied straight to the row to avoid re-saving the whole
            // feed inside the export loop.
            $this->deliveryManager->deliverAll($this->reload($feed));
        }

        return $result;
    }

    /**
     * Deliver whatever was last published, without regenerating.
     *
     * This is the retry path: a destination that was down when the feed was
     * generated should not force a full re-export of the catalog to try again.
     *
     * @return array<string, \Magenx\ProductFeed\Model\Delivery\DeliveryResult>
     */
    public function deliver(Feed $feed): array
    {
        if (!$this->config->isEnabled($feed->getStoreId())) {
            return [];
        }

        return $this->deliveryManager->deliverAll($feed);
    }

    private function reload(Feed $feed): Feed
    {
        $feedId = $feed->getFeedId();
        if ($feedId === null) {
            return $feed;
        }

        try {
            $fresh = $this->feedFactory->create();
            $this->feedResource->load($fresh, $feedId);

            return $fresh->getFeedId() === null ? $feed : $fresh;
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Magenx_ProductFeed: could not reload feed %d after generation: %s', $feedId, $e->getMessage())
            );

            return $feed;
        }
    }
}
