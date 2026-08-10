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
use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * Serves the HTML for a newly added condition row.
 *
 * The "+" button in the condition tree posts here. Without this endpoint the
 * button appears and does nothing, which is the single most common way a
 * hand-built rule form looks finished and is not - the tree renders, saves, and
 * simply cannot have conditions added to it.
 */
class NewConditionHtml extends Feed
{
    public function execute(): ResultInterface
    {
        $id = (string) $this->getRequest()->getParam('id');
        $typeArr = explode('|', str_replace('-', '/', (string) $this->getRequest()->getParam('type')));
        $type = $typeArr[0];

        $model = $this->_objectManager->create($type)
            ->setId($id)
            ->setType($type)
            ->setRule($this->feedFactory->create())
            ->setPrefix('conditions');

        if (isset($typeArr[1])) {
            $model->setAttribute($typeArr[1]);
        }

        $html = '';
        if ($model instanceof AbstractCondition) {
            $model->setJsFormObject((string) $this->getRequest()->getParam('form'));
            $html = $model->asHtmlRecursive();
        }

        return $this->resultFactory->create(ResultFactory::TYPE_RAW)->setContents($html);
    }
}
