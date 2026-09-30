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
 *
 * Password or SSH key. Io\Sftp hands its `password` argument straight to
 * phpseclib3's SFTP::login(), which accepts a loaded private key in the same
 * position - so key authentication (what the OpenAI feed SFTP endpoint and most
 * marketplace drop boxes issue) needs no adapter of its own. Settings:
 *   private_key            PEM / OpenSSH private key text (encrypted at rest)
 *   private_key_password   its passphrase, if any (encrypted at rest)
 * When a key is set it is used and `password` is ignored.
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
            'password' => $this->resolveCredential($context),
            'timeout' => Sftp::REMOTE_TIMEOUT,
        ]);

        return $connection;
    }

    /**
     * The private key when one is configured, the password otherwise.
     *
     * The key is read with the raw setting, not getString(): trimming is harmless
     * for PEM, but a key pasted through a form can arrive with literal "\n"
     * sequences instead of line breaks, which phpseclib then rejects as "unable
     * to read key" - so those are restored first.
     *
     * @return string|object A password string, or a phpseclib3 private key
     */
    private function resolveCredential(DeliveryContext $context): mixed
    {
        $key = $context->getString('private_key');
        if ($key === '') {
            return $context->getString('password');
        }

        if (!class_exists(\phpseclib3\Crypt\PublicKeyLoader::class)) {
            throw new \RuntimeException(
                (string) __('SSH key authentication needs phpseclib 3, which this Magento installation does not ship.')
            );
        }

        if (!str_contains($key, "\n") && str_contains($key, '\\n')) {
            $key = str_replace('\\n', "\n", $key);
        }

        $passphrase = $context->getString('private_key_password');

        try {
            return \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($key, $passphrase === '' ? false : $passphrase);
        } catch (\Throwable $e) {
            // Never echo the key material itself: the message is persisted on the
            // delivery row and shown in the admin.
            throw new \RuntimeException(
                (string) __('The SSH private key could not be read (%1). Check the key and its passphrase.', get_class($e)),
                0,
                $e
            );
        }
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
