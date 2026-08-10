<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\ResourceModel\History;

use Magenx\ProductFeed\Model\History;
use Magenx\ProductFeed\Model\ResourceModel\History as HistoryResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'history_id';

    protected function _construct(): void
    {
        $this->_init(History::class, HistoryResource::class);
    }

    /**
     * Newest first: the History tab is read to answer "what happened last?", so
     * the default order must not be the collection's insertion order.
     */
    public function addFeedFilter(int $feedId): self
    {
        $this->addFieldToFilter('feed_id', $feedId);
        $this->setOrder('created_at', self::SORT_ORDER_DESC);
        $this->setOrder('history_id', self::SORT_ORDER_DESC);

        return $this;
    }
}
