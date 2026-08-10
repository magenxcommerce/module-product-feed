<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Console\Command;

use Magenx\ProductFeed\Model\Config;
use Magenx\ProductFeed\Model\FeedManager;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento magenx:feed:deliver
 *
 * Sends the file a feed last published, without regenerating it - the recovery
 * path for a destination that was down.
 *
 * Re-exporting to retry an upload would be the expensive half of the work for
 * none of the benefit, and on a large catalog it can take longer than the outage
 * did.
 */
class DeliverCommand extends AbstractFeedCommand
{
    public function __construct(
        CollectionFactory $feedCollectionFactory,
        State $appState,
        private readonly FeedManager $feedManager,
        private readonly Config $config,
        ?string $name = null
    ) {
        parent::__construct($feedCollectionFactory, $appState, $name);
    }

    protected function configure(): void
    {
        $this->setName('magenx:feed:deliver');
        $this->setDescription('Deliver already-generated feeds to their configured destinations');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ensureAreaCode();

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Product feeds are disabled.</error>');

            return Cli::RETURN_FAILURE;
        }

        try {
            $feeds = $this->resolveFeeds($input, true);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }

        $exitCode = Cli::RETURN_SUCCESS;

        foreach ($feeds as $feed) {
            $output->writeln(sprintf('<info>%s</info>', $feed->getCode()));

            $results = $this->feedManager->deliver($feed);

            if ($results === []) {
                $output->writeln('  no active destinations configured.');
                continue;
            }

            foreach ($results as $type => $result) {
                if ($result->isError()) {
                    $output->writeln(sprintf('  <error>%s: %s</error>', $type, $result->message));
                    $exitCode = Cli::RETURN_FAILURE;
                } elseif ($result->isSkipped()) {
                    $output->writeln(sprintf('  %s: skipped - %s', $type, $result->message));
                } else {
                    $output->writeln(sprintf('  <info>%s</info>: %s', $type, $result->message));
                }
            }
        }

        return $exitCode;
    }
}
