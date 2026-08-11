<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * The filter table.
 *
 * SECURITY: this map is the complete set of transformations a template may
 * perform. Every entry is a method on this class, resolved through a hard-coded
 * match() - there is no dynamic dispatch, no call_user_func on a template-supplied
 * name, and no facility for running a PHP file named by an admin. Adding a filter
 * means adding a case here, deliberately. See Renderer for the wider rationale.
 *
 * An unknown filter name returns the value untouched and logs once, rather than
 * throwing: a template is merchant-authored, and one mistyped filter should
 * degrade a single field, not abort an export that is otherwise fine.
 */
class Filters
{
    /** Guard against a runaway {{ x | times: 1e9 }} producing an unusable string. */
    private const MAX_REPEAT_OUTPUT = 100000;

    /** @var array<string, true> */
    private array $warnedUnknown = [];

    public function __construct(
        private readonly TimezoneInterface $localeDate,
        private readonly CurrencyFactory $currencyFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int, mixed> $args
     */
    public function apply(string $name, mixed $value, array $args, RenderContext $context): mixed
    {
        return match ($name) {
            // ---- string -------------------------------------------------
            'lowercase', 'downcase' => mb_strtolower($this->str($value)),
            'uppercase', 'upcase' => mb_strtoupper($this->str($value)),
            'capitalize' => $this->capitalize($this->str($value)),
            'replace' => $this->replace($this->str($value), $args),
            'remove' => str_replace($this->arg($args, 0, ''), '', $this->str($value)),
            'append' => $this->str($value) . $this->arg($args, 0, ''),
            'prepend' => $this->arg($args, 0, '') . $this->str($value),
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative -- a template filter primitive; Escaper is store-scoped and not available here.
            'escape' => htmlspecialchars($this->str($value), ENT_QUOTES | ENT_XML1, 'UTF-8'),
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- feed output is not HTML; entities must be decoded back to raw text.
            'html_entity_decode' => html_entity_decode($this->str($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'nl2br', 'newline_to_br' => nl2br($this->str($value)),
            'strip_newlines' => str_replace(["\r\n", "\r", "\n"], ' ', $this->str($value)),
            'stripHtml', 'html2plain', 'plain' => $this->stripHtml($this->str($value)),
            'stripStyleTag' => $this->stripStyleTag($this->str($value)),
            'clean' => $this->clean($this->str($value)),
            'trim' => trim($this->str($value)),
            'ltrim' => ltrim($this->str($value)),
            'rtrim' => rtrim($this->str($value)),
            'truncate' => $this->truncate($this->str($value), (int) $this->arg($args, 0, 100), (string) $this->arg($args, 1, '')),
            'truncatewords' => $this->truncateWords($this->str($value), (int) $this->arg($args, 0, 20), (string) $this->arg($args, 1, '')),
            'ifEmpty', 'default' => $this->isBlank($value) ? $this->arg($args, 0, '') : $value,

            // ---- date / json --------------------------------------------
            'dateFormat' => $this->dateFormat($value, (string) $this->arg($args, 0, 'Y-m-d')),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            // ---- number --------------------------------------------------
            'ceil' => (string) (int) ceil($this->num($value)),
            'floor' => (string) (int) floor($this->num($value)),
            'round' => $this->formatDecimal(round($this->num($value), (int) $this->arg($args, 0, 0)), (int) $this->arg($args, 0, 0)),
            'numberFormat' => number_format(
                $this->num($value),
                (int) $this->arg($args, 0, 2),
                (string) $this->arg($args, 1, '.'),
                (string) $this->arg($args, 2, '')
            ),

            // ---- price / currency ----------------------------------------
            'price' => $this->formatDecimal($this->num($value), 2),
            'convert' => $this->convert($this->num($value), (string) $this->arg($args, 0, ''), $context),

            // ---- math ----------------------------------------------------
            'plus' => $this->formatDecimal($this->num($value) + $this->num($this->arg($args, 0, 0)), 2),
            'minus' => $this->formatDecimal($this->num($value) - $this->num($this->arg($args, 0, 0)), 2),
            'times' => $this->formatDecimal($this->num($value) * $this->num($this->arg($args, 0, 1)), 2),
            'divided_by' => $this->dividedBy($this->num($value), $this->num($this->arg($args, 0, 1))),
            'modulo' => $this->modulo($this->num($value), $this->num($this->arg($args, 0, 1))),

            // ---- array ---------------------------------------------------
            'first' => $this->firstOf($value),
            'last' => $this->lastOf($value),
            'count', 'size' => (string) count($this->arr($value)),
            'join' => implode((string) $this->arg($args, 0, ','), array_map([$this, 'str'], $this->arr($value))),

            // ---- url -----------------------------------------------------
            'secure' => preg_replace('#^http://#i', 'https://', $this->str($value)),
            'unsecure' => preg_replace('#^https://#i', 'http://', $this->str($value)),

            default => $this->unknown($name, $value),
        };
    }

    // =====================================================================
    // Implementations
    // =====================================================================

    private function capitalize(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function replace(string $value, array $args): string
    {
        return str_replace(
            (string) $this->arg($args, 0, ''),
            (string) $this->arg($args, 1, ''),
            $value
        );
    }

    private function stripHtml(string $value): string
    {
        // Drop script/style bodies first: strip_tags() removes the tags but keeps
        // their text content, which would otherwise inject CSS or JS source into
        // a product description field.
        $value = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $value);
        $value = strip_tags($value);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- feed output is not HTML; entities must be decoded back to raw text.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->clean($value);
    }

    private function stripStyleTag(string $value): string
    {
        return (string) preg_replace('#<style\b[^>]*>.*?</style>#is', '', $value);
    }

    /**
     * Collapse all whitespace runs to a single space and trim.
     */
    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function truncate(string $value, int $length, string $ellipsis): string
    {
        if ($length <= 0 || mb_strlen($value) <= $length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $length - mb_strlen($ellipsis))) . $ellipsis;
    }

    private function truncateWords(string $value, int $words, string $ellipsis): string
    {
        if ($words <= 0) {
            return $value;
        }

        $parts = preg_split('/\s+/u', trim($value)) ?: [];
        if (count($parts) <= $words) {
            return $value;
        }

        return implode(' ', array_slice($parts, 0, $words)) . $ellipsis;
    }

    private function dateFormat(mixed $value, string $format): string
    {
        $raw = $this->str($value);
        if ($raw === '') {
            return '';
        }

        try {
            return $this->localeDate->date(new \DateTime($raw))->format($format);
        } catch (\Throwable) {
            // A non-date value formatted as a date is a template mistake, not an
            // export failure. Emit nothing and keep going.
            return '';
        }
    }

    private function convert(float $value, string $toCurrency, RenderContext $context): string
    {
        $from = $context->getBaseCurrencyCode();
        if ($toCurrency === '' || $from === '' || strcasecmp($from, $toCurrency) === 0) {
            return $this->formatDecimal($value, 2);
        }

        try {
            $rate = (float) $this->currencyFactory->create()->load($from)->getAnyRate($toCurrency);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Magenx_ProductFeed: currency conversion %s->%s failed: %s', $from, $toCurrency, $e->getMessage())
            );
            $rate = 0.0;
        }

        if ($rate <= 0) {
            return $this->formatDecimal($value, 2);
        }

        return $this->formatDecimal($value * $rate, 2);
    }

    private function dividedBy(float $value, float $divisor): string
    {
        if ($divisor == 0.0) {
            // Division by zero in a feed template is a data problem (a zero quantity,
            // an empty attribute), not something worth aborting an export for.
            return '';
        }

        return $this->formatDecimal($value / $divisor, 2);
    }

    private function modulo(float $value, float $divisor): string
    {
        if ($divisor == 0.0) {
            return '';
        }

        return $this->formatDecimal(fmod($value, $divisor), 2);
    }

    private function firstOf(mixed $value): mixed
    {
        $array = $this->arr($value);

        // An empty list must yield an empty value, never a fatal: a product with no
        // gallery images is ordinary, and it must not abort the whole export.
        return $array === [] ? '' : reset($array);
    }

    private function lastOf(mixed $value): mixed
    {
        $array = $this->arr($value);

        return $array === [] ? '' : end($array);
    }

    private function unknown(string $name, mixed $value): mixed
    {
        if (!isset($this->warnedUnknown[$name])) {
            $this->warnedUnknown[$name] = true;
            $this->logger->warning(
                sprintf('Magenx_ProductFeed: unknown template filter "%s" - value passed through unchanged.', $name)
            );
        }

        return $value;
    }

    // =====================================================================
    // Coercion helpers
    // =====================================================================

    /**
     * @param array<int, mixed> $args
     */
    private function arg(array $args, int $index, mixed $default): mixed
    {
        return $args[$index] ?? $default;
    }

    private function str(mixed $value): string
    {
        if (is_array($value)) {
            // A multiselect attribute with several values must export its labels,
            // not the literal word "Array".
            return implode(', ', array_map([$this, 'str'], $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    private function num(mixed $value): float
    {
        if (is_array($value)) {
            return 0.0;
        }

        return (float) $this->str($value);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arr(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($this->isBlank($value)) {
            return [];
        }

        return [$value];
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * Format a number for machine consumption: a fixed decimal count, a dot
     * separator and no thousands separator.
     *
     * Never number_format() with a group separator here - "1,299.00" is read by a
     * marketplace as either a broken number or as 1.299, and it is one of the most
     * common causes of a silently rejected product.
     */
    private function formatDecimal(float $value, int $decimals): string
    {
        if ($value > self::MAX_REPEAT_OUTPUT * 1e12) {
            return '';
        }

        return number_format($value, max(0, $decimals), '.', '');
    }
}
