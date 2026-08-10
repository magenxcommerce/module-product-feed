<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

/**
 * Outcome of a delivery attempt.
 *
 * `skipped` is a first-class outcome, distinct from both success and failure: an
 * optional SDK that is not installed, or credentials not yet filled in, is a
 * configuration state rather than an error, and reporting it as a failure trains
 * merchants to ignore red feeds.
 */
final class DeliveryResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $details
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $details = []
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function success(string $message, array $details = []): self
    {
        return new self(self::STATUS_SUCCESS, $message, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $message, array $details = []): self
    {
        return new self(self::STATUS_ERROR, $message, $details);
    }

    public static function skipped(string $message): self
    {
        return new self(self::STATUS_SKIPPED, $message);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }
}
