<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * CSV field enclosures, stored as codes rather than literal characters.
 *
 * See Delimiter for why: a literal double quote must never appear as a column
 * default in db_schema.xml, because the declarative schema emits DEFAULT "%s"
 * unescaped and the resulting DDL is rejected as multiple queries.
 */
class Enclosure implements OptionSourceInterface
{
    public const DOUBLE = 'double';
    public const SINGLE = 'single';
    public const NONE = 'none';

    private const CHARACTERS = [
        self::DOUBLE => '"',
        self::SINGLE => "'",
        self::NONE => '',
    ];

    /**
     * Resolve a stored code to the character to write, '' meaning no enclosure.
     *
     * Unknown values fall back to a double quote, which is what the overwhelming
     * majority of consumers expect - failing open to "no enclosure" would instead
     * silently corrupt every row containing the delimiter.
     */
    public static function toCharacter(string $code): string
    {
        if (array_key_exists($code, self::CHARACTERS)) {
            return self::CHARACTERS[$code];
        }

        // Tolerate a literal character stored directly by an older row.
        if ($code === '"' || $code === "'") {
            return $code;
        }

        return '"';
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DOUBLE, 'label' => __('Double quote')],
            ['value' => self::SINGLE, 'label' => __('Single quote')],
            ['value' => self::NONE, 'label' => __('None')],
        ];
    }
}
