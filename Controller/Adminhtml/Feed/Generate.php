<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Controller\Adminhtml\Feed;

use Magenx\ProductFeed\Controller\Adminhtml\Feed;
use Magenx\ProductFeed\Model\FeedFactory;
use Magenx\ProductFeed\Model\FeedManager;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * "Generate Now" from the grid or the form.
 *
 * Runs ONE time-budgeted slice, exactly as cron does, rather than looping to
 * completion. A large catalog would otherwise hold the admin request open past
 * the web server's timeout and leave the merchant with a browser error and a
 * run whose state they cannot see - whereas a slice always returns, reports what
 * it did, and the rest happens on cron.
 */
class Generate extends Feed
{
    public const ADMIN_RESOURCE = 'Magenx_ProductFeed::generate';

    public function __construct(
        Context $context,
        FeedFactory $feedFactory,
        FeedResource $feedResource,
        Registry $registry,
        private readonly FeedManager $feedManager
    ) {
        parent::__construct($context, $feedFactory, $feedResource, $registry);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $feed = $this->loadFeed();

        if ($feed === null || $feed->getFeedId() === null) {
            $this->messageManager->addErrorMessage(__('That feed no longer exists.'));

            return $redirect->setPath('*/*/index');
        }

        try {
            $result = $this->feedManager->process($feed);

            if ($result->skipped) {
                $this->messageManager->addNoticeMessage(__($result->message));
            } elseif ($result->failed) {
                $this->messageManager->addErrorMessage(__('Generation failed: %1', $result->message));
            } elseif ($result->completed) {
                $this->messageManager->addSuccessMessage(
                    __('Generated %1 products in %2 ms.', $result->productCount, $result->durationMs)
                );
            } else {
                $this->messageManager->addNoticeMessage(
                    __(
                        '%1 products so far. The run reached its time budget and will continue from cron, '
                        . 'or you can press Generate again.',
                        $result->productCount
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect->setPath('*/*/index');
    }
}
