<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magento\Framework\Filesystem\Io\Ftp;

/**
 * Plain FTP delivery, through Magento's own Io\Ftp adapter.
 *
 * Offered because a large share of comparison-shopping engines and affiliate
 * networks still accept nothing else. Prefer SFTP wherever the destination
 * supports it: FTP sends the credentials in clear text, and the admin form says
 * so rather than leaving a merchant to assume otherwise.
 *
 * Requires ext-ftp, which is a `suggest` rather than a hard requirement - a store
 * that only uses SFTP or the API destinations should not need it installed.
 */
class FtpDeliverer extends AbstractTransferDeliverer
{
    private const DEFAULT_PORT = 21;

    public function getLabel(): string
    {
        return (string) __('FTP (unencrypted - prefer SFTP where available)');
    }

    public function isAvailable(DeliveryContext $context): DeliveryResult
    {
        if (!function_exists('ftp_connect')) {
            return DeliveryResult::skipped(
                __('FTP delivery needs the PHP ext-ftp extension, which is not installed on this server.')->render()
            );
        }

        return parent::isAvailable($context);
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

    private function connect(DeliveryContext $context): Ftp
    {
        $connection = new Ftp();
        $connection->open([
            'host' => $context->getString('host'),
            'port' => (int) ($context->get('port') ?: self::DEFAULT_PORT),
            'user' => $context->getString('username'),
            'password' => $context->getString('password'),
            'ssl' => $context->getBool('ssl'),
            // Passive mode is the default because it is what works through the
            // firewalls and NAT most stores sit behind; active mode requires the
            // server to open a connection back to us.
            'passive' => $context->getBool('passive', true),
            'timeout' => 30,
        ]);

        return $connection;
    }

    private function closeQuietly(?Ftp $connection): void
    {
        if ($connection === null) {
            return;
        }

        try {
            $connection->close();
        } catch (\Throwable) {
            // See SftpDeliverer.
        }
    }
}
