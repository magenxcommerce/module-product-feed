<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magento\Framework\Filesystem\Io\Sftp;

/**
 * SFTP delivery, through Magento's own Io\Sftp adapter.
 *
 * Core's adapter is used rather than phpseclib directly, so this module needs no
 * composer dependency of its own and inherits whichever phpseclib version the
 * platform ships.
 */
class SftpDeliverer extends AbstractTransferDeliverer
{
    private const DEFAULT_PORT = 22;

    public function getLabel(): string
    {
        return (string) __('SFTP');
    }

    public function testConnection(DeliveryContext $context): DeliveryResult
    {
        $available = $this->isAvailable($context);
        if (!$available->isSuccess()) {
            return $available;
        }

        $connection = null;

        try {
            $connection = $this->connect($context);
        } catch (\Throwable $e) {
            return DeliveryResult::error(
                __('Could not sign in to %1: %2', $context->getString('host'), $e->getMessage())->render()
            );
        }

        try {
            $path = $context->getString('path');
            if ($path !== '' && !$connection->cd($path)) {
                // Distinct from the sign-in failure above on purpose: the credentials
                // are fine and only the path is wrong, which is a different fix.
                return DeliveryResult::error(
                    __('Signed in, but the path "%1" does not exist on the server.', $path)->render()
                );
            }

            return DeliveryResult::success(
                __('Connected to %1 successfully. Nothing was uploaded.', $this->describeTarget($context))->render()
            );
        } catch (\Throwable $e) {
            return DeliveryResult::error($e->getMessage());
        } finally {
            $this->closeQuietly($connection);
        }
    }

    protected function upload(DeliveryContext $context, string $remoteName, string $contents): void
    {
        $connection = $this->connect($context);

        try {
            $path = $context->getString('path');
            if ($path !== '' && !$connection->cd($path)) {
                throw new \RuntimeException(
                    (string) __('The path "%1" does not exist on the server.', $path)
                );
            }

            $connection->write($remoteName, $contents);
        } finally {
            $this->closeQuietly($connection);
        }
    }

    private function connect(DeliveryContext $context): Sftp
    {
        $host = $context->getString('host');
        $port = (int) ($context->get('port') ?: self::DEFAULT_PORT);

        $connection = new Sftp();
        $connection->open([
            // Io\Sftp expects host:port in one string.
            'host' => str_contains($host, ':') ? $host : $host . ':' . $port,
            'username' => $context->getString('username'),
            'password' => $context->getString('password'),
            'timeout' => Sftp::REMOTE_TIMEOUT,
        ]);

        return $connection;
    }

    private function closeQuietly(?Sftp $connection): void
    {
        if ($connection === null) {
            return;
        }

        try {
            $connection->close();
        } catch (\Throwable) {
            // The transfer's outcome is already decided; a failure to hang up is not
            // worth overwriting it with.
        }
    }
}
