<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Validation;

/**
 * Findings accumulated across a whole generation run.
 *
 * Aggregated by field+rule rather than kept per product: "title exceeds 150
 * characters (1,204 products, e.g. SKU-1, SKU-9, SKU-44)" is what a merchant can
 * act on, whereas 1,204 separate rows is what makes them stop reading.
 */
class ValidationReport
{
    /**
     * @var array<string, array{
     *   field: string, type: string, severity: string, message: string,
     *   count: int, examples: string[]
     * }>
     */
    private array $findings = [];

    /**
     * Cross-record memory for rules that compare products with each other
     * (unique ids, variant groups). Lives on the report because the report is
     * what is scoped to one run; the Validator itself is a shared service.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memory = [];

    /**
     * @param string $example Identifier of the offending product
     * @param int $maxExamples Cap on retained examples; the count stays exact
     */
    public function add(
        string $field,
        string $type,
        string $severity,
        string $message,
        string $example,
        int $maxExamples
    ): void {
        $key = $field . '|' . $type;

        if (!isset($this->findings[$key])) {
            $this->findings[$key] = [
                'field' => $field,
                'type' => $type,
                'severity' => $severity,
                'message' => $message,
                'count' => 0,
                'examples' => [],
            ];
        }

        $this->findings[$key]['count']++;

        if ($example !== '' && count($this->findings[$key]['examples']) < $maxExamples) {
            $this->findings[$key]['examples'][] = $example;
        }
    }

    /**
     * Value previously stored under bucket/key, or null.
     */
    public function recall(string $bucket, string $key): mixed
    {
        return $this->memory[$bucket][$key] ?? null;
    }

    public function remember(string $bucket, string $key, mixed $value): void
    {
        $this->memory[$bucket][$key] = $value;
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === Validator::SEVERITY_ERROR) {
                return true;
            }
        }

        return false;
    }

    public function countBySeverity(string $severity): int
    {
        $total = 0;
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === $severity) {
                $total += $finding['count'];
            }
        }

        return $total;
    }

    /**
     * Most severe first, then by how many products each affects.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $order = [
            Validator::SEVERITY_ERROR => 0,
            Validator::SEVERITY_WARNING => 1,
            Validator::SEVERITY_NOTICE => 2,
            Validator::SEVERITY_IMPROVEMENT => 3,
        ];

        $findings = array_values($this->findings);

        usort(
            $findings,
            static fn (array $a, array $b): int
                => [$order[$a['severity']] ?? 9, -$a['count']] <=> [$order[$b['severity']] ?? 9, -$b['count']]
        );

        return $findings;
    }

    public function summarize(): string
    {
        if ($this->isEmpty()) {
            return (string) __('No validation issues.');
        }

        $parts = [];
        foreach ([
            Validator::SEVERITY_ERROR => __('errors'),
            Validator::SEVERITY_WARNING => __('warnings'),
            Validator::SEVERITY_NOTICE => __('notices'),
            Validator::SEVERITY_IMPROVEMENT => __('improvements'),
        ] as $severity => $label) {
            $count = $this->countBySeverity($severity);
            if ($count > 0) {
                $parts[] = $count . ' ' . $label;
            }
        }

        return implode(', ', $parts);
    }
}
