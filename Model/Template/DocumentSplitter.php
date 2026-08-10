<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * Splits a whole-document template into header / per-item / footer.
 *
 * A feed template is written as one complete document with the product loop in
 * the middle:
 *
 *     <?xml version="1.0"?>
 *     <rss version="2.0">
 *     {% for product in context.products %}
 *       <item>...</item>
 *     {% endfor %}
 *     </rss>
 *
 * That shape cannot be rendered in one pass on a catalog of any size - it would
 * mean holding every product in memory at once, which is exactly what the
 * batching exists to avoid. So the loop is located and lifted out: the header is
 * rendered once at the start of the run, the loop BODY is rendered per product
 * across as many cron ticks as it takes, and the footer is written when the run
 * completes.
 *
 * This is also what makes a run resumable. Because the header is already on disk
 * and the footer is not yet written, a run interrupted by its time budget simply
 * continues appending items on the next tick.
 *
 * A template with no product loop is treated as a per-item body with no header
 * or footer, which is what a bare CSV-style template wants.
 */
class DocumentSplitter
{
    /**
     * Loop sources that count as "the product loop". Anything else (reviews, tier
     * prices) is a nested loop inside the item body and must be left alone.
     */
    private const PRODUCT_SOURCES = ['context.products', 'products'];

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    public function split(array $nodes): DocumentPlan
    {
        foreach ($nodes as $index => $node) {
            if ($node['type'] !== 'for') {
                continue;
            }

            $source = implode('.', $node['expr']->path);
            if (!in_array($source, self::PRODUCT_SOURCES, true)) {
                continue;
            }

            return new DocumentPlan(
                array_slice($nodes, 0, $index),
                $node['body'],
                array_slice($nodes, $index + 1),
                (string) $node['alias'],
                true
            );
        }

        return new DocumentPlan([], $nodes, [], 'product', false);
    }
}
