<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * Renders a compiled node tree against a RenderContext.
 *
 * ============================ SECURITY BOUNDARY ============================
 * A feed template is DATA. It is authored in the admin, and the only things it
 * can name are (a) a field the DataLoader placed in the context and (b) a filter
 * from the fixed table in Filters::apply().
 *
 * There is deliberately NO path from template text to executable code:
 *   - no eval, no create_function, no include of a template-named file
 *   - no call_user_func on a template-supplied name (filters resolve through a
 *     hard-coded match(), so an unknown name is a no-op, not a callable)
 *   - no method calls: the context holds nested arrays, never Magento models, so
 *     there is nothing with methods to reach in the first place
 *   - no arbitrary PHP functions exposed as filters
 *
 * Feed extensions commonly offer a "dynamic variable" feature that includes an
 * admin-authored PHP file from var/ at render time. That is remote code
 * execution reachable by anyone holding feed permissions, and it is the single
 * feature this module most deliberately does not have. Do not add it, and do not
 * add a generic "call this PHP function" filter, which is the same hole with a
 * smaller door.
 * ==========================================================================
 */
class Renderer
{
    /**
     * Hard ceiling on loop iterations per {% for %}.
     *
     * The context is built by our own loader, so a runaway list should not be
     * possible - but a template rendered once per product multiplies any mistake
     * by the size of the catalog, and an export that quietly produces a
     * multi-gigabyte file is worse than one that stops.
     */
    private const MAX_LOOP_ITERATIONS = 100000;

    public function __construct(
        private readonly Filters $filters,
        private readonly ValueResolver $valueResolver
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    public function render(array $nodes, RenderContext $context): string
    {
        $out = '';

        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'text' => $node['value'],
                'var' => $this->stringify($this->evaluate($node['expr'], $context)),
                'for' => $this->renderFor($node, $context),
                'if' => $this->renderIf($node, $context),
                default => '',
            };
        }

        return $out;
    }

    /**
     * Resolve one expression: look the path up, then pipe it through its filters.
     */
    public function evaluate(Expression $expression, RenderContext $context): mixed
    {
        $value = $expression->isLiteral
            ? $expression->literal
            : $this->valueResolver->resolve($expression, $context);

        foreach ($expression->filters as [$name, $args]) {
            $value = $this->filters->apply($name, $value, $args, $context);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderFor(array $node, RenderContext $context): string
    {
        $list = $this->evaluate($node['expr'], $context);

        if (!is_array($list) || $list === []) {
            return '';
        }

        $alias = (string) $node['alias'];
        $out = '';
        $index = 0;

        foreach ($list as $item) {
            if ($index >= self::MAX_LOOP_ITERATIONS) {
                break;
            }

            $context->push([
                $alias => $item,
                'forloop' => [
                    'index' => $index + 1,
                    'index0' => $index,
                    'first' => $index === 0,
                ],
            ]);

            try {
                $out .= $this->render($node['body'], $context);
            } finally {
                // finally, not a trailing pop(): a throw from a nested render must
                // not leave the scope stack unbalanced for the next product.
                $context->pop();
            }

            $index++;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderIf(array $node, RenderContext $context): string
    {
        foreach ($node['branches'] as $branch) {
            if ($branch['test'] === null || $this->test($branch['test'], $context)) {
                return $this->render($branch['body'], $context);
            }
        }

        return '';
    }

    /**
     * @param array{left: Expression, operator: ?string, right: ?Expression} $test
     */
    private function test(array $test, RenderContext $context): bool
    {
        $left = $this->evaluate($test['left'], $context);

        if ($test['operator'] === null) {
            return $this->isTruthy($left);
        }

        $right = $test['right'] === null ? null : $this->evaluate($test['right'], $context);

        return match ($test['operator']) {
            '==' => $this->looseEquals($left, $right),
            '!=' => !$this->looseEquals($left, $right),
            '>' => $this->compare($left, $right) > 0,
            '<' => $this->compare($left, $right) < 0,
            '>=' => $this->compare($left, $right) >= 0,
            '<=' => $this->compare($left, $right) <= 0,
            'contains' => $this->contains($left, $right),
            default => false,
        };
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === []) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '' && $value !== '0';
        }
        if (is_numeric($value)) {
            return (float) $value != 0.0;
        }

        return (bool) $value;
    }

    private function looseEquals(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left == (float) $right;
        }

        return $this->stringify($left) === $this->stringify($right);
    }

    /**
     * Numeric when both sides look numeric, string otherwise.
     *
     * This matters for the common {% if product.qty > 10 %}: a string comparison
     * would make "9" greater than "10".
     */
    private function compare(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strcmp($this->stringify($left), $this->stringify($right));
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_array($haystack)) {
            foreach ($haystack as $item) {
                if ($this->looseEquals($item, $needle)) {
                    return true;
                }
            }

            return false;
        }

        $needleString = $this->stringify($needle);

        return $needleString !== '' && str_contains($this->stringify($haystack), $needleString);
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
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
