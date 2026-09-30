<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Validation;

use Magenx\ProductFeed\Model\Export\Writer\ValueTyper;

/**
 * Checks exported values against a feed's own rules.
 *
 * WHY THIS EARNS ITS PLACE: a marketplace rejects products silently. A title one
 * character over the limit, a link without a scheme, a condition value that is
 * not one of three allowed words - each one drops that product from the listing
 * with no error the merchant ever sees, and the feed itself looks perfectly fine.
 * Reporting it here is the difference between a feed that works and a feed that
 * appears to.
 *
 * Deliberately a single class with a match() rather than a pool of one class per
 * rule type. The vocabulary is fixed by what marketplaces actually check, not
 * open for third parties to extend, so a di.xml extension point would be
 * ceremony without a caller.
 *
 * Rules are declared per feed as JSON:
 *   [{"field": "g:id", "type": "required", "severity": "error",
 *     "message": "ID is a required attribute"},
 *    {"field": "title", "type": "max_length", "length": 150, "severity": "warning"}]
 *
 * Three kinds of rule:
 *  - VALUE rules look at one field of one product (max_length, gtin, money...).
 *  - CROSS-FIELD rules compare two fields of the same product
 *    (sale_price less_than_field price; group_id not_equal_field item_id).
 *  - CROSS-RECORD rules compare products with each other (unique, variant_group).
 *    Their memory lives on the ValidationReport, so it is scoped to the part of
 *    the run the report covers - one cron tick, like the rest of the findings.
 *
 * Rules see the RENDERED strings, before a JSON writer types them, so a boolean
 * rule accepts exactly what the writer would turn into true / false.
 */
class Validator
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_NOTICE = 'notice';
    public const SEVERITY_IMPROVEMENT = 'improvement';

    private const SEVERITIES = [
        self::SEVERITY_ERROR,
        self::SEVERITY_WARNING,
        self::SEVERITY_NOTICE,
        self::SEVERITY_IMPROVEMENT,
    ];

    /**
     * Cap on how many individual failures are retained.
     *
     * A misconfigured feed fails the same rule on every one of 50,000 products;
     * keeping them all would blow up the history row and tell the merchant nothing
     * the first twenty did not. Counts are still exact - only the examples are
     * capped.
     */
    private const MAX_EXAMPLES_PER_RULE = 20;

    /**
     * Rules that must run on an empty value too - every other rule treats an
     * empty value as "not applicable".
     */
    private const RUNS_ON_EMPTY = ['required', 'required_if'];

    /** GTIN-8, UPC-A (12), EAN-13, GTIN-14. Nine to eleven digits is never a GTIN. */
    private const GTIN_LENGTHS = [8, 12, 13, 14];

    /** "79.99 USD": decimal amount in major units, one space, ISO 4217 code. */
    private const MONEY_PATTERN = '/^(\d+(?:\.\d+)?) ([A-Z]{3})$/';

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, mixed> $record
     * @param ValidationReport $report Accumulates across the whole run
     */
    public function validateRecord(array $rules, array $record, ValidationReport $report): void
    {
        foreach ($rules as $rule) {
            $field = (string) ($rule['field'] ?? '');
            if ($field === '') {
                continue;
            }

            $type = (string) ($rule['type'] ?? '');
            $value = $this->stringify($record[$field] ?? '');

            if ($this->passes($type, $value, $rule, $record, $report)) {
                continue;
            }

            $report->add(
                $field,
                $type,
                $this->severity($rule),
                $this->message($rule, $field, $type),
                $this->identify($record),
                self::MAX_EXAMPLES_PER_RULE
            );
        }
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $record
     */
    private function passes(
        string $type,
        string $value,
        array $rule,
        array $record,
        ValidationReport $report
    ): bool {
        // Every rule except the "required" family treats an empty value as "not
        // applicable". Otherwise a single optional field failing max_length or
        // is_one_of would report a finding on every product that simply does not
        // have it.
        if ($value === '' && !in_array($type, self::RUNS_ON_EMPTY, true)) {
            return true;
        }

        return match ($type) {
            'required' => trim($value) !== '',
            'max_length' => mb_strlen($value) <= max(0, (int) ($rule['length'] ?? 0)),
            'min_length' => mb_strlen($value) >= max(0, (int) ($rule['length'] ?? 0)),
            'start_with' => $this->matchesAny($value, $rule, static fn (string $v, string $p): bool
                => str_starts_with($v, $p)),
            'end_with' => $this->matchesAny($value, $rule, static fn (string $v, string $s): bool
                => str_ends_with($v, $s)),
            'is_one_of' => in_array($value, $this->values($rule), true),
            'alphanumeric' => preg_match('/^[a-zA-Z0-9]+$/', $value) === 1,
            'ascii' => mb_check_encoding($value, 'ASCII'),
            'unicode' => $this->isValidUnicode($value),
            'numeric' => is_numeric($value),
            'without_html' => $value === strip_tags($value),
            'regex' => $this->matchesPattern($value, (string) ($rule['pattern'] ?? '')),
            'gtin' => $this->isValidGtin($value),
            'url' => $this->isValidUrl($value),
            'money' => $this->isValidMoney($value, (bool) ($rule['allow_zero'] ?? false)),
            'boolean' => ValueTyper::toBool($value) !== null,
            'integer' => $this->isInRange($value, $rule, true),
            'decimal' => $this->isInRange($value, $rule, false),
            'json_object' => $this->isStringObject($value, $rule),
            'required_if' => !$this->conditionHolds($record, $rule) || trim($value) !== '',
            'only_if' => $this->conditionHolds($record, $rule),
            'less_than_field' => $this->isLessThan($value, $this->otherValue($record, $rule)),
            'not_equal_field' => $value !== $this->otherValue($record, $rule),
            'unique' => $this->isFirstOccurrence((string) ($rule['field'] ?? ''), $value, $report),
            'variant_group' => $this->isConsistentVariant($value, $rule, $record, $report),
            // An unknown rule type passes rather than fails: a typo in a rule
            // definition must not mark an otherwise correct feed as broken.
            default => true,
        };
    }

    /**
     * A merchant-authored pattern, given WITHOUT delimiters. An invalid pattern
     * passes, for the same reason an unknown rule type does.
     */
    private function matchesPattern(string $value, string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        $regex = '~' . str_replace('~', '\\~', $pattern) . '~u';

        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a malformed admin-authored pattern must not raise a PHP warning per product.
        $result = @preg_match($regex, $value);

        return $result !== 0;
    }

    /**
     * Exactly 8, 12, 13 or 14 digits with a valid GS1 check digit.
     */
    private function isValidGtin(string $value): bool
    {
        if (preg_match('/^\d+$/', $value) !== 1 || !in_array(strlen($value), self::GTIN_LENGTHS, true)) {
            return false;
        }

        $digits = array_map('intval', str_split($value));
        $check = array_pop($digits);

        $sum = 0;
        foreach (array_reverse($digits) as $position => $digit) {
            // Rightmost digit before the check digit is weighted 3, then 1, 3, ...
            $sum += $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /**
     * An absolute http(s) URL with no whitespace and no embedded credentials -
     * the agentic-commerce feed rejects a row whose URL carries a user:password.
     */
    private function isValidUrl(string $value): bool
    {
        if (preg_match('/\s/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- parsing an exported value, not a request URL.
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function isValidMoney(string $value, bool $allowZero): bool
    {
        $money = $this->parseMoney($value);
        if ($money === null) {
            return false;
        }

        return $allowZero ? $money['amount'] >= 0 : $money['amount'] > 0;
    }

    /**
     * @return array{amount: float, currency: string}|null
     */
    private function parseMoney(string $value): ?array
    {
        if (preg_match(self::MONEY_PATTERN, $value, $match) !== 1) {
            return null;
        }

        return ['amount' => (float) $match[1], 'currency' => $match[2]];
    }

    /**
     * Integer or plain decimal (no exponent, no thousands separator), optionally
     * bounded, and for decimals optionally capped in fraction digits.
     *
     * @param array<string, mixed> $rule
     */
    private function isInRange(string $value, array $rule, bool $integer): bool
    {
        $pattern = $integer ? '/^-?\d+$/' : '/^-?\d+(\.\d+)?$/';
        if (preg_match($pattern, $value) !== 1) {
            return false;
        }

        $number = (float) $value;

        if (isset($rule['min']) && is_numeric($rule['min']) && $number < (float) $rule['min']) {
            return false;
        }
        if (isset($rule['max']) && is_numeric($rule['max']) && $number > (float) $rule['max']) {
            return false;
        }

        if (!$integer && isset($rule['decimals']) && is_numeric($rule['decimals'])) {
            $fraction = strpos($value, '.') === false ? '' : substr($value, strpos($value, '.') + 1);
            if (strlen($fraction) > (int) $rule['decimals']) {
                return false;
            }
        }

        return true;
    }

    /**
     * A JSON object whose keys are non-empty and whose values are all non-empty
     * strings - the shape of both `variant_dict` and `dimensions`. `keys`, when
     * given, is the complete list of keys allowed.
     *
     * @param array<string, mixed> $rule
     */
    private function isStringObject(string $value, array $rule): bool
    {
        $map = $this->decodeObject($value);
        if ($map === null) {
            return false;
        }

        $allowed = $this->listParam($rule['keys'] ?? []);

        foreach ($map as $key => $item) {
            if (trim((string) $key) === '' || !is_string($item) || trim($item) === '') {
                return false;
            }
            if ($allowed !== [] && !in_array((string) $key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeObject(string $value): ?array
    {
        if (!str_starts_with(ltrim($value), '{')) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether the rule's condition on another field holds: that field is
     * non-empty, or - with `equals` - has that value. Flags compare as flags, so
     * `"equals": true` matches a rendered "true", "1" or "yes".
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $rule
     */
    private function conditionHolds(array $record, array $rule): bool
    {
        $other = $this->otherValue($record, $rule);

        if (!array_key_exists('equals', $rule)) {
            return trim($other) !== '';
        }

        $expected = $rule['equals'];
        $expectedBool = ValueTyper::toBool($expected);
        $actualBool = ValueTyper::toBool($other);

        if ($expectedBool !== null && $actualBool !== null) {
            return $expectedBool === $actualBool;
        }

        return strcasecmp(trim($other), trim($this->stringify($expected))) === 0;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $rule
     */
    private function otherValue(array $record, array $rule): string
    {
        $other = (string) ($rule['other'] ?? '');

        return $other === '' ? '' : $this->stringify($record[$other] ?? '');
    }

    /**
     * Strictly less than the other field, in the same currency when both are
     * money. Nothing to compare against passes: "sale price below price" says
     * nothing about a product with no price, which `required` reports anyway.
     */
    private function isLessThan(string $value, string $other): bool
    {
        if ($other === '') {
            return true;
        }

        $left = $this->parseMoney($value);
        $right = $this->parseMoney($other);

        if ($left !== null || $right !== null) {
            return $left !== null
                && $right !== null
                && $left['currency'] === $right['currency']
                && $left['amount'] < $right['amount'];
        }

        return is_numeric($value) && is_numeric($other) && (float) $value < (float) $other;
    }

    private function isFirstOccurrence(string $field, string $value, ValidationReport $report): bool
    {
        $bucket = 'unique:' . $field;
        if ($report->recall($bucket, $value) !== null) {
            return false;
        }

        $report->remember($bucket, $value, true);

        return true;
    }

    /**
     * Variants of one group must use the same option names, and no two may
     * select the same combination. The value is the variant options object;
     * `group_by` names the group column.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $record
     */
    private function isConsistentVariant(string $value, array $rule, array $record, ValidationReport $report): bool
    {
        $group = $this->otherValue($record, ['other' => (string) ($rule['group_by'] ?? '')]);
        if ($group === '') {
            return true;
        }

        $map = $this->decodeObject($value);
        if ($map === null || $map === []) {
            return false;
        }

        ksort($map);
        $names = implode("\0", array_keys($map));
        $combination = (string) json_encode($map, JSON_UNESCAPED_UNICODE);

        $knownNames = $report->recall('variant_names', $group);
        if ($knownNames === null) {
            $report->remember('variant_names', $group, $names);
        } elseif ($knownNames !== $names) {
            return false;
        }

        $comboKey = $group . "\0" . $combination;
        if ($report->recall('variant_combos', $comboKey) !== null) {
            return false;
        }
        $report->remember('variant_combos', $comboKey, true);

        return true;
    }

    /**
     * @return string[]
     */
    private function listParam(mixed $values): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        return is_array($values)
            ? array_values(array_filter(array_map(
                static fn (mixed $v): string => trim((string) $v),
                $values
            ), static fn (string $v): bool => $v !== ''))
            : [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param callable(string, string): bool $test
     */
    private function matchesAny(string $value, array $rule, callable $test): bool
    {
        $candidates = $this->values($rule);
        if ($candidates === []) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($test($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     * @return string[]
     */
    private function values(array $rule): array
    {
        return $this->listParam($rule['values'] ?? []);
    }

    /**
     * Well-formed UTF-8 with no unpaired surrogates or control characters.
     *
     * Checked because a stray control character invalidates an entire XML document
     * - the marketplace then reports "feed could not be read" with no indication
     * of which product is responsible.
     */
    private function isValidUnicode(string $value): bool
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) !== 1;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function severity(array $rule): string
    {
        $severity = strtolower((string) ($rule['severity'] ?? self::SEVERITY_WARNING));

        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_WARNING;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function message(array $rule, string $field, string $type): string
    {
        $message = trim((string) ($rule['message'] ?? ''));

        return $message !== '' ? $message : sprintf('"%s" failed the %s check.', $field, $type);
    }

    /**
     * Which product a finding is about. Without this a report says "17 titles are
     * too long" and the merchant has no way to find them.
     *
     * @param array<string, mixed> $record
     */
    private function identify(array $record): string
    {
        foreach (['sku', 'id', 'g:id', 'item_id', 'entity_id'] as $key) {
            $value = $record[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $v): string => $this->stringify($v), $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
