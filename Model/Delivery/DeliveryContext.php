<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Model\Feed;

/**
 * Everything a deliverer is given: the feed, its decrypted settings, and where
 * the published file is.
 *
 * SECRETS ARE DECRYPTED HERE AND NOWHERE ELSE. The `config` column stores
 * credentials encrypted; DeliveryManager decrypts them into this object for the
 * duration of one attempt. A deliverer therefore never touches the encryptor,
 * and - just as important - a credential never ends up in a log line by way of
 * some deliverer dumping its whole config for debugging. Keep __debugInfo()
 * below in place for that reason.
 */
// phpcs:ignore Magento2.PHP.FinalImplementation.FoundFinal -- internal immutable value object, not an extension point; nothing extends it and no di.xml preference targets it.
final class DeliveryContext
{
    /**
     * @param array<string, mixed> $settings Decrypted deliverer settings
     */
    public function __construct(
        public readonly Feed $feed,
        public readonly array $settings,
        public readonly string $relativePath,
        public readonly string $absolutePath,
        public readonly string $publicUrl,
        public readonly string $filename,
        public readonly int $productCount = 0
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : (bool) $value;
    }

    /**
     * Keep credentials out of var_dump(), print_r() and any exception renderer
     * that walks object properties.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'feed' => $this->feed->getCode(),
            'filename' => $this->filename,
            'settings' => '[redacted]',
        ];
    }
}
