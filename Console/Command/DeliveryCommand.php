<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Console\Command;

use Magenx\ProductFeed\Model\Delivery;
use Magenx\ProductFeed\Model\Delivery\DelivererPool;
use Magenx\ProductFeed\Model\Delivery\DeliveryManager;
use Magenx\ProductFeed\Model\DeliveryFactory;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Delivery as DeliveryResource;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory as DeliveryCollectionFactory;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento magenx:feed:delivery
 *
 * Creates or updates one destination of one feed, and optionally tests it.
 *
 *   bin/magento magenx:feed:delivery --code=openai --type=sftp \
 *       --set host=sftp.example.com --set username=acme --set path=/ \
 *       --set remote_filename=products.jsonl.gz --key-file=/secure/openai_ed25519 --enable --test
 *
 * Exists because destinations had no editor at all, and writing the `config`
 * JSON by hand means writing credentials into the database in plain text.
 * Every secret-looking setting (password, token, secret, key) goes through
 * DeliveryManager::encryptSettings() here, exactly as a form save would.
 *
 * Secrets should not be typed on the command line, where they land in shell
 * history and the process list: --key-file reads an SSH private key from a file,
 * and --secret-env=api_key=ACP_API_KEY reads a value from an environment
 * variable. --set remains for everything that is not secret.
 */
class DeliveryCommand extends AbstractFeedCommand
{
    private const OPTION_TYPE = 'type';
    private const OPTION_SET = 'set';
    private const OPTION_UNSET = 'unset';
    private const OPTION_KEY_FILE = 'key-file';
    private const OPTION_SECRET_ENV = 'secret-env';
    private const OPTION_ENABLE = 'enable';
    private const OPTION_DISABLE = 'disable';
    private const OPTION_TEST = 'test';

    public function __construct(
        CollectionFactory $feedCollectionFactory,
        State $appState,
        private readonly DelivererPool $delivererPool,
        private readonly DeliveryManager $deliveryManager,
        private readonly DeliveryFactory $deliveryFactory,
        private readonly DeliveryResource $deliveryResource,
        private readonly DeliveryCollectionFactory $deliveryCollectionFactory,
        ?string $name = null
    ) {
        parent::__construct($feedCollectionFactory, $appState, $name);
    }

    protected function configure(): void
    {
        $this->setName('magenx:feed:delivery');
        $this->setDescription('Create, update or test a destination (sftp, acp_feed_api, ...) of one feed');

        $this->addOption(self::OPTION_TYPE, null, InputOption::VALUE_REQUIRED, 'Deliverer type, e.g. sftp or acp_feed_api');
        $this->addOption(
            self::OPTION_SET,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Setting as key=value (repeatable)'
        );
        $this->addOption(
            self::OPTION_UNSET,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Setting to remove (repeatable)'
        );
        $this->addOption(
            self::OPTION_KEY_FILE,
            null,
            InputOption::VALUE_REQUIRED,
            'Read the SFTP private_key setting from this file'
        );
        $this->addOption(
            self::OPTION_SECRET_ENV,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Setting read from an environment variable, as key=ENV_NAME (repeatable)'
        );
        $this->addOption(self::OPTION_ENABLE, null, InputOption::VALUE_NONE, 'Activate the destination');
        $this->addOption(self::OPTION_DISABLE, null, InputOption::VALUE_NONE, 'Deactivate the destination');
        $this->addOption(
            self::OPTION_TEST,
            null,
            InputOption::VALUE_NONE,
            'Test the connection after saving (writes nothing to the destination)'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ensureAreaCode();

        try {
            [$feed, $delivery] = $this->saveDelivery($input);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not save the destination: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Saved %s for feed "%s" (%s). Settings: %s</info>',
            $delivery->getType(),
            $feed->getCode(),
            $delivery->isActive() ? 'active' : 'inactive',
            implode(', ', array_keys($delivery->getConfigData())) ?: '-'
        ));

        if (!$input->getOption(self::OPTION_TEST)) {
            return Cli::RETURN_SUCCESS;
        }

        $result = $this->deliveryManager->testConnection($feed, $delivery);
        $output->writeln(sprintf(
            '%s%s: %s%s',
            $result->isSuccess() ? '<info>' : '<error>',
            $result->status,
            $result->message,
            $result->isSuccess() ? '</info>' : '</error>'
        ));

        return $result->isSuccess() ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }

    /**
     * @return array{0: Feed, 1: Delivery}
     * @throws \InvalidArgumentException on a bad selection or option
     */
    private function saveDelivery(InputInterface $input): array
    {
        $feeds = $this->resolveFeeds($input, false);
        if (count($feeds) !== 1) {
            throw new \InvalidArgumentException('Select exactly one feed with --id or --code.');
        }
        $feed = $feeds[0];

        $type = trim((string) $input->getOption(self::OPTION_TYPE));
        if ($type === '' || !$this->delivererPool->has($type)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown --type "%s". Registered types: %s.',
                $type,
                implode(', ', array_keys($this->delivererPool->getOptions()))
            ));
        }

        $delivery = $this->loadOrCreate((int) $feed->getFeedId(), $type);
        $existing = $delivery->getConfigData();
        $settings = $existing;

        foreach ((array) $input->getOption(self::OPTION_UNSET) as $key) {
            unset($settings[trim((string) $key)]);
        }

        // Only the settings given now are (re-)encrypted; stored ones are already
        // encrypted and must not be encrypted twice.
        $settings = array_merge(
            $settings,
            $this->deliveryManager->encryptSettings($this->collectChanges($input), $existing)
        );
        $delivery->setConfigData($settings);

        if ($input->getOption(self::OPTION_ENABLE)) {
            $delivery->setData('is_active', 1);
        } elseif ($input->getOption(self::OPTION_DISABLE)) {
            $delivery->setData('is_active', 0);
        }

        $this->deliveryResource->save($delivery);

        return [$feed, $delivery];
    }

    /**
     * @return array<string, string>
     */
    private function collectChanges(InputInterface $input): array
    {
        $changes = [];

        foreach ((array) $input->getOption(self::OPTION_SET) as $pair) {
            [$key, $value] = $this->splitPair((string) $pair, '--set');
            $changes[$key] = $value;
        }

        foreach ((array) $input->getOption(self::OPTION_SECRET_ENV) as $pair) {
            [$key, $variable] = $this->splitPair((string) $pair, '--secret-env');
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the point of --secret-env is to keep secrets out of argv.
            $value = getenv($variable);
            if ($value === false || $value === '') {
                throw new \InvalidArgumentException(sprintf('Environment variable %s is not set.', $variable));
            }
            $changes[$key] = $value;
        }

        $keyFile = trim((string) $input->getOption(self::OPTION_KEY_FILE));
        if ($keyFile !== '') {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- an operator-supplied local key file, read once by the CLI.
            $key = is_readable($keyFile) ? file_get_contents($keyFile) : false;
            if ($key === false || trim($key) === '') {
                throw new \InvalidArgumentException(sprintf('Could not read the key file %s.', $keyFile));
            }
            $changes['private_key'] = $key;
        }

        return $changes;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPair(string $pair, string $option): array
    {
        $position = strpos($pair, '=');
        $key = $position === false ? '' : trim(substr($pair, 0, $position));

        if ($key === '') {
            throw new \InvalidArgumentException(sprintf('%s expects key=value, got "%s".', $option, $pair));
        }

        return [$key, (string) substr($pair, $position + 1)];
    }

    private function loadOrCreate(int $feedId, string $type): Delivery
    {
        $collection = $this->deliveryCollectionFactory->create();
        $collection->addFeedFilter($feedId)->addFieldToFilter('type', $type);

        $delivery = $collection->getFirstItem();
        if ($delivery instanceof Delivery && $delivery->getId()) {
            return $delivery;
        }

        $delivery = $this->deliveryFactory->create();
        $delivery->setData(['feed_id' => $feedId, 'type' => $type, 'is_active' => 0]);

        return $delivery;
    }
}
