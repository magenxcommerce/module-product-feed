<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Block\Adminhtml\Feed;

use Magenx\ProductFeed\Controller\Adminhtml\Feed as FeedController;
use Magenx\ProductFeed\Model\Feed;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * Edit-page container.
 *
 * A legacy Widget form rather than a ui_component form, because the product
 * filter is a condition tree and the ui_component stack has no equivalent of the
 * rule fieldset. This is the same trade Magenx_AutoProductLinks makes for its
 * rule form.
 */
class Edit extends Container
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct(): void
    {
        $this->_objectId = 'feed_id';
        $this->_blockGroup = 'Magenx_ProductFeed';
        $this->_controller = 'adminhtml_feed';

        parent::_construct();

        $this->buttonList->update('save', 'label', __('Save Feed'));
        $this->buttonList->add(
            'save_and_continue',
            [
                'label' => __('Save and Continue Editing'),
                'class' => 'save',
                'data_attribute' => [
                    'mage-init' => ['button' => ['event' => 'saveAndContinueEdit', 'target' => '#edit_form']],
                ],
            ],
            10
        );

        $feed = $this->getFeed();

        if ($feed !== null && $feed->getFeedId() !== null) {
            $this->buttonList->add(
                'generate',
                [
                    'label' => __('Generate Now'),
                    'class' => 'primary',
                    'onclick' => sprintf(
                        "setLocation('%s')",
                        $this->getUrl('*/*/generate', ['feed_id' => $feed->getFeedId()])
                    ),
                ],
                20
            );
        }
    }

    public function getHeaderText(): \Magento\Framework\Phrase
    {
        $feed = $this->getFeed();

        return $feed !== null && $feed->getFeedId() !== null
            ? __('Edit Feed "%1"', $feed->getData('name'))
            : __('New Feed');
    }

    private function getFeed(): ?Feed
    {
        $feed = $this->registry->registry(FeedController::REGISTRY_KEY);

        return $feed instanceof Feed ? $feed : null;
    }
}
