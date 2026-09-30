<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Writer;

use Magenx\ProductFeed\Model\Config\Source\Delimiter;
use Magenx\ProductFeed\Model\Config\Source\Enclosure;
use Magenx\ProductFeed\Model\Feed;

/**
 * Delimited text: CSV, and the base for TSV.
 *
 * Two behaviours here are load-bearing and easy to regress:
 *
 *  1. EVERY column is emitted for every row, including trailing ones the product
 *     left empty. Trimming them produces a final row shorter than the header,
 *     and a consumer reading values by column position then misreads or rejects
 *     that product - a bug that only ever shows up on the last row of the file.
 *
 *  2. Newlines and the delimiter are neutralised inside a value. A product
 *     description containing a line break silently splits one product into two
 *     malformed rows otherwise, and enclosure alone does not save it on the many
 *     consumers that split on newline before parsing quotes.
 */
class CsvWriter implements WriterInterface
{
    public function __construct(
        private readonly ValueTyper $valueTyper
    ) {
    }

    public function open(Feed $feed, array $columns): string
    {
        $prefix = $feed->getData('csv_bom') ? "\xEF\xBB\xBF" : '';

        if (!$feed->getData('csv_include_header')) {
            return $prefix;
        }

        return $prefix . $this->row($feed, $columns);
    }

    public function writeRecord(Feed $feed, array $record, array $columns): string
    {
        $types = $feed->getColumnTypes();

        $values = [];
        foreach ($columns as $column) {
            $values[] = $this->typed(
                $this->stringify($record[$column] ?? ''),
                $types[$column] ?? ValueTyper::TYPE_STRING
            );
        }

        return $this->row($feed, $values);
    }

    /**
     * The delimited spelling of a typed column.
     *
     * A flag becomes lowercase true / false (never PHP's 1 / 0, which the
     * agentic-commerce feed rejects for a boolean), and a list becomes one
     * comma-separated cell with any comma inside an item percent-encoded, so a
     * URL containing one does not split into two. JSON objects stay serialized
     * JSON, which is how a delimited cell carries them.
     */
    private function typed(string $value, string $type): string
    {
        if ($type === ValueTyper::TYPE_BOOL) {
            return $this->valueTyper->toDelimitedBool($value);
        }

        if ($type === ValueTyper::TYPE_LIST) {
            $list = $this->valueTyper->convert($value, ValueTyper::TYPE_LIST);
            if (is_array($list)) {
                return implode(',', array_map(
                    fn (mixed $item): string => str_replace(',', '%2C', $this->stringify($item)),
                    $list
                ));
            }
        }

        return $value;
    }

    public function close(Feed $feed): string
    {
        return '';
    }

    public function getExtension(): string
    {
        return 'csv';
    }

    /**
     * @param string[] $values
     */
    protected function row(Feed $feed, array $values): string
    {
        $delimiter = $this->getDelimiter($feed);
        $enclosure = $this->getEnclosure($feed);

        $cells = [];
        foreach ($values as $value) {
            $cells[] = $this->cell($this->sanitize($value, $delimiter, $enclosure), $delimiter, $enclosure);
        }

        return implode($delimiter, $cells) . "\n";
    }

    /**
     * Delimiter and enclosure are stored as CODES, not literal characters - see
     * Config\Source\Delimiter for why the literals must never reach the schema.
     */
    protected function getDelimiter(Feed $feed): string
    {
        return Delimiter::toCharacter((string) $feed->getData('csv_delimiter'));
    }

    protected function getEnclosure(Feed $feed): string
    {
        return Enclosure::toCharacter((string) $feed->getData('csv_enclosure'));
    }

    /**
     * Collapse anything that would break row or column boundaries.
     *
     * With no enclosure configured the delimiter itself must go too, otherwise a
     * value containing it silently creates an extra column.
     */
    private function sanitize(string $value, string $delimiter, string $enclosure): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        if ($enclosure === '') {
            $value = str_replace($delimiter, ' ', $value);
        }

        return $this->neutralizeFormula($value);
    }

    /**
     * Defuse CSV/formula injection.
     *
     * A value beginning with =, +, -, @, tab or CR is executed as a formula by
     * Excel/Sheets when the file is opened rather than displayed as text - a
     * product review or attribute value is enough to reach this, since neither
     * is validated as "safe spreadsheet text" anywhere upstream. Prefixing with
     * an apostrophe forces text interpretation without changing the visible
     * value in any spreadsheet application.
     */
    protected function neutralizeFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Enclose unconditionally when an enclosure is configured.
     *
     * Quoting only the cells that "need" it saves a few bytes per row and costs a
     * support ticket the first time a value begins with a space, so it is not
     * worth the conditional.
     */
    private function cell(string $value, string $delimiter, string $enclosure): string
    {
        if ($enclosure === '') {
            return $value;
        }

        return $enclosure . str_replace($enclosure, $enclosure . $enclosure, $value) . $enclosure;
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
