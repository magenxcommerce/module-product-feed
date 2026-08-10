<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Model\Delivery as DeliveryModel;
use Magenx\ProductFeed\Model\Export\FeedFilesystem;
use Magenx\ProductFeed\Model\Export\RecordConsumerInterface;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\FeedHistory;
use Magenx\ProductFeed\Model\ResourceModel\Delivery as DeliveryResource;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory as DeliveryCollectionFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Runs a feed's configured destinations and records what happened.
 *
 * SECRETS ARE DECRYPTED HERE AND NOWHERE ELSE. `magenx_feed_delivery.config`
 * stores credentials encrypted; this class decrypts them into a DeliveryContext
 * for the duration of one attempt, so no deliverer touches the encryptor and a
 * credential cannot leak by way of some deliverer logging its whole config.
 *
 * ONE DESTINATION'S FAILURE MUST NOT STOP THE OTHERS. A merchant who publishes
 * to a public URL, an SFTP server and Google should still get the first two when
 * Google is down - so every attempt is caught individually and reported on its
 * own row.
 */
class DeliveryManager
{
    /**
     * Keys whose values are encrypted at rest.
     *
     * Matched by suffix so a new deliverer's `api_password` or `client_secret` is
     * covered without anyone remembering to add it here - the failure mode of an
     * explicit list is a plaintext credential in the database.
     */
    private const SECRET_SUFFIXES = ['password', 'token', 'secret', 'key'];

    public function __construct(
        private readonly DelivererPool $delivererPool,
        private readonly DeliveryCollectionFactory $deliveryCollectionFactory,
        private readonly DeliveryResource $deliveryResource,
        private readonly FeedFilesystem $feedFilesystem,
        private readonly FeedHistory $feedHistory,
        private readonly EncryptorInterface $encryptor,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Push destinations for this feed, prepared and ready to be fed records as the
     * export runs.
     *
     * @return RecordConsumerInterface[]
     */
    public function getRecordConsumers(Feed $feed): array
    {
        $consumers = [];

        foreach ($this->loadActiveDeliveries($feed) as $delivery) {
            if (!$this->delivererPool->has($delivery->getType())) {
                continue;
            }

            $deliverer = $this->delivererPool->get($delivery->getType());
            if (!$deliverer instanceof RecordConsumerInterface) {
                continue;
            }

            try {
                $context = $this->createContext($feed, $delivery);
                if (!$deliverer->isAvailable($context)->isSuccess()) {
                    continue;
                }

                $deliverer->prepare($context);
                $consumers[] = $deliverer;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Magenx_ProductFeed: could not prepare "%s" for feed "%s": %s',
                        $delivery->getType(),
                        $feed->getCode(),
                        $e->getMessage()
                    )
                );
            }
        }

        return $consumers;
    }

    /**
     * @return array<string, DeliveryResult> delivery type => outcome
     */
    public function deliverAll(Feed $feed): array
    {
        $results = [];
        $feedId = (int) $feed->getFeedId();

        foreach ($this->loadActiveDeliveries($feed) as $delivery) {
            $type = $delivery->getType();

            if (!$this->delivererPool->has($type)) {
                $results[$type] = DeliveryResult::error(
                    (string) __('No deliverer is registered for type "%1".', $type)
                );
                $this->persist($delivery, $results[$type]);
                continue;
            }

            try {
                $context = $this->createContext($feed, $delivery);
                $results[$type] = $this->delivererPool->get($type)->deliver($context);
            } catch (\Throwable $e) {
                // Caught per destination on purpose: the next one may well work.
                $this->logger->error(
                    sprintf(
                        'Magenx_ProductFeed: delivery "%s" of feed "%s" threw: %s',
                        $type,
                        $feed->getCode(),
                        $e->getMessage()
                    )
                );
                $results[$type] = DeliveryResult::error($e->getMessage());
            }

            $this->persist($delivery, $results[$type]);
        }

        if ($results !== []) {
            $this->recordHistory($feedId, $results);
        }

        return $results;
    }

    public function testConnection(Feed $feed, DeliveryModel $delivery): DeliveryResult
    {
        if (!$this->delivererPool->has($delivery->getType())) {
            return DeliveryResult::error(
                (string) __('No deliverer is registered for type "%1".', $delivery->getType())
            );
        }

        try {
            return $this->delivererPool->get($delivery->getType())
                ->testConnection($this->createContext($feed, $delivery));
        } catch (\Throwable $e) {
            return DeliveryResult::error($e->getMessage());
        }
    }

    /**
     * Encrypt the secret-looking keys of a settings array before it is saved.
     *
     * A value that is already encrypted is left alone, so re-saving a form that
     * shows a masked password does not double-encrypt it into garbage.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $existing Currently stored settings
     * @return array<string, mixed>
     */
    public function encryptSettings(array $settings, array $existing = []): array
    {
        foreach ($settings as $key => $value) {
            if (!$this->isSecretKey($key) || !is_string($value)) {
                continue;
            }

            if ($value === '') {
                // An empty secret field means "leave it as it was", not "clear it" -
                // the admin form never renders the real value back.
                $settings[$key] = $existing[$key] ?? '';
                continue;
            }

            $settings[$key] = $this->encryptor->encrypt($value);
        }

        return $settings;
    }

    /**
     * @return DeliveryModel[]
     */
    private function loadActiveDeliveries(Feed $feed): array
    {
        $feedId = $feed->getFeedId();
        if ($feedId === null) {
            return [];
        }

        $collection = $this->deliveryCollectionFactory->create();
        $collection->addFeedFilter($feedId)->addActiveFilter();

        return array_values($collection->getItems());
    }

    private function createContext(Feed $feed, DeliveryModel $delivery): DeliveryContext
    {
        $filename = $this->resolvePublishedFilename($feed);

        return new DeliveryContext(
            $feed,
            $this->decryptSettings($delivery->getConfigData()),
            $this->feedFilesystem->getRelativePath($feed, $filename),
            $this->feedFilesystem->getAbsolutePath($feed, $filename),
            $this->feedFilesystem->getPublicUrl($feed, $filename),
            $filename,
            (int) $feed->getData('product_count')
        );
    }

    /**
     * The name the last run actually published under.
     *
     * Re-rendering the filename template here would be wrong for a date-stamped
     * feed generated just before midnight: the deliverer would look for tomorrow's
     * file. `last_filename` is written by the runner at publish time.
     */
    private function resolvePublishedFilename(Feed $feed): string
    {
        $published = trim((string) $feed->getData('last_filename'));
        if ($published !== '') {
            return $published;
        }

        $configured = trim((string) $feed->getData('filename'));
        if ($configured !== '' && !str_contains($configured, '{{')) {
            return $configured;
        }

        return $feed->getCode() . '.' . $feed->getFormat();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function decryptSettings(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if ($this->isSecretKey($key) && is_string($value) && $value !== '') {
                try {
                    $settings[$key] = $this->encryptor->decrypt($value);
                } catch (\Throwable) {
                    // A value that predates encryption, or one written by hand, is
                    // used as-is rather than blanked - blanking it would turn a
                    // working destination into a silently skipped one.
                    $settings[$key] = $value;
                }
            }
        }

        return $settings;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function persist(DeliveryModel $delivery, DeliveryResult $result): void
    {
        try {
            $delivery->setData('last_status', $result->status);
            $delivery->setData('last_message', mb_substr($result->message, 0, 2000));

            if ($result->isSuccess()) {
                $delivery->setData('last_delivered_at', $this->dateTime->gmtDate());
            }

            $this->deliveryResource->save($delivery);
        } catch (\Throwable $e) {
            $this->logger->warning('Magenx_ProductFeed: could not persist delivery state: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, DeliveryResult> $results
     */
    private function recordHistory(int $feedId, array $results): void
    {
        $failed = [];
        $succeeded = [];
        $details = [];

        foreach ($results as $type => $result) {
            $details[$type] = ['status' => $result->status, 'message' => $result->message];

            if ($result->isError()) {
                $failed[] = $type;
            } elseif ($result->isSuccess()) {
                $succeeded[] = $type;
            }
        }

        $status = $failed !== [] ? 'error' : ($succeeded !== [] ? 'success' : 'skipped');

        $message = $failed !== []
            ? (string) __('Delivered to %1; failed for %2.', implode(', ', $succeeded) ?: '-', implode(', ', $failed))
            : (string) __('Delivered to %1.', implode(', ', $succeeded) ?: '-');

        $this->feedHistory->record($feedId, FeedHistory::TYPE_DELIVER, $status, $message, null, null, $details);
    }
}
