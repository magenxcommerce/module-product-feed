<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\ResourceModel\Feed;

use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Rule\Model\ResourceModel\Rule\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'feed_id';

    protected function _construct(): void
    {
        $this->_init(Feed::class, FeedResource::class);
    }

    /**
     * Feeds the cron dispatcher should consider on this tick.
     */
    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);

        return $this;
    }

    public function addStoreFilter(int $storeId): self
    {
        $this->addFieldToFilter('store_id', $storeId);

        return $this;
    }
}
