<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Cron;

use Magenx\ProductFeed\Model\Config;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\FeedManager;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory as DeliveryCollectionFactory;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory as FeedCollectionFactory;
use Magenx\ProductFeed\Model\Delivery\DeliveryResult;
use Psr\Log\LoggerInterface;

/**
 * Retries destinations whose last attempt failed.
 *
 * Separate from generation on purpose: a marketplace API being down is not a
 * reason to re-export the catalog, and re-exporting is by far the expensive half.
 * Only feeds that are READY - meaning a complete file exists - are retried.
 *
 * PUSH DESTINATIONS ARE NOT RETRIED HERE. Their data goes out during the export
 * and there is no file to resend, so a failed push is corrected by the next
 * generation. Retrying one from here would report success while sending nothing.
 */
class RetryDelivery
{
    public function __construct(
        private readonly Config $config,
        private readonly FeedCollectionFactory $feedCollectionFactory,
        private readonly DeliveryCollectionFactory $deliveryCollectionFactory,
        private readonly FeedManager $feedManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            foreach ($this->findFeedsNeedingRetry() as $feed) {
                try {
                    $results = $this->feedManager->deliver($feed);

                    foreach ($results as $type => $result) {
                        if ($result->status === DeliveryResult::STATUS_ERROR) {
                            $this->logger->warning(
                                sprintf(
                                    'Magenx_ProductFeed: retry of "%s" for feed "%s" failed again: %s',
                                    $type,
                                    $feed->getCode(),
                                    $result->message
                                )
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        sprintf(
                            'Magenx_ProductFeed: delivery retry for feed "%s" threw: %s',
                            $feed->getCode(),
                            $e->getMessage()
                        )
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Magenx_ProductFeed: delivery retry cron failed: ' . $e->getMessage());
        }
    }

    /**
     * @return Feed[]
     */
    private function findFeedsNeedingRetry(): array
    {
        $deliveries = $this->deliveryCollectionFactory->create();
        $deliveries->addActiveFilter();
        $deliveries->addFieldToFilter('last_status', DeliveryResult::STATUS_ERROR);

        $feedIds = [];
        foreach ($deliveries as $delivery) {
            $feedIds[(int) $delivery->getData('feed_id')] = true;
        }

        if ($feedIds === []) {
            return [];
        }

        $feeds = $this->feedCollectionFactory->create();
        $feeds->addActiveFilter();
        $feeds->addFieldToFilter('feed_id', ['in' => array_keys($feedIds)]);
        // Only feeds with a complete published file. A feed still mid-run, or one
        // that has never finished, has nothing to deliver.
        $feeds->addFieldToFilter('status', Feed::STATUS_READY);

        return array_values($feeds->getItems());
    }
}
