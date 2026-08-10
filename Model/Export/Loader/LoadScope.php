<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

/**
 * Everything a batch loader needs to know about the batch it is loading.
 *
 * Passed rather than re-derived per loader: resolving the website for a store, or
 * the sku list for a set of ids, once per loader would put five redundant
 * lookups into every batch.
 */
final class LoadScope
{
    /**
     * @param int[] $productIds
     * @param array<int, string> $skusById Needed by loaders keyed on sku rather than id (MSI)
     */
    public function __construct(
        public readonly array $productIds,
        public readonly array $skusById,
        public readonly int $storeId,
        public readonly int $websiteId,
        public readonly string $mediaBaseUrl,
        public readonly int $customerGroupId = 0
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->productIds === [];
    }
}
