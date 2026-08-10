<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

/**
 * Outcome of one generation tick.
 *
 * `completed` false is a normal, successful result: it means the tick used its
 * time budget and the run will continue where it left off. Only `failed`
 * indicates something went wrong.
 */
final class RunResult
{
    public function __construct(
        public readonly bool $completed,
        public readonly bool $failed,
        public readonly int $productCount,
        public readonly int $durationMs,
        public readonly string $message = '',
        public readonly ?string $publishedPath = null,
        public readonly bool $skipped = false
    ) {
    }

    public static function progressed(int $count, int $durationMs): self
    {
        return new self(false, false, $count, $durationMs, 'Partially generated; will continue on the next run.');
    }

    public static function finished(int $count, int $durationMs, string $publishedPath): self
    {
        return new self(true, false, $count, $durationMs, 'Feed generated.', $publishedPath);
    }

    public static function error(string $message, int $durationMs, int $count = 0): self
    {
        return new self(false, true, $count, $durationMs, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(false, false, 0, 0, $message, null, true);
    }
}
