<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions: edit, generate now, delete.
 */
class FeedActions extends Column
{
    private const URL_EDIT = 'magenx_product_feed/feed/edit';
    private const URL_DELETE = 'magenx_product_feed/feed/delete';
    private const URL_GENERATE = 'magenx_product_feed/feed/generate';

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $feedId = $item['feed_id'] ?? null;
            if ($feedId === null) {
                continue;
            }

            $item[$name] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_EDIT, ['feed_id' => $feedId]),
                    'label' => __('Edit'),
                ],
                'generate' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_GENERATE, ['feed_id' => $feedId]),
                    'label' => __('Generate Now'),
                    'confirm' => [
                        'title' => __('Generate "%1"?', $item['name'] ?? ''),
                        // Said plainly because on a large catalog this ties up the
                        // request until the time budget is reached, and the merchant
                        // should expect to come back to a partially generated feed.
                        'message' => __(
                            'This runs one generation slice now. A large catalog may need several runs, '
                            . 'or will finish on its own from cron.'
                        ),
                    ],
                    'post' => true,
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_DELETE, ['feed_id' => $feedId]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete "%1"?', $item['name'] ?? ''),
                        'message' => __(
                            'The feed, its delivery settings and its run history are removed. '
                            . 'Any file already published stays where it is.'
                        ),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
