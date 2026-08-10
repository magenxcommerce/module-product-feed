<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * One parsed `path | filter:arg | filter` expression.
 *
 * Immutable, and produced only by ExpressionParser. `filters` is a list of
 * [name, args] pairs applied left to right.
 *
 * @phpstan-type FilterCall array{0: string, 1: array<int, string|float|int>}
 */
final class Expression
{
    /**
     * @param string[] $path Dot-separated segments, e.g. ['product', 'parent', 'name']
     * @param array<int, array{0: string, 1: array<int, mixed>}> $filters
     * @param string|null $index Bracket index, e.g. gallery[0] -> '0'
     * @param string|null $selector Colon selector, e.g. inventory:default -> 'default'
     */
    public function __construct(
        public readonly array $path,
        public readonly array $filters = [],
        public readonly ?string $index = null,
        public readonly ?string $selector = null,
        public readonly mixed $literal = null,
        public readonly bool $isLiteral = false
    ) {
    }

    /**
     * The namespace this expression reads from: product, category, review, ...
     */
    public function getRoot(): string
    {
        return $this->path[0] ?? '';
    }

    /**
     * The field within the namespace, ignoring a `parent.` hop.
     *
     * Used by Parser to decide which attributes the export must load.
     */
    public function getField(): string
    {
        $segments = $this->path;
        array_shift($segments);

        if (($segments[0] ?? null) === 'parent') {
            array_shift($segments);
        }

        return implode('.', $segments);
    }

    public function isParentLookup(): bool
    {
        return ($this->path[1] ?? null) === 'parent';
    }
}
