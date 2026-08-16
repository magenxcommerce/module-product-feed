<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Config;
use Magenx\ProductFeed\Model\Export\Loader\LoadScope;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\FeedHistory;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magenx\ProductFeed\Model\Template\DocumentPlan;
use Magenx\ProductFeed\Model\Template\DocumentSplitter;
use Magenx\ProductFeed\Model\Template\RenderContext;
use Magenx\ProductFeed\Model\Template\Requirements;
use Magenx\ProductFeed\Model\Template\TemplateEngine;
use Magenx\ProductFeed\Model\Validation\ValidationReport;
use Magenx\ProductFeed\Model\Validation\Validator;
use Magento\Framework\App\Area;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates one feed, across as many ticks as it takes.
 *
 * Five things here are load-bearing:
 *
 * 1. LOCKING uses Magento's DB LockManager, not a lock file. A file lock fails
 *    outright on network-mounted filesystems (NFS, AWS EFS) - taking every export
 *    on the host down with it - and a crash leaves it behind, wedging the feature
 *    until someone deletes it by hand. A DB lock is session-bound: a crashed
 *    process releases it.
 *
 * 2. STORE EMULATION wraps the whole run. Product URLs and image URLs resolve
 *    against the ADMIN store in a cron or CLI context and come out wrong - a
 *    silent, feed-wide defect that looks fine when generated from the admin and
 *    breaks when the same feed runs on cron.
 *
 * 3. THE RUN IS RESUMABLE. It appends to a .part file and records how far it got;
 *    when the time budget runs out it returns without publishing and the next
 *    tick continues. A catalog larger than one cron window still completes.
 *
 * 4. PUBLICATION IS AN ATOMIC RENAME, done once at the end. A consumer polling
 *    the URL sees the previous complete file or the new complete file, never a
 *    half-written one.
 *
 * 5. THE TEMPLATE IS COMPILED AND ANALYSED ONCE PER RUN, never per product, and
 *    what it references decides which loaders run at all.
 */
class Runner
{
    private const LOCK_PREFIX = 'magenx_product_feed_';

    /**
     * Lock is taken with a zero timeout: if another process holds it, this tick
     * skips rather than queues. Two cron ticks waiting on each other is how a
     * cron group backs up.
     */
    private const LOCK_TIMEOUT = 0;

    public function __construct(
        private readonly Config $config,
        private readonly CollectionBuilder $collectionBuilder,
        private readonly DataLoader $dataLoader,
        private readonly TemplateEngine $templateEngine,
        private readonly DocumentSplitter $documentSplitter,
        private readonly WriterPool $writerPool,
        private readonly FeedFilesystem $feedFilesystem,
        private readonly FeedResource $feedResource,
        private readonly FeedHistory $feedHistory,
        private readonly Validator $validator,
        private readonly LockManagerInterface $lockManager,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param RecordConsumerInterface[] $consumers Push destinations fed live during the run
     */
    public function run(Feed $feed, array $consumers = []): RunResult
    {
        $feedId = $feed->getFeedId();
        if ($feedId === null) {
            return RunResult::error('Feed has not been saved yet.', 0);
        }

        $lockName = self::LOCK_PREFIX . $feedId;

        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            return RunResult::skipped('Another generation of this feed is already running.');
        }

        $startedAt = microtime(true);

        try {
            return $this->execute($feed, $consumers, $startedAt);
        } catch (\Throwable $e) {
            $duration = $this->elapsedMs($startedAt);

            $this->logger->error(
                sprintf('Magenx_ProductFeed: feed "%s" failed: %s', $feed->getCode(), $e->getMessage()),
                ['exception' => $e]
            );

            $this->discardQuietly($feed);
            $this->feedResource->updateRunState($feedId, [
                'status' => Feed::STATUS_ERROR,
                'last_error' => $e->getMessage(),
                'cursor_position' => null,
            ]);
            $this->feedHistory->record($feedId, FeedHistory::TYPE_GENERATE, 'error', $e->getMessage(), null, $duration);

            return RunResult::error($e->getMessage(), $duration);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * @param RecordConsumerInterface[] $consumers
     */
    private function execute(Feed $feed, array $consumers, float $startedAt): RunResult
    {
        $feedId = (int) $feed->getFeedId();
        $storeId = $feed->getStoreId();
        $store = $this->storeManager->getStore($storeId);

        $plan = $this->buildPlan($feed);
        $filename = $this->resolveFilename($feed);

        $isResuming = $feed->getData('cursor_position') !== null;
        $cursor = (int) $feed->getData('cursor_position');
        $written = $isResuming ? (int) $feed->getData('product_count') : 0;

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        try {
            if (!$isResuming) {
                $this->feedFilesystem->beginWork($feed, $filename);
                $this->feedResource->updateRunState($feedId, [
                    'status' => Feed::STATUS_PROCESSING,
                    'last_error' => null,
                ]);

                $header = $this->renderHeader($feed, $plan, $store->getBaseCurrencyCode());
                if ($header !== '') {
                    $this->feedFilesystem->appendWork($feed, $filename, $header);
                }
            }

            $scopeTemplate = [
                'storeId' => $storeId,
                'websiteId' => (int) $store->getWebsiteId(),
                'mediaBaseUrl' => $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                'currency' => (string) $store->getBaseCurrencyCode(),
            ];

            $batchSize = $this->config->getBatchSize($storeId);
            $deadline = $startedAt + $this->config->getMaxExecutionSeconds($storeId);
            $completed = true;

            // Validation only makes sense in record mode: a free-form XML template
            // has no per-column record to check a rule against. The report is kept
            // in memory for THIS tick only and discarded if the tick does not
            // complete the run - a catalog needing several cron ticks reports only
            // the last tick's batches. Accepted: it mirrors how the TYPE_GENERATE
            // history row itself is only written on completion, not per tick, and
            // avoids persisting partial findings across ticks (which would need a
            // schema change).
            $validationRules = $feed->getValidationRules();
            $shouldValidate = $plan->isRecordMode
                && $validationRules !== []
                && $this->config->shouldValidateAfterGeneration($storeId);
            $validationReport = $shouldValidate ? new ValidationReport() : null;

            while (true) {
                $collection = $this->collectionBuilder->createPage($feed, $plan->requirements, $cursor, $batchSize);
                $products = array_values($collection->getItems());

                if ($products === []) {
                    break;
                }

                $chunk = $this->processBatch(
                    $feed,
                    $plan,
                    $products,
                    $scopeTemplate,
                    $consumers,
                    $validationRules,
                    $validationReport
                );
                $this->feedFilesystem->appendWork($feed, $filename, $chunk);

                $written += count($products);
                $cursor = (int) end($products)->getId();

                $this->feedResource->updateRunState($feedId, [
                    'cursor_position' => $cursor,
                    'product_count' => $written,
                ]);

                // Free the collection's identity map before the next page: without
                // this a long run accumulates every product it has already written.
                $collection->clear();
                unset($products, $collection);

                if (microtime(true) >= $deadline) {
                    $completed = false;
                    break;
                }
            }

            if (!$completed) {
                foreach ($consumers as $consumer) {
                    $consumer->flush($feed);
                }

                return RunResult::progressed($written, $this->elapsedMs($startedAt));
            }

            $footer = $this->renderFooter($feed, $plan, $store->getBaseCurrencyCode());
            if ($footer !== '') {
                $this->feedFilesystem->appendWork($feed, $filename, $footer);
            }

            foreach ($consumers as $consumer) {
                $consumer->flush($feed);
                $consumer->finish($feed);
            }

            $published = $this->feedFilesystem->publish($feed, $filename);
            $duration = $this->elapsedMs($startedAt);

            $this->feedResource->updateRunState($feedId, [
                'status' => Feed::STATUS_READY,
                'product_count' => $written,
                'generation_time' => $duration,
                'last_generated_at' => $this->dateTime->gmtDate(),
                // Recorded so delivery targets the file that was actually written.
                // Re-rendering a date-stamped filename at delivery time would look
                // for tomorrow's file on a run that finished just before midnight.
                'last_filename' => $filename,
                'cursor_position' => null,
                'last_error' => null,
            ]);

            $this->feedHistory->record(
                $feedId,
                FeedHistory::TYPE_GENERATE,
                'success',
                sprintf('Generated %d products.', $written),
                $written,
                $duration
            );

            if ($validationReport !== null) {
                $status = match (true) {
                    $validationReport->hasErrors() => 'error',
                    $validationReport->countBySeverity(Validator::SEVERITY_WARNING) > 0 => 'warning',
                    default => 'success',
                };

                $this->feedHistory->record(
                    $feedId,
                    FeedHistory::TYPE_VALIDATE,
                    $status,
                    $validationReport->summarize(),
                    $written,
                    null,
                    $validationReport->toArray()
                );
            }

            return RunResult::finished($written, $duration, $published);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @param \Magento\Catalog\Model\Product[] $products
     * @param array<string, mixed> $scopeTemplate
     * @param RecordConsumerInterface[] $consumers
     * @param array<int, array<string, mixed>> $validationRules
     */
    private function processBatch(
        Feed $feed,
        ExportPlan $plan,
        array $products,
        array $scopeTemplate,
        array $consumers,
        array $validationRules = [],
        ?ValidationReport $validationReport = null
    ): string {
        $productIds = [];
        $skusById = [];
        foreach ($products as $product) {
            $id = (int) $product->getId();
            $productIds[] = $id;
            $skusById[$id] = (string) $product->getSku();
        }

        $scope = new LoadScope(
            $productIds,
            $skusById,
            $scopeTemplate['storeId'],
            $scopeTemplate['websiteId'],
            $scopeTemplate['mediaBaseUrl']
        );

        $records = $this->dataLoader->buildRecords($products, $plan->requirements, $scope);

        return $plan->isRecordMode
            ? $this->renderRecords($feed, $plan, $records, $scopeTemplate, $consumers, $validationRules, $validationReport)
            : $this->renderTemplateItems($feed, $plan, $records, $scopeTemplate);
    }

    /**
     * Record mode: each product becomes a flat map of column => value, which the
     * writer serializes AND which push deliverers consume directly. This is the
     * reason records exist as a stage of their own - a push destination must never
     * be fed by parsing a generated file back apart.
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $scopeTemplate
     * @param RecordConsumerInterface[] $consumers
     * @param array<int, array<string, mixed>> $validationRules
     */
    private function renderRecords(
        Feed $feed,
        ExportPlan $plan,
        array $records,
        array $scopeTemplate,
        array $consumers,
        array $validationRules = [],
        ?ValidationReport $validationReport = null
    ): string {
        $writer = $this->writerPool->get($feed->getFormat());
        $out = '';
        $batch = [];

        foreach ($records as $record) {
            $context = $this->createContext($record, $scopeTemplate, $plan->alias);

            $row = [];
            foreach ($plan->columns as $column => $nodes) {
                $row[$column] = $this->templateEngine->renderCompiled($nodes, $context);
            }

            if ($validationReport !== null) {
                $this->validator->validateRecord($validationRules, $row, $validationReport);
            }

            $batch[] = $row;
            $out .= $writer->writeRecord($feed, $row, array_keys($plan->columns));
        }

        foreach ($consumers as $consumer) {
            $consumer->consume($feed, $batch);
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $scopeTemplate
     */
    private function renderTemplateItems(
        Feed $feed,
        ExportPlan $plan,
        array $records,
        array $scopeTemplate
    ): string {
        $out = '';

        foreach ($records as $record) {
            $context = $this->createContext($record, $scopeTemplate, $plan->alias);
            $out .= $this->templateEngine->renderCompiled($plan->document->item, $context);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $scopeTemplate
     */
    private function createContext(array $record, array $scopeTemplate, string $alias): RenderContext
    {
        return new RenderContext(
            [
                $alias => $record,
                // `product` is always bound as well as the loop alias, so a template
                // that says {{ product.sku }} inside {% for item in ... %} still works.
                'product' => $record,
                'context' => [
                    'reviews' => $record['reviews'] ?? [],
                    'categories' => $record['categories'] ?? [],
                    'date' => $this->dateTime->gmtDate('Y-m-d'),
                    'time' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
                ],
            ],
            (string) $scopeTemplate['currency'],
            (int) $scopeTemplate['storeId']
        );
    }

    private function renderHeader(Feed $feed, ExportPlan $plan, string $currency): string
    {
        if ($plan->isRecordMode) {
            return $this->writerPool->get($feed->getFormat())->open($feed, array_keys($plan->columns));
        }

        return $this->templateEngine->renderCompiled(
            $plan->document->header,
            new RenderContext(['context' => $this->documentContext()], $currency, $feed->getStoreId())
        );
    }

    private function renderFooter(Feed $feed, ExportPlan $plan, string $currency): string
    {
        if ($plan->isRecordMode) {
            return $this->writerPool->get($feed->getFormat())->close($feed);
        }

        return $this->templateEngine->renderCompiled(
            $plan->document->footer,
            new RenderContext(['context' => $this->documentContext()], $currency, $feed->getStoreId())
        );
    }

    /**
     * @return array<string, string>
     */
    private function documentContext(): array
    {
        return [
            'date' => $this->dateTime->gmtDate('Y-m-d'),
            'time' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Compile the template (or field map) once, and work out what data it needs.
     */
    private function buildPlan(Feed $feed): ExportPlan
    {
        $fieldMap = $feed->getFieldMap();
        $template = (string) $feed->getData('template');

        // Record mode when there is a field map and no template body. A feed with
        // both is treated as template-driven: the template is the more specific
        // instruction, and silently ignoring it would be worse than ignoring a map
        // the merchant may simply have left behind.
        $isRecordMode = $fieldMap !== [] && trim($template) === '';

        if ($isRecordMode) {
            $columns = [];
            $sources = [];
            foreach ($fieldMap as $row) {
                $column = trim((string) ($row['column'] ?? ''));
                $value = (string) ($row['value'] ?? '');
                if ($column === '') {
                    continue;
                }
                $columns[$column] = $this->templateEngine->compile($value);
                $sources[] = $value;
            }

            return new ExportPlan(
                true,
                $this->templateEngine->analyzeAll($sources),
                $columns,
                new DocumentPlan([], [], [], 'product', false),
                'product'
            );
        }

        $document = $this->documentSplitter->split($this->templateEngine->compile($template));

        return new ExportPlan(
            false,
            $this->templateEngine->analyze($template),
            [],
            $document,
            $document->alias
        );
    }

    /**
     * The file name is itself a template, so a merchant can date-stamp it.
     */
    private function resolveFilename(Feed $feed): string
    {
        $raw = trim((string) $feed->getData('filename'));
        if ($raw === '') {
            $raw = $feed->getCode() . '.' . $feed->getFormat();
        }

        if (!str_contains($raw, '{{')) {
            return $raw;
        }

        $rendered = $this->templateEngine->render(
            $raw,
            new RenderContext(['context' => $this->documentContext()], '', $feed->getStoreId())
        );

        return trim($rendered) === '' ? $feed->getCode() . '.' . $feed->getFormat() : trim($rendered);
    }

    private function discardQuietly(Feed $feed): void
    {
        try {
            $this->feedFilesystem->discardWork($feed, $this->resolveFilename($feed));
        } catch (\Throwable) {
            // Best effort: the run has already failed and is being reported.
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
