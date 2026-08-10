<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Writer;

use Magenx\ProductFeed\Model\Feed;

/**
 * Generic record-mode XML.
 *
 * This writer is for a feed that has a FIELD MAP but no hand-written template -
 * it wraps each record in <item> and each column in its own element. A feed with
 * a template does not reach a writer at all: the template is the serialization,
 * and it is rendered directly (see DocumentSplitter). Both paths exist because
 * push catalog APIs need records, and a free-form template has no record
 * equivalent.
 *
 * Values are wrapped in CDATA rather than escaped. Product names and
 * descriptions routinely contain "&" and "<", and a raw "&" alone is enough to
 * make the whole document unparseable - which a marketplace reports as "feed
 * could not be read", with no indication of which product caused it. The one
 * sequence CDATA itself cannot carry, "]]>", is split across two sections.
 */
class XmlWriter implements WriterInterface
{
    private const ROOT_ELEMENT = 'items';
    private const ITEM_ELEMENT = 'item';

    public function open(Feed $feed, array $columns): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<' . self::ROOT_ELEMENT . '>' . "\n";
    }

    public function writeRecord(Feed $feed, array $record, array $columns): string
    {
        $out = '  <' . self::ITEM_ELEMENT . '>' . "\n";

        foreach ($columns as $column) {
            $tag = $this->tagName($column);
            $out .= '    <' . $tag . '>' . $this->cdata($this->stringify($record[$column] ?? '')) . '</' . $tag . '>' . "\n";
        }

        return $out . '  </' . self::ITEM_ELEMENT . '>' . "\n";
    }

    public function close(Feed $feed): string
    {
        return '</' . self::ROOT_ELEMENT . '>' . "\n";
    }

    public function getExtension(): string
    {
        return 'xml';
    }

    /**
     * Coerce a column name into a legal XML element name.
     *
     * A namespaced name such as "g:price" is kept intact, because that is exactly
     * what the shopping feeds want.
     */
    private function tagName(string $column): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_:.-]/', '_', trim($column));

        // An XML name may not begin with a digit, a dot, a hyphen, or "xml".
        if ($name === '' || preg_match('/^[A-Za-z_]/', $name) !== 1) {
            $name = 'field_' . $name;
        }

        return $name;
    }

    private function cdata(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Strip control characters that are illegal in XML 1.0 at any escaping
        // level - CDATA does not make them legal, and one of them invalidates the
        // entire document.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        $value = str_replace(']]>', ']]]]><![CDATA[>', $value);

        return '<![CDATA[' . $value . ']]>';
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
