<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * CSV field delimiters, stored as codes rather than literal characters.
 *
 * A tab cannot be typed into a text field, which alone justifies a select - but
 * the stronger reason is that the literal characters must never reach
 * db_schema.xml as a column default. Magento's declarative schema emits
 * DEFAULT "%s" with no escaping, so a double quote there unbalances the whole
 * CREATE TABLE and setup:upgrade fails with "Multiple queries can't be
 * executed". Codes keep the DDL free of quoting characters entirely.
 */
class Delimiter implements OptionSourceInterface
{
    public const COMMA = 'comma';
    public const SEMICOLON = 'semicolon';
    public const TAB = 'tab';
    public const PIPE = 'pipe';
    public const COLON = 'colon';
    public const SPACE = 'space';

    private const CHARACTERS = [
        self::COMMA => ',',
        self::SEMICOLON => ';',
        self::TAB => "\t",
        self::PIPE => '|',
        self::COLON => ':',
        self::SPACE => ' ',
    ];

    /**
     * Resolve a stored code to the character to write.
     *
     * Falls back to a comma for an unknown value, including the literal
     * characters an older row may hold - a feed must keep generating rather than
     * fail on a value it does not recognise.
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- named constructor on an immutable value object; not an interception point.
    public static function toCharacter(string $code): string
    {
        if (isset(self::CHARACTERS[$code])) {
            return self::CHARACTERS[$code];
        }

        // Tolerate a literal character stored directly.
        if ($code !== '' && strlen($code) <= 2 && !ctype_alpha($code)) {
            return $code;
        }

        return ',';
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::COMMA, 'label' => __('Comma  ,')],
            ['value' => self::SEMICOLON, 'label' => __('Semicolon  ;')],
            ['value' => self::TAB, 'label' => __('Tab')],
            ['value' => self::PIPE, 'label' => __('Pipe  |')],
            ['value' => self::COLON, 'label' => __('Colon  :')],
            ['value' => self::SPACE, 'label' => __('Space')],
        ];
    }
}
