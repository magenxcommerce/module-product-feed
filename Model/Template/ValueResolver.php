<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * Walks an Expression's path through the RenderContext.
 *
 * Only array traversal happens here. If a segment is not an array key the walk
 * stops and yields null - it never falls back to calling a getter, because the
 * context contains no objects to call one on. See Renderer's security note.
 *
 * Understood shapes:
 *   product.name                 plain field
 *   product.parent.name          fall back to the parent's value, then the child's
 *   product.gallery[2]           positional index into a list
 *   product.inventory:default    selector into a keyed map (MSI source, mapping id)
 */
class ValueResolver
{
    /**
     * Path prefixes whose second segment is a hop to another record rather than a
     * field name.
     */
    private const HOP_PARENT = 'parent';

    public function resolve(Expression $expression, RenderContext $context): mixed
    {
        $segments = $expression->path;
        if ($segments === []) {
            return null;
        }

        $rootName = array_shift($segments);
        if (!$context->has($rootName)) {
            return null;
        }

        $current = $context->lookup($rootName);

        // product.parent.* - resolve against the parent record when one was loaded,
        // and fall back to the product's own value when it was not. A simple product
        // with no configurable parent must still export a usable URL rather than an
        // empty one; that is the whole point of the .parent suffix.
        if (($segments[0] ?? null) === self::HOP_PARENT) {
            array_shift($segments);
            $parent = is_array($current) ? ($current['parent'] ?? null) : null;
            $fallback = $current;

            $resolved = $this->walk($parent, $segments, $expression);

            return $this->isBlank($resolved) ? $this->walk($fallback, $segments, $expression) : $resolved;
        }

        return $this->walk($current, $segments, $expression);
    }

    /**
     * @param string[] $segments
     */
    private function walk(mixed $current, array $segments, Expression $expression): mixed
    {
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        if ($expression->selector !== null) {
            $current = is_array($current) ? ($current[$expression->selector] ?? null) : null;
        }

        if ($expression->index !== null) {
            if (!is_array($current)) {
                return null;
            }
            $list = array_values($current);
            $current = $list[(int) $expression->index] ?? null;
        }

        return $current;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
