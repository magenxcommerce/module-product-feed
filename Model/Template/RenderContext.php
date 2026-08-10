<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * The variable scope stack a template is rendered against.
 *
 * Plain nested arrays, never Magento models. The renderer therefore cannot reach
 * a method on anything - the only things in scope are values the DataLoader put
 * there. That is a security property, not just a simplification: it is what makes
 * "a template is data" true rather than aspirational.
 */
class RenderContext
{
    /** @var array<int, array<string, mixed>> */
    private array $scopes = [];

    /**
     * @param array<string, mixed> $root Values available everywhere: context.*, store metadata
     */
    public function __construct(
        array $root = [],
        private readonly string $baseCurrencyCode = '',
        private readonly int $storeId = 0
    ) {
        $this->scopes[] = $root;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function push(array $values): void
    {
        $this->scopes[] = $values;
    }

    public function pop(): void
    {
        if (count($this->scopes) > 1) {
            array_pop($this->scopes);
        }
    }

    /**
     * Innermost scope wins, so a {% for product in ... %} alias shadows the root
     * binding of the same name for the duration of the loop.
     */
    public function lookup(string $name): mixed
    {
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return $this->scopes[$i][$name];
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return true;
            }
        }

        return false;
    }

    public function getBaseCurrencyCode(): string
    {
        return $this->baseCurrencyCode;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }
}
