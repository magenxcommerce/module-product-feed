<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magenx\ProductFeed\Model\ResourceModel\History as HistoryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One row in a feed's run log.
 */
class History extends AbstractModel
{
    protected $_eventPrefix = 'magenx_product_feed_history';

    protected $_eventObject = 'history';

    protected function _construct(): void
    {
        $this->_init(HistoryResource::class);
        $this->setIdFieldName('history_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        $raw = (string) $this->getData('details');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
