<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * Parses the inside of a {{ ... }} into an Expression.
 *
 * Grammar:
 *   expression := ( literal | path ) ( "|" filter )*
 *   path       := segment ( "." segment )* ( "[" digits "]" )? ( ":" token )?
 *   filter     := name ( ":" arg ( "," arg )* )?
 *   arg        := quoted-string | number | bare-token
 *
 * There is no operator support and no function-call syntax, on purpose. A
 * template is data: the only thing it can name is a field and a filter from a
 * fixed table. See Renderer's class docblock.
 */
class ExpressionParser
{
    public function parse(string $source): Expression
    {
        $parts = $this->splitOnPipes($source);
        $head = trim((string) array_shift($parts));

        $filters = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $filters[] = $this->parseFilter($part);
        }

        if ($this->isQuoted($head)) {
            return new Expression([], $filters, null, null, $this->unquote($head), true);
        }

        if (is_numeric($head)) {
            return new Expression([], $filters, null, null, $head + 0, true);
        }

        $index = null;
        if (preg_match('/^(.*)\[(\d+)]$/', $head, $m) === 1) {
            $head = $m[1];
            $index = $m[2];
        }

        $selector = null;
        if (str_contains($head, ':')) {
            [$head, $selector] = explode(':', $head, 2);
        }

        $path = array_values(array_filter(explode('.', trim($head)), static fn (string $s): bool => $s !== ''));

        return new Expression($path, $filters, $index, $selector === null ? null : trim($selector));
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function parseFilter(string $source): array
    {
        if (!str_contains($source, ':')) {
            return [trim($source), []];
        }

        [$name, $argSource] = explode(':', $source, 2);

        $args = [];
        foreach ($this->splitArgs($argSource) as $arg) {
            $arg = trim($arg);
            if ($arg === '') {
                continue;
            }
            if ($this->isQuoted($arg)) {
                $args[] = $this->unquote($arg);
            } elseif (is_numeric($arg)) {
                $args[] = $arg + 0;
            } else {
                $args[] = $arg;
            }
        }

        return [trim($name), $args];
    }

    /**
     * Split on "|" that is not inside a quoted string, so a filter argument like
     * replace:"a|b","c" survives.
     *
     * @return string[]
     */
    private function splitOnPipes(string $source): array
    {
        return $this->splitOutsideQuotes($source, '|');
    }

    /**
     * @return string[]
     */
    private function splitArgs(string $source): array
    {
        return $this->splitOutsideQuotes($source, ',');
    }

    /**
     * @return string[]
     */
    private function splitOutsideQuotes(string $source, string $delimiter): array
    {
        $out = [];
        $buffer = '';
        $quote = null;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $source[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === $delimiter) {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $out[] = $buffer;

        return $out;
    }

    private function isQuoted(string $value): bool
    {
        return strlen($value) >= 2
            && (
                ($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'"))
            );
    }

    private function unquote(string $value): string
    {
        $inner = substr($value, 1, -1);

        return str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $inner);
    }
}
