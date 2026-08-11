<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Template\DocumentPlan;
use Magenx\ProductFeed\Model\Template\Requirements;

/**
 * Everything about a feed that is decided once per run rather than per product:
 * which mode it is in, what data it needs, and its compiled templates.
 *
 * Built by Runner::buildPlan() and then read-only for the rest of the run, which
 * is what guarantees the template is never recompiled inside the product loop.
 */
// phpcs:ignore Magento2.PHP.FinalImplementation.FoundFinal -- internal immutable value object, not an extension point; nothing extends it and no di.xml preference targets it.
final class ExportPlan
{
    /**
     * @param bool $isRecordMode True for a field-mapped feed (one record per
     *        product, servable to a push API); false for a free-form template.
     * @param array<string, array<int, array<string, mixed>>> $columns Column name
     *        => compiled expression, record mode only. Insertion order IS the
     *        column order of the output file.
     */
    public function __construct(
        public readonly bool $isRecordMode,
        public readonly Requirements $requirements,
        public readonly array $columns,
        public readonly DocumentPlan $document,
        public readonly string $alias
    ) {
    }
}
