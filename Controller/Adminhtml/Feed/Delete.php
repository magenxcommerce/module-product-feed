<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Controller\Adminhtml\Feed;

use Magenx\ProductFeed\Controller\Adminhtml\Feed;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * POST only: a destructive action behind a GET is one crawler or one prefetch
 * away from deleting a merchant's feeds.
 */
class Delete extends Feed implements HttpPostActionInterface
{
    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $feed = $this->loadFeed();

        if ($feed === null || $feed->getFeedId() === null) {
            $this->messageManager->addErrorMessage(__('That feed no longer exists.'));

            return $redirect->setPath('*/*/index');
        }

        try {
            // Delivery rows and history cascade with the row; the published file is
            // deliberately left alone, because a consumer may still be fetching it
            // and removing it turns a deletion into a broken URL on someone else's
            // platform.
            $this->feedResource->delete($feed);
            $this->messageManager->addSuccessMessage(__('The feed has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect->setPath('*/*/index');
    }
}
