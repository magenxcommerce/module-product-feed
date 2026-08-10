<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Api\DelivererInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Shared behaviour for the file-transfer destinations (FTP, SFTP).
 *
 * THE TEST BUTTON MUST NOT WRITE ANYTHING. It verifies that the host is
 * reachable, that the credentials work, and that the configured path exists -
 * and then disconnects. Uploading a probe file is tempting because it also
 * proves write access, but the destination folder is usually watched by a feed
 * consumer, which then ingests the probe as a bogus feed; and if the probe is
 * copied from the module's own directory it publishes source code to a third
 * party. Neither failure is hypothetical.
 *
 * Failures are reported distinctly enough to act on: "could not connect" and
 * "connected but the path does not exist" send a merchant to completely
 * different settings, and collapsing them into "connection failed" is the
 * difference between a two-minute fix and a support ticket.
 */
abstract class AbstractTransferDeliverer implements DelivererInterface
{
    public function __construct(
        protected readonly FileDriver $fileDriver,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function deliver(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        try {
            if (!$this->fileDriver->isExists($context->absolutePath)) {
                return DeliveryResult::error(
                    __('The feed file has not been generated yet.')->render()
                );
            }

            $contents = $this->fileDriver->fileGetContents($context->absolutePath);
            $remoteName = $this->resolveRemoteName($context);

            $this->upload($context, $remoteName, $contents);

            return DeliveryResult::success(
                __('Uploaded %1 to %2.', $remoteName, $this->describeTarget($context))->render(),
                ['remote_name' => $remoteName]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'Magenx_ProductFeed: %s delivery of feed "%s" failed: %s',
                    static::class,
                    $context->feed->getCode(),
                    $e->getMessage()
                )
            );

            return DeliveryResult::error($e->getMessage());
        }
    }

    public function isAvailable(DeliveryContext $context): DeliveryResult
    {
        if ($context->getString('host') === '') {
            return DeliveryResult::skipped(__('No host is configured.')->render());
        }

        return DeliveryResult::success('Available.');
    }

    /**
     * The remote file name, which defaults to the local one.
     *
     * Some consumers require a fixed name regardless of how the local file is
     * dated, so this is separately configurable.
     */
    protected function resolveRemoteName(DeliveryContext $context): string
    {
        $configured = $context->getString('remote_filename');

        return $configured !== '' ? basename($configured) : $context->filename;
    }

    protected function describeTarget(DeliveryContext $context): string
    {
        $path = $context->getString('path');

        return $context->getString('host') . ($path === '' ? '' : ':' . $path);
    }

    /**
     * @throws \Throwable
     */
    abstract protected function upload(DeliveryContext $context, string $remoteName, string $contents): void;
}
