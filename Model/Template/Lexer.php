<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

/**
 * Splits template source into a flat token list.
 *
 * Three token kinds only:
 *   text   literal output
 *   var    {{ expression }}
 *   tag    {% for ... %} / {% if ... %} / {% else %} / {% endfor %} ...
 *
 * The lexer is deliberately dumb: it does not understand `for` or `if`, only
 * that something sits between the delimiters. Nesting is the compiler's job.
 */
class Lexer
{
    public const T_TEXT = 'text';
    public const T_VAR = 'var';
    public const T_TAG = 'tag';

    /**
     * @return array<int, array{type: string, value: string, line: int}>
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($source);
        $line = 1;

        while ($offset < $length) {
            $varPos = strpos($source, '{{', $offset);
            $tagPos = strpos($source, '{%', $offset);

            $next = $this->earliest($varPos, $tagPos);

            if ($next === null) {
                $text = substr($source, $offset);
                $tokens[] = ['type' => self::T_TEXT, 'value' => $text, 'line' => $line];
                break;
            }

            if ($next > $offset) {
                $text = substr($source, $offset, $next - $offset);
                $tokens[] = ['type' => self::T_TEXT, 'value' => $text, 'line' => $line];
                $line += substr_count($text, "\n");
            }

            $isVar = $next === $varPos;
            $close = $isVar ? '}}' : '%}';
            $closePos = strpos($source, $close, $next + 2);

            if ($closePos === false) {
                // An unterminated delimiter is emitted as literal text rather than
                // throwing: a feed template is merchant-authored and a stray "{{" in
                // a product description must not take the whole export down.
                $tokens[] = ['type' => self::T_TEXT, 'value' => substr($source, $next), 'line' => $line];
                break;
            }

            $inner = trim(substr($source, $next + 2, $closePos - $next - 2));
            $tokens[] = [
                'type' => $isVar ? self::T_VAR : self::T_TAG,
                'value' => $inner,
                'line' => $line,
            ];

            $raw = substr($source, $next, $closePos + 2 - $next);
            $line += substr_count($raw, "\n");
            $offset = $closePos + 2;
        }

        return $tokens;
    }

    private function earliest(int|false $a, int|false $b): ?int
    {
        if ($a === false && $b === false) {
            return null;
        }
        if ($a === false) {
            return (int) $b;
        }
        if ($b === false) {
            return (int) $a;
        }

        return min((int) $a, (int) $b);
    }
}
