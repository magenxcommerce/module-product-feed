<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Writer;

use Magenx\ProductFeed\Model\Feed;

/**
 * Tab-delimited text.
 *
 * The delimiter is forced to a tab rather than read from the feed, because the
 * format IS the delimiter here - a "TSV" whose feed row happens to carry a comma
 * would be a CSV with the wrong extension, and the consumers that ask for TSV
 * (the agentic-commerce product feed among them) reject it.
 *
 * Enclosure is likewise forced off: tab-delimited consumers overwhelmingly do
 * not expect quoting, and the inherited sanitiser already strips tabs and
 * newlines out of values, which is what makes that safe.
 */
class TsvWriter extends CsvWriter
{
    public function getExtension(): string
    {
        return 'tsv';
    }

    protected function getDelimiter(Feed $feed): string
    {
        return "\t";
    }

    /**
     * @param string[] $values
     */
    protected function row(Feed $feed, array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $cells[] = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        }

        return implode("\t", $cells) . "\n";
    }
}
