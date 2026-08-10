<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\ResourceModel\Delivery;

use Magenx\ProductFeed\Model\Delivery;
use Magenx\ProductFeed\Model\ResourceModel\Delivery as DeliveryResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'delivery_id';

    protected function _construct(): void
    {
        $this->_init(Delivery::class, DeliveryResource::class);
    }

    public function addFeedFilter(int $feedId): self
    {
        $this->addFieldToFilter('feed_id', $feedId);

        return $this;
    }

    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);

        return $this;
    }
}
