<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Validation;

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

            if ($this->passes($type, $value, $rule)) {
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
     */
    private function passes(string $type, string $value, array $rule): bool
    {
        // Every rule except `required` treats an empty value as "not applicable".
        // Otherwise a single optional field failing max_length or is_one_of would
        // report a finding on every product that simply does not have it.
        if ($value === '' && $type !== 'required') {
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
            // An unknown rule type passes rather than fails: a typo in a rule
            // definition must not mark an otherwise correct feed as broken.
            default => true,
        };
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
        $values = $rule['values'] ?? [];

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            $values
        ), static fn (string $v): bool => $v !== ''));
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
