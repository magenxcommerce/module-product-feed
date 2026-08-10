<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Export\Writer\WriterInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Format code -> writer, populated from di.xml.
 *
 * Validates its contents on construction rather than on first use: a
 * mis-registered writer should fail loudly at object creation, not halfway
 * through an export that has already published a partial file.
 */
class WriterPool
{
    /**
     * @param array<string, WriterInterface> $writers
     * @throws LocalizedException
     */
    public function __construct(
        private readonly array $writers = []
    ) {
        foreach ($this->writers as $code => $writer) {
            if (!$writer instanceof WriterInterface) {
                throw new LocalizedException(
                    __('Feed writer "%1" must implement %2.', $code, WriterInterface::class)
                );
            }
        }
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $format): WriterInterface
    {
        if (!isset($this->writers[$format])) {
            throw new LocalizedException(
                __('No feed writer is registered for format "%1".', $format)
            );
        }

        return $this->writers[$format];
    }

    public function has(string $format): bool
    {
        return isset($this->writers[$format]);
    }

    /**
     * @return string[]
     */
    public function getFormats(): array
    {
        return array_keys($this->writers);
    }
}
