<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Three states, not a Yes/No: the agentic-commerce feed distinguishes "does not
 * accept returns" from "not stated", and a Yes/No field cannot say the second.
 */
class AcceptsReturns implements OptionSourceInterface
{
    public const UNSPECIFIED = '';
    public const YES = 'yes';
    public const NO = 'no';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::UNSPECIFIED, 'label' => __('Not stated')],
            ['value' => self::YES, 'label' => __('Yes')],
            ['value' => self::NO, 'label' => __('No (final sale)')],
        ];
    }
}
