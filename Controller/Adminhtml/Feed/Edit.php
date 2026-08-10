<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Controller\Adminhtml\Feed;

use Magenx\ProductFeed\Controller\Adminhtml\Feed;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

class Edit extends Feed
{
    public function execute(): ResultInterface
    {
        $feed = $this->loadFeed();

        if ($feed === null) {
            $this->messageManager->addErrorMessage(__('That feed no longer exists.'));

            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
        }

        // Restore a failed submission so the merchant does not lose a long template
        // to a validation error.
        $restored = $this->_getSession()->getMagenxProductFeedFormData(true);
        if (is_array($restored) && $restored !== []) {
            $feed->addData($restored);
        }

        $this->registry->register(self::REGISTRY_KEY, $feed);

        /** @var Page $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu('Magenx_ProductFeed::feed');
        $result->getConfig()->getTitle()->prepend(
            $feed->getFeedId() === null ? __('New Feed') : __('Edit Feed "%1"', $feed->getData('name'))
        );

        return $result;
    }
}
