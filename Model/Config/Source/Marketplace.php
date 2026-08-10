<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Target channel.
 *
 * Advisory only - it selects a starter template and labels the feed in the grid;
 * it does not gate delivery. A merchant may legitimately send a "Google Shopping"
 * shaped feed to an aggregator that expects the same columns.
 */
class Marketplace implements OptionSourceInterface
{
    public const GOOGLE = 'google';
    public const META = 'meta';
    public const TIKTOK = 'tiktok';
    public const PINTEREST = 'pinterest';
    public const MICROSOFT = 'microsoft';
    public const AGENTIC = 'agentic';
    public const OTHER = 'other';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('-- None / generic --')],
            ['value' => self::GOOGLE, 'label' => __('Google Merchant Center')],
            ['value' => self::META, 'label' => __('Meta (Facebook / Instagram)')],
            ['value' => self::TIKTOK, 'label' => __('TikTok')],
            ['value' => self::PINTEREST, 'label' => __('Pinterest')],
            ['value' => self::MICROSOFT, 'label' => __('Microsoft Advertising (Bing)')],
            ['value' => self::AGENTIC, 'label' => __('AI / agentic shopping')],
            ['value' => self::OTHER, 'label' => __('Other')],
        ];
    }
}
