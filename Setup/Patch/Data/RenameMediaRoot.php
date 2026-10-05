<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Setup\Patch\Data;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magenx\ProductFeed\Model\Export\FeedFilesystem;
use Psr\Log\LoggerInterface;

/**
 * Moves published feeds from pub/media/magenx-feed/ to pub/media/magenx_feed/.
 *
 * The media root was renamed so every directory the module owns uses the same
 * underscore form as var/magenx_feed/ and the magenx_feed* tables. Without the
 * move, the files already published would stay reachable at the old URL as a
 * stale catalog dump until someone deleted them by hand, while the next
 * generation run wrote a fresh copy under the new root.
 *
 * Never fails setup:upgrade. If the old root is absent there is nothing to do;
 * if the new one already exists the two are not merged, and the old root is left
 * for the operator to remove. The next generation run republishes every feed
 * under the new root either way.
 */
class RenameMediaRoot implements DataPatchInterface
{
    private const LEGACY_MEDIA_ROOT = 'magenx-feed';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        try {
            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            if (!$media->isExist(self::LEGACY_MEDIA_ROOT)) {
                return $this;
            }
            if ($media->isExist(FeedFilesystem::MEDIA_ROOT)) {
                $this->logger->warning(sprintf(
                    'Magenx_ProductFeed: both pub/media/%s and pub/media/%s exist; '
                    . 'the old directory was left in place and can be removed once its feeds are republished.',
                    self::LEGACY_MEDIA_ROOT,
                    FeedFilesystem::MEDIA_ROOT
                ));
                return $this;
            }
            $media->renameFile(self::LEGACY_MEDIA_ROOT, FeedFilesystem::MEDIA_ROOT);
        } catch (FileSystemException $e) {
            $this->logger->warning(sprintf(
                'Magenx_ProductFeed: could not move pub/media/%s to pub/media/%s: %s',
                self::LEGACY_MEDIA_ROOT,
                FeedFilesystem::MEDIA_ROOT,
                $e->getMessage()
            ));
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
