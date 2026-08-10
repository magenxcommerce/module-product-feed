<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Controller\Adminhtml;

use Magenx\ProductFeed\Model\Feed as FeedModel;
use Magenx\ProductFeed\Model\FeedFactory;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Registry;

/**
 * Shared base for the feed admin controllers.
 */
abstract class Feed extends Action
{
    public const ADMIN_RESOURCE = 'Magenx_ProductFeed::feed';

    /** Key the edit form's blocks read the current feed from. */
    public const REGISTRY_KEY = 'magenx_product_feed_feed';

    public function __construct(
        Context $context,
        protected readonly FeedFactory $feedFactory,
        protected readonly FeedResource $feedResource,
        protected readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    /**
     * Load the feed named by the request, or an empty one when creating.
     */
    protected function loadFeed(): ?FeedModel
    {
        $feedId = (int) $this->getRequest()->getParam('feed_id');
        $feed = $this->feedFactory->create();

        if ($feedId > 0) {
            $this->feedResource->load($feed, $feedId);

            if ($feed->getFeedId() === null) {
                return null;
            }
        }

        return $feed;
    }
}
