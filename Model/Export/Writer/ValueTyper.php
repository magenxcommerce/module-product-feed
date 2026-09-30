<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Writer;

/**
 * Turns a rendered column value into the JSON type its field map row declares.
 *
 * Every template expression renders to a string, which is what a delimited file
 * wants and what a JSON consumer does not: the agentic-commerce feed reads
 * `"is_eligible_checkout": "true"` as a string, not a flag, and it cannot read
 * `variant_dict` at all unless it arrives as a real object. The field map row
 * therefore carries an optional `type`, and a JSON writer converts through here.
 *
 * A value that does not convert is returned UNCHANGED rather than dropped or
 * zeroed. Silently turning "abc" into 0 or false would publish a wrong value
 * that looks right; leaving it a string keeps it visibly wrong, and the feed's
 * validation rules are where it gets reported.
 */
class ValueTyper
{
    public const TYPE_STRING = 'string';
    public const TYPE_BOOL = 'bool';
    public const TYPE_INT = 'int';
    public const TYPE_NUMBER = 'number';
    public const TYPE_JSON = 'json';
    public const TYPE_LIST = 'list';

    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_BOOL,
        self::TYPE_INT,
        self::TYPE_NUMBER,
        self::TYPE_JSON,
        self::TYPE_LIST,
    ];

    private const TRUE_WORDS = ['true', '1', 'yes', 'y'];
    private const FALSE_WORDS = ['false', '0', 'no', 'n'];

    /**
     * Normalise a declared type, so "boolean" or "integer" in a hand-written map
     * does not silently fall back to string.
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure function, also needed by the Feed model, which has no DI.
    public static function normalizeType(mixed $type): string
    {
        $type = strtolower(trim(is_scalar($type) ? (string) $type : ''));

        return match ($type) {
            'bool', 'boolean' => self::TYPE_BOOL,
            'int', 'integer' => self::TYPE_INT,
            'number', 'float', 'decimal' => self::TYPE_NUMBER,
            'json', 'object' => self::TYPE_JSON,
            'list', 'array' => self::TYPE_LIST,
            default => self::TYPE_STRING,
        };
    }

    /**
     * Parse a flag, or null when the value is not recognisably one.
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure function shared with the Validator so both agree on what a flag is.
    public static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $word = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        if (in_array($word, self::TRUE_WORDS, true)) {
            return true;
        }
        if (in_array($word, self::FALSE_WORDS, true)) {
            return false;
        }

        return null;
    }

    public function convert(mixed $value, string $type): mixed
    {
        if (is_array($value) || is_bool($value) || $value === null) {
            return $value;
        }

        $raw = trim(is_scalar($value) || $value instanceof \Stringable ? (string) $value : '');

        if ($raw === '') {
            return '';
        }

        return match ($type) {
            self::TYPE_BOOL => self::toBool($raw) ?? $raw,
            self::TYPE_INT => preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : $raw,
            self::TYPE_NUMBER => is_numeric($raw) ? $this->toNumber($raw) : $raw,
            self::TYPE_JSON => $this->decodeJson($raw),
            self::TYPE_LIST => $this->toList($raw),
            default => $raw,
        };
    }

    /**
     * Whether a typed value carries no information, and so can be left out of a
     * record entirely rather than written as "".
     *
     * An empty object or list counts as empty too: the agentic-commerce spec
     * treats an empty `dimensions` object as invalid, and `[]` as "none".
     * false and 0 are values, not absences.
     */
    public function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if ($value instanceof \stdClass) {
            return (array) $value === [];
        }

        return is_array($value) && $value === [];
    }

    /**
     * The same flag, spelled the way delimited consumers want it: lowercase
     * true / false, never PHP's 1 / 0.
     */
    public function toDelimitedBool(string $value): string
    {
        $bool = self::toBool($value);

        return $bool === null ? $value : ($bool ? 'true' : 'false');
    }

    private function toNumber(string $raw): int|float
    {
        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : (float) $raw;
    }

    /**
     * A JSON object must stay an object even when PHP would see a list: an empty
     * `{}` decoded to an array would be re-encoded as `[]`, a different type.
     */
    private function decodeJson(string $raw): mixed
    {
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }

        return $decoded ?? $raw;
    }

    /**
     * Comma-separated text to a list. A value that is already JSON (an array
     * rendered with the `json` filter) is taken as-is, which is the safe way to
     * pass URLs that may themselves contain commas.
     *
     * @return array<int, mixed>|string
     */
    private function toList(string $raw): array|string
    {
        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                return array_values(array_filter(
                    $decoded,
                    static fn (mixed $v): bool => $v !== null && $v !== ''
                ));
            }
        }

        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $v): bool => $v !== ''));
    }
}
