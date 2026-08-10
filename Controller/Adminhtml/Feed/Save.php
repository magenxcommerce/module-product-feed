<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Controller\Adminhtml\Feed;

use Magenx\ProductFeed\Controller\Adminhtml\Feed;
use Magenx\ProductFeed\Model\Template\Exception\TemplateSyntaxException;
use Magenx\ProductFeed\Model\Template\TemplateEngine;
use Magenx\ProductFeed\Model\FeedFactory;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

class Save extends Feed
{
    public function __construct(
        Context $context,
        FeedFactory $feedFactory,
        FeedResource $feedResource,
        Registry $registry,
        private readonly TemplateEngine $templateEngine
    ) {
        parent::__construct($context, $feedFactory, $feedResource, $registry);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/index');
        }

        $feed = $this->loadFeed();
        if ($feed === null) {
            $this->messageManager->addErrorMessage(__('That feed no longer exists.'));

            return $redirect->setPath('*/*/index');
        }

        try {
            $data = $this->normalize($data);

            // Compile before saving. A template that does not parse would otherwise
            // be stored, and the failure would surface hours later as a cron error
            // rather than here, where the person who wrote it is looking.
            $this->assertTemplateCompiles($data);

            $feed->addData($data);

            // loadPost() is what gives the condition tree its structure; without it
            // the posted rule[conditions] array is stored as-is and never rebuilds.
            if (isset($data['rule'])) {
                $feed->loadPost($data['rule']);
            }

            $this->feedResource->save($feed);

            $this->messageManager->addSuccessMessage(__('The feed has been saved.'));
            $this->_getSession()->setMagenxProductFeedFormData(null);

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['feed_id' => $feed->getFeedId()]);
            }

            return $redirect->setPath('*/*/index');
        } catch (TemplateSyntaxException $e) {
            $this->messageManager->addErrorMessage(__('Template error: %1', $e->getMessage()));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $this->_getSession()->setMagenxProductFeedFormData($data);

        return $redirect->setPath('*/*/edit', ['feed_id' => $this->getRequest()->getParam('feed_id')]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        unset($data['form_key'], $data['back'], $data['key']);

        // Multi-selects post arrays; the columns are comma-separated strings.
        foreach (['schedule_days', 'schedule_times'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = implode(',', array_filter(array_map('trim', $data[$key])));
            }
        }

        foreach (['is_active', 'csv_include_header', 'csv_bom'] as $key) {
            $data[$key] = isset($data[$key]) ? (int) (bool) $data[$key] : 0;
        }

        // The field map is edited as JSON today and may come from a dynamic-rows
        // control later, so both shapes are accepted. Either way blank rows are
        // dropped - an empty trailing row would otherwise become an empty column
        // in every line of every file the feed produces.
        if (isset($data['field_map'])) {
            $decoded = is_array($data['field_map'])
                ? $data['field_map']
                : json_decode(trim((string) $data['field_map']) ?: '[]', true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException((string) __('The field mapping is not valid JSON.'));
            }

            $rows = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $column = trim((string) ($row['column'] ?? ''));
                if ($column === '') {
                    continue;
                }
                $rows[] = ['column' => $column, 'value' => (string) ($row['value'] ?? '')];
            }

            $data['field_map'] = $rows === [] ? null : json_encode($rows, JSON_UNESCAPED_SLASHES);
        }

        if (isset($data['validation_rules']) && is_string($data['validation_rules'])) {
            $raw = trim($data['validation_rules']);
            if ($raw !== '' && json_decode($raw) === null) {
                throw new \InvalidArgumentException(
                    (string) __('The validation rules are not valid JSON.')
                );
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @throws TemplateSyntaxException
     */
    private function assertTemplateCompiles(array $data): void
    {
        $template = (string) ($data['template'] ?? '');
        if (trim($template) !== '') {
            $this->templateEngine->compile($template);
        }

        $filename = (string) ($data['filename'] ?? '');
        if (str_contains($filename, '{{')) {
            $this->templateEngine->compile($filename);
        }

        $fieldMap = $data['field_map'] ?? null;
        if (is_string($fieldMap) && $fieldMap !== '') {
            foreach ((array) json_decode($fieldMap, true) as $row) {
                $value = (string) ($row['value'] ?? '');
                if ($value !== '') {
                    $this->templateEngine->compile($value);
                }
            }
        }
    }
}
