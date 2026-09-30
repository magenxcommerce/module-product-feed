<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Api\DelivererInterface;
use Magenx\ProductFeed\Model\Acp\ProductPayloadBuilder;
use Magenx\ProductFeed\Model\Export\FeedFilesystem;
use Magenx\ProductFeed\Model\Export\RecordConsumerInterface;
use Magenx\ProductFeed\Model\Feed;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Pushes the catalog to OpenAI through the Agentic Commerce Protocol Products
 * API: PATCH {base_url}/product_feeds/{feed_id}/products.
 *
 * Spec: https://developers.openai.com/commerce/specs/api/overview
 *
 * Settings (magenx_feed_delivery.config):
 *   base_url        API root. OpenAI issues it at onboarding; it is not public.
 *   api_key         Bearer key (encrypted at rest).
 *   feed_id         The product feed id OpenAI created for this catalog.
 *   target_country  Optional ISO 3166 alpha-2, sent with every request.
 *   api_version     API-Version header, default 2025-09-12.
 *   batch_size      Products per PATCH, default 100.
 *
 * WHY IT SPOOLS TO DISK instead of pushing each batch as it arrives, like the
 * Meta deliverer does: the API is keyed by PRODUCT, each holding its variants,
 * and a PATCH upserts a product whole. The export pages by entity id, so a
 * configurable's children can land in different batches - or different cron
 * ticks - and pushing a product with only the variants seen so far would replace
 * the ones sent earlier. So records are appended to a spool file under var/ as
 * they are exported (the spool is on disk, so it survives the tick boundary
 * that in-memory buffers may not cross), and the whole spool is grouped and sent
 * when the run completes. Grouping streams: one pass counts each group's rows,
 * the next emits a product the moment its last row has been read, so memory
 * holds only the groups still open, not the catalog.
 *
 * The completed spool is kept as the last full record set, which is what lets
 * `magenx:feed:deliver` and the retry cron re-send without regenerating.
 *
 * OpenAI's own guidance is a daily full file upload with the API for updates
 * during the day; for a small catalog the API alone can carry both. This class
 * sends the full set each run - use it on a frequent schedule for a small or
 * filtered feed, and SFTP for the daily snapshot of a large one.
 */
class AcpFeedApiDeliverer implements DelivererInterface, RecordConsumerInterface
{
    private const DEFAULT_API_VERSION = '2025-09-12';
    private const DEFAULT_BATCH_SIZE = 100;
    private const MAX_BATCH_SIZE = 1000;

    /** Attempts per request on 429 / 5xx / transport failure. */
    private const MAX_ATTEMPTS = 3;

    /** Ceiling on a Retry-After wait: a cron worker must not block for minutes. */
    private const MAX_RETRY_WAIT_SECONDS = 30;

    private const SPOOL_DIR = FeedFilesystem::VAR_ROOT . '/acp_api';

    /** @var array<int, DeliveryContext> */
    private array $contexts = [];

    /**
     * Feeds whose spool must be truncated before the first append of this
     * process - set when prepare() sees a run that is starting rather than
     * resuming. Deferred to the first consume() so a process that prepares but
     * then loses the generation lock to another does not wipe that run's spool.
     *
     * @var array<int, true>
     */
    private array $pendingReset = [];

    /** @var array<int, array{products: int, variants: int, failed: int, errors: string[]}> */
    private array $stats = [];

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Filesystem $filesystem,
        private readonly ProductPayloadBuilder $payloadBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getLabel(): string
    {
        return (string) __('OpenAI Agentic Commerce (ACP Products API)');
    }

    public function isAvailable(DeliveryContext $context): DeliveryResult
    {
        foreach ([
            'base_url' => __('No ACP API base URL is configured. OpenAI provides it during onboarding.'),
            'api_key' => __('No ACP API key is configured.'),
            'feed_id' => __('No ACP product feed id is configured.'),
        ] as $key => $message) {
            if ($context->getString($key) === '') {
                return DeliveryResult::skipped($message->render());
            }
        }

        if (!str_starts_with(strtolower($context->getString('base_url')), 'https://')) {
            // The request carries the API key; never send it in clear text.
            return DeliveryResult::skipped(__('The ACP API base URL must use https://.')->render());
        }

        if (!$context->feed->producesRecords()) {
            return DeliveryResult::skipped(
                __(
                    'The ACP API needs a field-mapped feed (JSONL, CSV or TSV) using the OpenAI column names; '
                    . 'a free-form XML template cannot be pushed.'
                )->render()
            );
        }

        return DeliveryResult::success('Available.');
    }

    /**
     * GET /product_feeds/{id}: proves the key works and the feed exists, and
     * writes nothing.
     */
    public function testConnection(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        try {
            [$status, $body] = $this->request($context, 'GET', $this->feedPath($context), null);
        } catch (\Throwable $e) {
            return DeliveryResult::error(__('Could not reach the ACP API: %1', $e->getMessage())->render());
        }

        if ($status === 200) {
            return DeliveryResult::success(
                __(
                    'Connected to ACP product feed "%1". Nothing was uploaded.',
                    (string) ($body['id'] ?? $context->getString('feed_id'))
                )->render()
            );
        }

        return DeliveryResult::error($this->explain($status, $body));
    }

    /**
     * After a run the push has already happened in finish(), so this reports it.
     * Called on its own - the deliver CLI, the retry cron - it re-sends the last
     * complete record set.
     */
    public function deliver(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        $feedId = (int) $context->feed->getFeedId();

        if (!isset($this->stats[$feedId])) {
            if (!$this->spool()->isExist($this->spoolPath($feedId))) {
                return DeliveryResult::skipped(
                    __('Nothing to send yet: the feed has not completed a run with this destination active.')->render()
                );
            }

            $this->contexts[$feedId] = $context;
            $this->sendSpool($context);
        }

        $stats = $this->stats[$feedId];

        if ($stats['products'] === 0 && $stats['failed'] === 0) {
            return DeliveryResult::skipped(__('No products were pushed - the feed produced nothing.')->render());
        }

        if ($stats['failed'] > 0) {
            return DeliveryResult::error(
                __(
                    'Pushed %1 products to the ACP API; %2 failed. First error: %3',
                    $stats['products'],
                    $stats['failed'],
                    $stats['errors'][0] ?? ''
                )->render(),
                $stats
            );
        }

        return DeliveryResult::success(
            __('Pushed %1 products (%2 variants) to the ACP API.', $stats['products'], $stats['variants'])->render(),
            $stats
        );
    }

    // =====================================================================
    // RecordConsumerInterface - fed live during the export
    // =====================================================================

    public function prepare(DeliveryContext $context): void
    {
        $feedId = (int) $context->feed->getFeedId();
        $this->contexts[$feedId] = $context;
        unset($this->stats[$feedId]);

        if ($context->feed->getData('cursor_position') === null) {
            $this->pendingReset[$feedId] = true;
        }
    }

    public function consume(Feed $feed, array $records): void
    {
        $feedId = (int) $feed->getFeedId();
        if (!isset($this->contexts[$feedId])) {
            $this->logger->warning(
                sprintf('Magenx_ProductFeed: ACP consumer for feed %d was never prepared; records dropped.', $feedId)
            );

            return;
        }

        $lines = '';
        foreach ($records as $record) {
            $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $lines .= $encoded . "\n";
            }
        }

        $spool = $this->spool();
        $part = $this->partPath($feedId);

        if (isset($this->pendingReset[$feedId])) {
            unset($this->pendingReset[$feedId]);
            $spool->create(self::SPOOL_DIR);
            if ($spool->isExist($part)) {
                $spool->delete($part);
            }
        }

        if ($lines === '') {
            return;
        }

        $stream = $spool->openFile($part, 'a');
        try {
            $stream->lock();
            $stream->write($lines);
        } finally {
            $stream->unlock();
            $stream->close();
        }
    }

    /**
     * Nothing to do per tick: the spool is already on disk.
     */
    public function flush(Feed $feed): void
    {
    }

    /**
     * The run completed: promote the spool to "last complete set" and send it.
     */
    public function finish(Feed $feed): void
    {
        $feedId = (int) $feed->getFeedId();
        $context = $this->contexts[$feedId] ?? null;
        if ($context === null) {
            return;
        }

        $spool = $this->spool();
        $part = $this->partPath($feedId);

        if (isset($this->pendingReset[$feedId]) || !$spool->isExist($part)) {
            // A run that produced no records: leave the previous set in place and
            // report an empty push rather than sending last time's data again.
            $this->stats[$feedId] = $this->emptyStats();

            return;
        }

        $spool->renameFile($part, $this->spoolPath($feedId));
        $this->sendSpool($context);
    }

    /**
     * Group the spool into products and PATCH them in batches.
     */
    private function sendSpool(DeliveryContext $context): void
    {
        $feedId = (int) $context->feed->getFeedId();
        $this->stats[$feedId] = $this->emptyStats();

        $path = $this->spool()->getAbsolutePath($this->spoolPath($feedId));
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, (int) ($context->get('batch_size') ?: self::DEFAULT_BATCH_SIZE)));

        try {
            $remaining = [];
            foreach ($this->readSpool($path) as $row) {
                $key = $this->payloadBuilder->groupKey($row);
                $remaining[$key] = ($remaining[$key] ?? 0) + 1;
            }

            $open = [];
            $batch = [];
            foreach ($this->readSpool($path) as $row) {
                $key = $this->payloadBuilder->groupKey($row);
                $open[$key][] = $row;

                if (--$remaining[$key] > 0) {
                    continue;
                }

                $batch[] = $this->payloadBuilder->buildProduct($open[$key]);
                unset($open[$key], $remaining[$key]);

                if (count($batch) >= $batchSize) {
                    $this->sendBatch($context, $batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->sendBatch($context, $batch);
            }
        } catch (\Throwable $e) {
            $this->stats[$feedId]['errors'][] = $e->getMessage();
            $this->stats[$feedId]['failed']++;
            $this->logger->error(
                sprintf('Magenx_ProductFeed: ACP push of feed "%s" failed: %s', $context->feed->getCode(), $e->getMessage())
            );
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function readSpool(string $path): \Generator
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction -- line-by-line streaming of a var/ spool file; the Magento file driver reads whole files.
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the ACP spool file.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
        // phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function sendBatch(DeliveryContext $context, array $products): void
    {
        $feedId = (int) $context->feed->getFeedId();
        $variants = array_sum(array_map(static fn (array $p): int => count($p['variants'] ?? []), $products));

        $body = ['products' => $products];
        $country = strtoupper($context->getString('target_country'));
        if ($country !== '') {
            $body = ['target_country' => $country] + $body;
        }

        try {
            [$status, $response] = $this->request($context, 'PATCH', $this->feedPath($context) . '/products', $body);
        } catch (\Throwable $e) {
            $this->recordFailure($feedId, count($products), $e->getMessage());

            return;
        }

        if ($status < 200 || $status >= 300) {
            $this->recordFailure($feedId, count($products), $this->explain($status, $response));

            return;
        }

        if (($response['accepted'] ?? true) === false) {
            $this->recordFailure($feedId, count($products), (string) __('The ACP API did not accept the batch.'));

            return;
        }

        $this->stats[$feedId]['products'] += count($products);
        $this->stats[$feedId]['variants'] += $variants;
    }

    private function recordFailure(int $feedId, int $count, string $message): void
    {
        $this->stats[$feedId]['failed'] += $count;
        if (count($this->stats[$feedId]['errors']) < 5) {
            $this->stats[$feedId]['errors'][] = $message;
        }

        $this->logger->error(sprintf('Magenx_ProductFeed: ACP products PATCH failed for feed %d: %s', $feedId, $message));
    }

    /**
     * One API call, retried on rate limiting, server errors and transport
     * failures. The same Idempotency-Key is reused across the attempts of one
     * call, so a retry of a request that did land is not applied twice.
     *
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(DeliveryContext $context, string $method, string $path, ?array $body): array
    {
        $url = rtrim($context->getString('base_url'), '/') . $path;
        $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $idempotencyKey = bin2hex(random_bytes(16));

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // A fresh client per attempt: Curl accumulates headers across calls.
            $curl = $this->curlFactory->create();
            $curl->setTimeout(120);
            $curl->addHeader('Authorization', 'Bearer ' . $context->getString('api_key'));
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('API-Version', $context->getString('api_version', self::DEFAULT_API_VERSION)
                ?: self::DEFAULT_API_VERSION);
            $curl->addHeader('Idempotency-Key', $idempotencyKey);
            $curl->addHeader('Request-Id', bin2hex(random_bytes(16)));
            $curl->addHeader('Timestamp', gmdate('Y-m-d\TH:i:s\Z'));

            try {
                if ($method === 'GET') {
                    $curl->get($url);
                } else {
                    $curl->setOptions([CURLOPT_CUSTOMREQUEST => $method]);
                    $curl->post($url, $payload);
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->wait($attempt, null);
                continue;
            }

            $status = $curl->getStatus();
            $decoded = json_decode((string) $curl->getBody(), true);
            $decoded = is_array($decoded) ? $decoded : [];

            if (($status === 429 || $status >= 500) && $attempt < self::MAX_ATTEMPTS) {
                $headers = array_change_key_case($curl->getHeaders(), CASE_LOWER);
                $this->wait($attempt, $headers['retry-after'] ?? null);
                continue;
            }

            return [$status, $decoded];
        }

        throw new \RuntimeException($lastError?->getMessage() ?? 'The ACP API could not be reached.');
    }

    private function wait(int $attempt, mixed $retryAfter): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return;
        }

        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : 2 ** $attempt;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- backoff between retries of an outbound API call, in a cron context.
        sleep(max(1, min(self::MAX_RETRY_WAIT_SECONDS, $seconds)));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function explain(int $status, array $body): string
    {
        $detail = trim((string) ($body['message'] ?? $body['error']['message'] ?? $body['type'] ?? ''));
        $suffix = $detail === '' ? '' : ' (' . $detail . ')';

        return match (true) {
            $status === 401, $status === 403 => (string) __('The ACP API rejected the API key%1.', $suffix),
            $status === 404 => (string) __(
                'The ACP API does not know this product feed%1. Check the feed id OpenAI issued.',
                $suffix
            ),
            $status === 400 => (string) __('The ACP API rejected the payload as invalid%1.', $suffix),
            $status === 429 => (string) __('The ACP API rate-limited the push%1. It will be retried.', $suffix),
            default => (string) __('The ACP API returned HTTP %1%2.', $status, $suffix),
        };
    }

    private function feedPath(DeliveryContext $context): string
    {
        return '/product_feeds/' . rawurlencode($context->getString('feed_id'));
    }

    private function spoolPath(int $feedId): string
    {
        return self::SPOOL_DIR . '/feed_' . $feedId . '.jsonl';
    }

    private function partPath(int $feedId): string
    {
        return $this->spoolPath($feedId) . '.part';
    }

    private function spool(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * @return array{products: int, variants: int, failed: int, errors: string[]}
     */
    private function emptyStats(): array
    {
        return ['products' => 0, 'variants' => 0, 'failed' => 0, 'errors' => []];
    }
}
