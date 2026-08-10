<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Controller\Adminhtml\Feed;

use Magento\Framework\Controller\ResultInterface;

/**
 * Creating a feed reuses the edit page rather than having a page of its own -
 * the form is identical, and a separate one is how the two drift apart.
 */
class NewAction extends Edit
{
    public function execute(): ResultInterface
    {
        return parent::execute();
    }
}
