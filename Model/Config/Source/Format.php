<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Config\Source;

use Magenx\ProductFeed\Model\Feed;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Output formats.
 *
 * The labels say which formats can feed a push catalog API, because that is not
 * inferable and choosing XML here is what silently makes the Meta destination
 * unusable later.
 */
class Format implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Feed::FORMAT_XML, 'label' => __('XML (template-driven)')],
            ['value' => Feed::FORMAT_CSV, 'label' => __('CSV (field-mapped - can push to catalog APIs)')],
            ['value' => Feed::FORMAT_TSV, 'label' => __('TSV (field-mapped - can push to catalog APIs)')],
            ['value' => Feed::FORMAT_JSONL, 'label' => __('JSONL (field-mapped - can push to catalog APIs)')],
        ];
    }
}
