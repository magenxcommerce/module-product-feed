<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * A template document taken apart for streaming.
 *
 * @see DocumentSplitter for why a feed template is split rather than rendered
 *      whole.
 */
// phpcs:ignore Magento2.PHP.FinalImplementation.FoundFinal -- internal immutable value object, not an extension point; nothing extends it and no di.xml preference targets it.
final class DocumentPlan
{
    /**
     * @param array<int, array<string, mixed>> $header Rendered once, before any product
     * @param array<int, array<string, mixed>> $item Rendered once per product
     * @param array<int, array<string, mixed>> $footer Rendered once, after the last product
     * @param string $alias Loop variable the item body binds each product to
     * @param bool $hasProductLoop False when the template is a bare per-item body
     */
    public function __construct(
        public readonly array $header,
        public readonly array $item,
        public readonly array $footer,
        public readonly string $alias,
        public readonly bool $hasProductLoop
    ) {
    }
}
