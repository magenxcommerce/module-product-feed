<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Console\Command;

use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento magenx:feed:list
 *
 * What exists, what state it is in, and when it last ran - the first thing to
 * reach for when a merchant reports that "the feed is not updating", since it
 * distinguishes never-generated from generated-but-not-delivered without opening
 * the admin.
 */
class ListCommand extends AbstractFeedCommand
{
    public function __construct(
        CollectionFactory $feedCollectionFactory,
        State $appState,
        ?string $name = null
    ) {
        parent::__construct($feedCollectionFactory, $appState, $name);
    }

    protected function configure(): void
    {
        $this->setName('magenx:feed:list');
        $this->setDescription('List configured product feeds and their status');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ensureAreaCode();

        $collection = $this->feedCollectionFactory->create();
        $collection->setOrder('feed_id', 'ASC');

        $rows = [];
        foreach ($collection as $feed) {
            $rows[] = [
                $feed->getFeedId(),
                $feed->getCode(),
                $feed->getStoreId(),
                $feed->getFormat(),
                $feed->isActive() ? 'yes' : 'no',
                (string) $feed->getData('status'),
                (string) ($feed->getData('product_count') ?? '-'),
                (string) ($feed->getData('last_generated_at') ?? 'never'),
            ];
        }

        if ($rows === []) {
            $output->writeln('No feeds are configured.');

            return Cli::RETURN_SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Code', 'Store', 'Format', 'Active', 'Status', 'Products', 'Last generated']);
        $table->setRows($rows);
        $table->render();

        return Cli::RETURN_SUCCESS;
    }
}
