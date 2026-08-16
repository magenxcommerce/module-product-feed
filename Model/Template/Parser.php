<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * Static analysis of a compiled template: what data does it actually reference?
 *
 * Runs ONCE per generation run, never per product. Its output drives the
 * DataLoader, so a template that never mentions reviews costs no review query at
 * all - which is the difference between an export that scales and one that does
 * eleven queries per batch regardless.
 */
class Parser
{
    /**
     * Fields on `product` that are not EAV attributes and must not be added to
     * addAttributeToSelect() - Magento throws for an attribute code it cannot
     * find, so a synthetic field passed through would abort the export.
     *
     * @var array<string, true>
     */
    private const SYNTHETIC_PRODUCT_FIELDS = [
        'url' => true,
        'url_with_options' => true,
        'final_price' => true,
        'final_price_tax' => true,
        'min_price' => true,
        'max_price' => true,
        'regular_price' => true,
        'qty' => true,
        'is_in_stock' => true,
        'stock_status' => true,
        'is_salable' => true,
        'category' => true,
        'categories' => true,
        'category_ids' => true,
        'image' => true,
        'images' => true,
        'gallery' => true,
        'inventory' => true,
        'tier_prices' => true,
        'source_items' => true,
        'configurable_attributes' => true,
        'parent' => true,
        'reviews_count' => true,
        'rating_summary' => true,
        'attribute_set' => true,
        'stock' => true,
    ];

    /** Fields that require the price index join. */
    private const PRICE_FIELDS = [
        'final_price', 'final_price_tax', 'min_price', 'max_price', 'regular_price', 'special_price', 'tier_price',
    ];

    /** Fields that require the stock join. */
    private const STOCK_FIELDS = ['qty', 'is_in_stock', 'stock_status', 'is_salable', 'stock'];

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    public function analyze(array $nodes): Requirements
    {
        $state = [
            'attributes' => [],
            'prices' => false,
            'stock' => false,
            'categories' => false,
            'gallery' => false,
            'parent' => false,
            'reviews' => false,
            'tier' => false,
            'sources' => false,
            'configurable' => false,
            'url' => false,
        ];

        $this->walk($nodes, $state);

        ksort($state['attributes']);

        return new Requirements(
            array_keys($state['attributes']),
            $state['prices'],
            $state['stock'],
            $state['categories'],
            $state['gallery'],
            $state['parent'],
            $state['reviews'],
            $state['tier'],
            $state['sources'],
            $state['configurable'],
            $state['url']
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, mixed> $state
     */
    private function walk(array $nodes, array &$state): void
    {
        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'var':
                    $this->collect($node['expr'], $state);
                    break;
                case 'for':
                    $this->collect($node['expr'], $state);
                    $this->noteLoopSource($node['expr'], $state);
                    $this->walk($node['body'], $state);
                    break;
                case 'if':
                    foreach ($node['branches'] as $branch) {
                        if ($branch['test'] !== null) {
                            $this->collect($branch['test']['left'], $state);
                            if ($branch['test']['right'] !== null) {
                                $this->collect($branch['test']['right'], $state);
                            }
                        }
                        $this->walk($branch['body'], $state);
                    }
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function noteLoopSource(Expression $expression, array &$state): void
    {
        $path = implode('.', $expression->path);

        if (str_ends_with($path, 'reviews')) {
            $state['reviews'] = true;
        }
        if (str_ends_with($path, 'tier_prices')) {
            $state['tier'] = true;
        }
        if (str_ends_with($path, 'source_items')) {
            $state['sources'] = true;
        }
        if (str_ends_with($path, 'configurable_attributes')) {
            $state['configurable'] = true;
        }
        if (str_ends_with($path, 'categories')) {
            $state['categories'] = true;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function collect(Expression $expression, array &$state): void
    {
        if ($expression->isLiteral) {
            return;
        }

        $root = $expression->getRoot();

        if ($expression->isParentLookup()) {
            $state['parent'] = true;
        }

        if ($root === 'review') {
            $state['reviews'] = true;
        }
        if ($root === 'category') {
            $state['categories'] = true;
        }
        if ($root === 'tier_price') {
            $state['tier'] = true;
        }
        if ($root === 'source') {
            $state['sources'] = true;
        }
        if ($root === 'attribute') {
            $state['configurable'] = true;
        }

        if ($root !== 'product' && $root !== 'review') {
            return;
        }

        $field = $expression->getField();
        if ($field === '') {
            return;
        }

        // review.product.<code> reaches back into the product record.
        if ($root === 'review' && str_starts_with($field, 'product.')) {
            $field = substr($field, strlen('product.'));
        }

        $head = explode('.', $field)[0];

        if (in_array($head, self::PRICE_FIELDS, true)) {
            $state['prices'] = true;
        }
        if (in_array($head, self::STOCK_FIELDS, true)) {
            $state['stock'] = true;
        }
        if ($head === 'category' || $head === 'categories' || $head === 'category_ids') {
            $state['categories'] = true;
        }
        if (in_array($head, ['image', 'images', 'gallery', 'thumbnail', 'small_image'], true)) {
            $state['gallery'] = true;
        }
        if ($head === 'inventory' || $head === 'source_items') {
            $state['sources'] = true;
        }
        if ($head === 'tier_prices') {
            $state['tier'] = true;
        }
        if ($head === 'configurable_attributes') {
            $state['configurable'] = true;
        }
        if ($head === 'url' || $head === 'url_with_options') {
            $state['url'] = true;
        }

        // Anything not synthetic is an EAV attribute the collection must select.
        // thumbnail/small_image are BOTH: real attributes and gallery-derived, so
        // they are selected as attributes too and simply resolve from the same row.
        if (!isset(self::SYNTHETIC_PRODUCT_FIELDS[$head])) {
            $state['attributes'][$head] = true;
        }
    }
}
