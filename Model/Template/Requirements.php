<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * What a template actually asks for.
 *
 * This is the object that keeps the export fast. The DataLoader issues one query
 * per data group per batch, and each of those queries is skipped entirely when
 * the template never references it - so a feed of sku/name/price costs three
 * queries per batch, not the eleven a "load everything" export would pay.
 */
final class Requirements
{
    /**
     * @param string[] $attributes EAV attribute codes to select on the collection
     */
    public function __construct(
        public readonly array $attributes = [],
        public readonly bool $needsPrices = false,
        public readonly bool $needsStock = false,
        public readonly bool $needsCategories = false,
        public readonly bool $needsGallery = false,
        public readonly bool $needsParent = false,
        public readonly bool $needsReviews = false,
        public readonly bool $needsTierPrices = false,
        public readonly bool $needsInventorySources = false,
        public readonly bool $needsConfigurableAttributes = false,
        public readonly bool $needsUrl = false
    ) {
    }
}
