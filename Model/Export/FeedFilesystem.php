<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Feed;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Where feed files live, and how they become visible.
 *
 * Layout:  pub/media/magenx-feed/<store_code>/<url_secret>/<filename>
 *
 * The secret path segment is not decoration. A feed is a complete
 * machine-readable dump of the catalog and may carry cost price, margin or
 * supplier SKU if the merchant maps them, so a guessable
 * /media/magenx-feed/products.xml is a competitor's afternoon.
 *
 * PUBLICATION IS AN ATOMIC RENAME, and that is why the work file is written in
 * the SAME directory as its final destination rather than in var/: rename() is
 * only atomic within one filesystem, and a partial file served to Google - or to
 * any consumer polling the URL - is worse than a stale one. A generation run
 * appends to "<filename>.part" for as many cron ticks as it needs, and the file
 * only becomes <filename> when the run completes.
 */
class FeedFilesystem
{
    /** Root directory under pub/media. */
    public const MEDIA_ROOT = 'magenx-feed';

    /**
     * Directory under var/ holding the Google service-account key.
     *
     * NEVER pub/media: that directory is web-served, and the key grants
     * programmatic access to the merchant's Merchant Center account. Same
     * reasoning as Magenx_Helpdesk's attachment storage.
     */
    public const VAR_ROOT = 'magenx_feed';
    public const GMC_KEY_DIR = self::VAR_ROOT . '/gmc';

    private const PART_SUFFIX = '.part';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Media-relative directory for a feed, e.g. magenx-feed/default/ab12.../
     *
     * @throws NoSuchEntityException
     */
    public function getRelativeDirectory(Feed $feed): string
    {
        $storeCode = $this->storeManager->getStore($feed->getStoreId())->getCode();

        return self::MEDIA_ROOT
            . '/' . $this->sanitizeSegment($storeCode)
            . '/' . $this->sanitizeSegment((string) $feed->getData('url_secret'));
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getRelativePath(Feed $feed, string $filename): string
    {
        return $this->getRelativeDirectory($feed) . '/' . $this->sanitizeSegment($filename);
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getWorkPath(Feed $feed, string $filename): string
    {
        return $this->getRelativePath($feed, $filename) . self::PART_SUFFIX;
    }

    /**
     * Absolute path, for deliverers that must hand a real file to an external
     * library (FTP upload, a stream read).
     *
     * @throws NoSuchEntityException
     * @throws FileSystemException
     */
    public function getAbsolutePath(Feed $feed, string $filename): string
    {
        return $this->getMediaWriter()->getAbsolutePath($this->getRelativePath($feed, $filename));
    }

    /**
     * Publicly fetchable URL of the published file. This is what gets registered
     * with a marketplace as the fetch target.
     *
     * @throws NoSuchEntityException
     */
    public function getPublicUrl(Feed $feed, string $filename): string
    {
        $store = $this->storeManager->getStore($feed->getStoreId());
        $base = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');

        return $base . '/' . $this->getRelativePath($feed, $filename);
    }

    /**
     * Start a run: create the directory and truncate any leftover work file.
     *
     * @throws NoSuchEntityException
     * @throws FileSystemException
     */
    public function beginWork(Feed $feed, string $filename): void
    {
        $writer = $this->getMediaWriter();
        $writer->create($this->getRelativeDirectory($feed));

        $work = $this->getWorkPath($feed, $filename);
        if ($writer->isExist($work)) {
            $writer->delete($work);
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws FileSystemException
     */
    public function appendWork(Feed $feed, string $filename, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $writer = $this->getMediaWriter();
        $stream = $writer->openFile($this->getWorkPath($feed, $filename), 'a');
        try {
            $stream->lock();
            $stream->write($chunk);
        } finally {
            $stream->unlock();
            $stream->close();
        }
    }

    /**
     * Make the work file the live file.
     *
     * Same-directory rename, so a consumer polling the URL sees either the
     * previous complete file or the new complete file, never a half-written one.
     *
     * @throws NoSuchEntityException
     * @throws FileSystemException
     * @throws LocalizedException
     */
    public function publish(Feed $feed, string $filename): string
    {
        $writer = $this->getMediaWriter();
        $work = $this->getWorkPath($feed, $filename);
        $final = $this->getRelativePath($feed, $filename);

        if (!$writer->isExist($work)) {
            throw new LocalizedException(
                __('Nothing to publish: the work file for feed "%1" is missing.', $feed->getCode())
            );
        }

        $writer->renameFile($work, $final);

        return $final;
    }

    /**
     * @throws NoSuchEntityException
     * @throws FileSystemException
     */
    public function discardWork(Feed $feed, string $filename): void
    {
        $writer = $this->getMediaWriter();
        $work = $this->getWorkPath($feed, $filename);

        if ($writer->isExist($work)) {
            $writer->delete($work);
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws FileSystemException
     */
    public function isPublished(Feed $feed, string $filename): bool
    {
        return $this->getMediaWriter()->isExist($this->getRelativePath($feed, $filename));
    }

    /**
     * @throws FileSystemException
     */
    public function getFileSize(string $relativePath): int
    {
        $writer = $this->getMediaWriter();
        if (!$writer->isExist($relativePath)) {
            return 0;
        }

        return (int) ($writer->stat($relativePath)['size'] ?? 0);
    }

    /**
     * Absolute path of the Google service-account key inside var/.
     *
     * The configured value is a file NAME; it is basename()d here so a traversal
     * in that config value cannot escape var/magenx_feed/gmc/.
     *
     * @throws FileSystemException
     */
    public function getGoogleKeyPath(string $configuredFileName): ?string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- string-level basename on a generated feed filename.
        $name = basename(trim($configuredFileName));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $varWriter = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = self::GMC_KEY_DIR . '/' . $name;

        if (!$varWriter->isExist($relative)) {
            return null;
        }

        return $varWriter->getAbsolutePath($relative);
    }

    /**
     * @throws FileSystemException
     */
    private function getMediaWriter(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * One path segment: no separators, no traversal, no leading dot.
     */
    private function sanitizeSegment(string $segment): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- string-level basename on a generated feed filename.
        $segment = basename(trim($segment));
        $segment = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $segment);
        $segment = ltrim($segment, '.');

        return $segment === '' ? 'feed' : $segment;
    }
}
