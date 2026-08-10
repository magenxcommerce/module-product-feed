<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Writer;

use Magenx\ProductFeed\Model\Feed;

/**
 * Serializes a stream of records into a feed file.
 *
 * Writers are used in RECORD mode only - a feed whose output is one record per
 * product, described by a field map. A free-form XML template is not written by
 * a writer: the template itself is the serialization, and it is rendered
 * directly (see DocumentSplitter).
 *
 * Every method returns a chunk of text rather than writing to a handle, so the
 * runner owns all I/O and a writer stays trivially testable.
 *
 * A new format is a class plus one <item> in di.xml. Never special-case a format
 * inside the runner.
 */
interface WriterInterface
{
    /**
     * Chunk emitted once before the first record.
     *
     * @param string[] $columns
     */
    public function open(Feed $feed, array $columns): string;

    /**
     * Chunk emitted for one product.
     *
     * @param array<string, mixed> $record
     * @param string[] $columns Authoritative column order. A writer MUST emit every
     *        one of these, including the trailing ones the record leaves empty: a
     *        consumer reading values by column position misreads or rejects a row
     *        that is short.
     */
    public function writeRecord(Feed $feed, array $record, array $columns): string;

    /**
     * Chunk emitted once after the last record.
     */
    public function close(Feed $feed): string;

    /**
     * File extension this writer produces, without the dot.
     */
    public function getExtension(): string;
}
