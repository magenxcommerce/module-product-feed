<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Writer;

use Magenx\ProductFeed\Model\Feed;

/**
 * Newline-delimited JSON: one complete JSON object per line.
 *
 * Chosen over a single JSON array on purpose. An array has to be closed, so a
 * consumer cannot begin parsing until the last product is written and a run
 * interrupted mid-file leaves an unparseable document. JSONL is valid after
 * every line, which is what suits a streamed, resumable export - and it is what
 * the ingestion side of most modern catalog APIs wants anyway.
 */
class JsonlWriter implements WriterInterface
{
    public function open(Feed $feed, array $columns): string
    {
        return '';
    }

    public function writeRecord(Feed $feed, array $record, array $columns): string
    {
        $payload = [];
        foreach ($columns as $column) {
            $payload[$column] = $record[$column] ?? '';
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // json_encode returning false would silently drop a product. Emitting an
        // empty line instead would corrupt the record count, so skip the row and
        // let validation report the gap.
        return $encoded === false ? '' : $encoded . "\n";
    }

    public function close(Feed $feed): string
    {
        return '';
    }

    public function getExtension(): string
    {
        return 'jsonl';
    }
}
