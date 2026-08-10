<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Api\DelivererInterface;
use Magenx\ProductFeed\Model\Export\RecordConsumerInterface;
use Magenx\ProductFeed\Model\Feed;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Pushes records straight into a Meta (Facebook / Instagram) catalog.
 *
 * THE PUSH DESTINATION THIS MODULE IS SHAPED AROUND. It implements
 * RecordConsumerInterface as well as DelivererInterface, so it is fed from the
 * same record stream the file writer sees, as the export runs - no file is
 * produced for it, and nothing is parsed back out of one. That is the whole
 * reason records exist as a stage of their own in the pipeline.
 *
 * Meta's own guidance is to use this endpoint rather than a hosted feed whenever
 * updates are more frequent than hourly, which is exactly the case a merchant
 * running this on cron is in.
 *
 * REQUIRES A FIELD-MAPPED FEED. A free-form XML template has no record
 * equivalent, so pointing one at this destination cannot work; deliver() says so
 * plainly instead of sending nothing and reporting success.
 */
class MetaCatalogBatchDeliverer implements DelivererInterface, RecordConsumerInterface
{
    private const API_VERSION = 'v21.0';
    private const ENDPOINT = 'https://graph.facebook.com/%s/%s/items_batch';

    /** Meta's documented ceiling for one items_batch request. */
    private const MAX_ITEMS_PER_REQUEST = 5000;

    /**
     * Records buffered for the current tick, keyed by feed id.
     *
     * Buffering must not span cron ticks: a run pauses at a batch boundary and
     * resumes in a different process, so Runner calls flush() at the end of every
     * tick. Keyed by feed because one process can generate several feeds in a row.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $buffer = [];

    /** @var array<int, array{sent: int, failed: int, errors: string[]}> */
    private array $stats = [];

    /**
     * Delivery settings per feed. The consumer path receives records, not
     * contexts, so DeliveryManager hands these over once via prepare() before the
     * run starts.
     *
     * @var array<int, DeliveryContext>
     */
    private array $contexts = [];

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getLabel(): string
    {
        return (string) __('Meta Catalog (Facebook / Instagram, direct push)');
    }

    public function isAvailable(DeliveryContext $context): DeliveryResult
    {
        if ($context->getString('catalog_id') === '') {
            return DeliveryResult::skipped(__('No Meta catalog ID is configured.')->render());
        }

        if ($context->getString('access_token') === '') {
            return DeliveryResult::skipped(__('No Meta access token is configured.')->render());
        }

        if (!$context->feed->producesRecords()) {
            return DeliveryResult::skipped(
                __(
                    'Meta catalog push needs a feed that produces records. '
                    . 'Set this feed\'s format to CSV, TSV or JSONL and define its columns in Field Mapping; '
                    . 'a free-form XML template cannot be pushed to a catalog API.'
                )->render()
            );
        }

        return DeliveryResult::success('Available.');
    }

    public function testConnection(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        // A GET of the catalog node itself: it proves the id exists and the token
        // can read it, and it writes nothing.
        $curl = $this->curlFactory->create();
        $curl->setTimeout(30);

        $url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=id,name&access_token=%s',
            self::API_VERSION,
            rawurlencode($context->getString('catalog_id')),
            rawurlencode($context->getString('access_token'))
        );

        try {
            $curl->get($url);
        } catch (\Throwable $e) {
            return DeliveryResult::error(__('Could not reach Meta: %1', $e->getMessage())->render());
        }

        $body = json_decode((string) $curl->getBody(), true);

        if ($curl->getStatus() !== 200 || !is_array($body) || isset($body['error'])) {
            return DeliveryResult::error($this->explain($body, $curl->getStatus()));
        }

        return DeliveryResult::success(
            __('Connected to Meta catalog "%1". Nothing was uploaded.', (string) ($body['name'] ?? $body['id'] ?? ''))
                ->render()
        );
    }

    /**
     * By the time this runs the records have already been pushed during the export,
     * so this only reports what happened.
     */
    public function deliver(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        $feedId = (int) $context->feed->getFeedId();
        $stats = $this->stats[$feedId] ?? ['sent' => 0, 'failed' => 0, 'errors' => []];

        if ($stats['sent'] === 0 && $stats['failed'] === 0) {
            return DeliveryResult::skipped(
                __('No records were pushed - the feed produced nothing in this run.')->render()
            );
        }

        if ($stats['failed'] > 0) {
            return DeliveryResult::error(
                __(
                    'Pushed %1 items to Meta; %2 failed. First error: %3',
                    $stats['sent'],
                    $stats['failed'],
                    $stats['errors'][0] ?? ''
                )->render(),
                $stats
            );
        }

        return DeliveryResult::success(
            __('Pushed %1 items to the Meta catalog.', $stats['sent'])->render(),
            $stats
        );
    }

    // =====================================================================
    // RecordConsumerInterface - fed live during the export
    // =====================================================================

    public function consume(Feed $feed, array $records): void
    {
        if ($records === []) {
            return;
        }

        $feedId = (int) $feed->getFeedId();
        foreach ($records as $record) {
            $this->buffer[$feedId][] = $record;
        }

        if (count($this->buffer[$feedId]) >= self::MAX_ITEMS_PER_REQUEST) {
            $this->send($feed);
        }
    }

    public function flush(Feed $feed): void
    {
        $this->send($feed);
    }

    public function finish(Feed $feed): void
    {
        $this->send($feed);
    }

    public function prepare(DeliveryContext $context): void
    {
        $feedId = (int) $context->feed->getFeedId();
        $this->contexts[$feedId] = $context;
        $this->buffer[$feedId] = [];
        $this->stats[$feedId] = ['sent' => 0, 'failed' => 0, 'errors' => []];
    }

    private function send(Feed $feed): void
    {
        $feedId = (int) $feed->getFeedId();
        $records = $this->buffer[$feedId] ?? [];

        if ($records === []) {
            return;
        }

        $this->buffer[$feedId] = [];
        $context = $this->contexts[$feedId] ?? null;

        if ($context === null) {
            // Nothing configured this consumer, so there is nowhere to send. Dropping
            // silently would look like a successful push of zero items.
            $this->logger->warning(
                sprintf('Magenx_ProductFeed: Meta consumer for feed %d was never prepared; %d records dropped.',
                    $feedId, count($records))
            );

            return;
        }

        foreach (array_chunk($records, self::MAX_ITEMS_PER_REQUEST) as $chunk) {
            $this->sendChunk($context, $feedId, $chunk);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     */
    private function sendChunk(DeliveryContext $context, int $feedId, array $chunk): void
    {
        $requests = [];
        foreach ($chunk as $record) {
            $retailerId = $this->resolveRetailerId($record);
            if ($retailerId === '') {
                // An item with no stable id cannot be updated or deleted later, so
                // sending it would create an orphan in the catalog on every run.
                $this->stats[$feedId]['failed']++;
                $this->stats[$feedId]['errors'][] = 'A record had no id / retailer_id column.';
                continue;
            }

            $requests[] = [
                'method' => 'UPDATE',
                'retailer_id' => $retailerId,
                'data' => $this->buildItemData($record),
            ];
        }

        if ($requests === []) {
            return;
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(120);
        $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        $url = sprintf(
            self::ENDPOINT,
            self::API_VERSION,
            rawurlencode($context->getString('catalog_id'))
        );

        try {
            $curl->post($url, [
                'access_token' => $context->getString('access_token'),
                'item_type' => $context->getString('item_type', 'PRODUCT_ITEM'),
                'requests' => json_encode($requests, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            $this->stats[$feedId]['failed'] += count($requests);
            $this->stats[$feedId]['errors'][] = $e->getMessage();

            return;
        }

        $body = json_decode((string) $curl->getBody(), true);

        if ($curl->getStatus() < 200 || $curl->getStatus() >= 300 || (is_array($body) && isset($body['error']))) {
            $message = $this->explain(is_array($body) ? $body : [], $curl->getStatus());

            $this->stats[$feedId]['failed'] += count($requests);
            $this->stats[$feedId]['errors'][] = $message;

            $this->logger->error(
                sprintf('Magenx_ProductFeed: Meta items_batch failed for feed %d: %s', $feedId, $message)
            );

            return;
        }

        $this->stats[$feedId]['sent'] += count($requests);
    }

    /**
     * Meta identifies an item by `retailer_id`, which must be stable for the life
     * of the product - it is the key every later update and deletion is matched
     * on. The feed's own id column is used, falling back to sku.
     *
     * @param array<string, mixed> $record
     */
    private function resolveRetailerId(array $record): string
    {
        foreach (['id', 'retailer_id', 'g:id', 'item_id', 'sku'] as $key) {
            $value = $record[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Strip the id columns out of the payload and normalise Google-style "g:"
     * prefixes, which merchants routinely leave in place after copying a Google
     * template.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function buildItemData(array $record): array
    {
        $data = [];

        foreach ($record as $key => $value) {
            $normalised = str_starts_with($key, 'g:') ? substr($key, 2) : $key;

            if (in_array($normalised, ['id', 'retailer_id', 'item_id'], true)) {
                continue;
            }

            $data[$normalised] = is_array($value) ? implode(',', array_map('strval', $value)) : $value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function explain(array $body, int $status): string
    {
        $error = $body['error'] ?? null;

        if (!is_array($error)) {
            return (string) __('Meta returned HTTP %1.', $status);
        }

        $message = (string) ($error['message'] ?? 'unknown error');
        $code = (int) ($error['code'] ?? 0);

        return match ($code) {
            190 => (string) __(
                'Meta rejected the access token (%1). System user tokens do not expire, but a user token does - '
                . 'generate a system user token in Business Settings and use that.',
                $message
            ),
            200, 10 => (string) __(
                'Meta denied access (%1). The token needs the catalog_management permission and the system user '
                . 'needs a role on the catalog.',
                $message
            ),
            default => (string) __('Meta returned an error (%1).', $message),
        };
    }
}
