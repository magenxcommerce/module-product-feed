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
 *
 * VALUES ARE TYPED. Each column is converted to the type its field map row
 * declares (see ValueTyper), so a flag is written as JSON true, a count as a
 * number and `variant_dict` as an object - a JSON consumer reads "true" as a
 * string. With the feed's omit-empty option on, a column that rendered empty is
 * left out of the line entirely, which is what "omit unknown optional values"
 * means to a consumer such as the agentic-commerce feed.
 */
class JsonlWriter implements WriterInterface
{
    public function __construct(
        private readonly ValueTyper $valueTyper
    ) {
    }

    public function open(Feed $feed, array $columns): string
    {
        return '';
    }

    public function writeRecord(Feed $feed, array $record, array $columns): string
    {
        $types = $feed->getColumnTypes();
        $omitEmpty = $feed->shouldOmitEmpty();

        $payload = [];
        foreach ($columns as $column) {
            $value = $this->valueTyper->convert(
                $record[$column] ?? '',
                $types[$column] ?? ValueTyper::TYPE_STRING
            );

            if ($omitEmpty && $this->valueTyper->isEmpty($value)) {
                continue;
            }

            $payload[$column] = $value ?? '';
        }

        // An empty record still has to be an object, never `[]`.
        $encoded = json_encode(
            $payload === [] ? new \stdClass() : $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PRESERVE_ZERO_FRACTION
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
