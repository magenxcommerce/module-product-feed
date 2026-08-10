<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Console\Command;

use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared plumbing for the feed CLI commands.
 *
 * A CLI is warranted here, unlike in the admin-button-only modules in this repo:
 * generating a feed is genuinely operator- and CI-facing, and the CLI is how you
 * export a catalog too large to finish inside a cron window, or re-run one feed
 * after fixing its template without waiting for the next slot.
 *
 * AREA CODE: Magento's CLI starts with no area set, and the export resolves
 * product and image URLs, which needs one. The runner emulates the FRONTEND area
 * per store, but that emulation itself requires an area code to already be set,
 * so it is established here. Already-set is not an error - `setAreaCode` throws
 * if called twice, and another command in the same process may have got there
 * first.
 */
abstract class AbstractFeedCommand extends Command
{
    protected const OPTION_ID = 'id';
    protected const OPTION_CODE = 'code';
    protected const OPTION_ALL = 'all';

    public function __construct(
        protected readonly CollectionFactory $feedCollectionFactory,
        protected readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            self::OPTION_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'Numeric ID of a single feed'
        );
        $this->addOption(
            self::OPTION_CODE,
            null,
            InputOption::VALUE_REQUIRED,
            'Code of a single feed (stable across environments - prefer this in scripts)'
        );
        $this->addOption(
            self::OPTION_ALL,
            null,
            InputOption::VALUE_NONE,
            'Every active feed'
        );

        parent::configure();
    }

    protected function ensureAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Throwable) {
            // Already set by another command in this process; nothing to do.
        }
    }

    /**
     * Resolve --id / --code / --all into the feeds to act on.
     *
     * @return Feed[]
     * @throws \InvalidArgumentException when the selection is empty or ambiguous
     */
    protected function resolveFeeds(InputInterface $input, bool $activeOnly): array
    {
        $id = $input->getOption(self::OPTION_ID);
        $code = $input->getOption(self::OPTION_CODE);
        $all = (bool) $input->getOption(self::OPTION_ALL);

        if (!$all && $id === null && $code === null) {
            throw new \InvalidArgumentException(
                'Specify one of --id, --code or --all. Refusing to guess which feeds you meant.'
            );
        }

        $collection = $this->feedCollectionFactory->create();

        if ($id !== null) {
            $collection->addFieldToFilter('feed_id', (int) $id);
        } elseif ($code !== null) {
            $collection->addFieldToFilter('code', $code);
        } elseif ($activeOnly) {
            // --all means "everything that would run on its own", so an inactive
            // feed is skipped; naming one explicitly still runs it, which is what
            // makes testing a not-yet-live feed possible.
            $collection->addActiveFilter();
        }

        $feeds = array_values($collection->getItems());

        if ($feeds === []) {
            throw new \InvalidArgumentException('No feed matched that selection.');
        }

        return $feeds;
    }
}
