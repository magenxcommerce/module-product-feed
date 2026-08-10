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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento magenx:feed:generate
 *
 * Runs a feed to completion, ignoring its schedule.
 *
 * DIFFERENT FROM THE CRON PATH IN ONE RESPECT: cron generates in slices bounded
 * by a time budget so it can share the cron window, whereas an operator running
 * this expects the command to finish the job. So the loop is repeated until the
 * run reports itself complete. --slice restores the cron behaviour for anyone
 * who wants to nudge a stalled feed forward by exactly one tick.
 */
class GenerateCommand extends AbstractFeedCommand
{
    private const OPTION_SLICE = 'slice';

    /** Refuse to loop forever if a run never advances. */
    private const MAX_SLICES = 10000;

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
        $this->setName('magenx:feed:generate');
        $this->setDescription('Generate product feeds and deliver them to their configured destinations');
        $this->addOption(
            self::OPTION_SLICE,
            null,
            InputOption::VALUE_NONE,
            'Run a single time-budgeted slice instead of generating to completion'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ensureAreaCode();

        if (!$this->config->isEnabled()) {
            $output->writeln(
                '<error>Product feeds are disabled.</error> '
                . 'Enable them under Stores > Configuration > Magenx > Product Feeds.'
            );

            return Cli::RETURN_FAILURE;
        }

        try {
            $feeds = $this->resolveFeeds($input, true);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }

        $sliceOnly = (bool) $input->getOption(self::OPTION_SLICE);
        $exitCode = Cli::RETURN_SUCCESS;

        foreach ($feeds as $feed) {
            $output->writeln(sprintf('<info>%s</info> (store %d)', $feed->getCode(), $feed->getStoreId()));

            $slices = 0;

            do {
                $result = $this->feedManager->process($feed);
                $slices++;

                if ($result->skipped) {
                    $output->writeln('  skipped: ' . $result->message);
                    break;
                }

                if ($result->failed) {
                    $output->writeln('  <error>failed: ' . $result->message . '</error>');
                    $exitCode = Cli::RETURN_FAILURE;
                    break;
                }

                if ($result->completed) {
                    $output->writeln(sprintf(
                        '  <info>done</info>: %d products in %d ms%s',
                        $result->productCount,
                        $result->durationMs,
                        $result->publishedPath === null ? '' : ' -> ' . $result->publishedPath
                    ));
                    break;
                }

                $output->writeln(sprintf('  ... %d products so far', $result->productCount));

                if ($sliceOnly) {
                    break;
                }

                if ($slices >= self::MAX_SLICES) {
                    $output->writeln('  <error>giving up: the run is not advancing.</error>');
                    $exitCode = Cli::RETURN_FAILURE;
                    break;
                }
            } while (true);
        }

        return $exitCode;
    }
}
