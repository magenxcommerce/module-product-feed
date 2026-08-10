<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

use Magenx\ProductFeed\Model\Template\Exception\TemplateSyntaxException;

/**
 * Turns the flat token list into a nested node tree.
 *
 * Node shapes (plain arrays - cheap to build, cheap to cache, and there are only
 * four of them):
 *   ['type' => 'text', 'value' => string]
 *   ['type' => 'var',  'expr' => Expression]
 *   ['type' => 'for',  'alias' => string, 'expr' => Expression, 'body' => Node[]]
 *   ['type' => 'if',   'branches' => [['test' => ?Test, 'body' => Node[]], ...]]
 *
 * A branch with test === null is the {% else %}.
 */
class Compiler
{
    private const OPERATORS = ['==', '!=', '>=', '<=', '>', '<', 'contains'];

    public function __construct(
        private readonly Lexer $lexer,
        private readonly ExpressionParser $expressionParser
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws TemplateSyntaxException
     */
    public function compile(string $source): array
    {
        $tokens = $this->lexer->tokenize($source);
        $position = 0;

        $nodes = $this->compileBody($tokens, $position, null);

        if ($position < count($tokens)) {
            $token = $tokens[$position];
            throw new TemplateSyntaxException(
                sprintf('Unexpected {%% %s %%} on line %d.', $token['value'], $token['line'])
            );
        }

        return $nodes;
    }

    /**
     * @param array<int, array{type: string, value: string, line: int}> $tokens
     * @param string[]|null $stopAt Tag keywords that end this body
     * @return array<int, array<string, mixed>>
     * @throws TemplateSyntaxException
     */
    private function compileBody(array $tokens, int &$position, ?array $stopAt): array
    {
        $nodes = [];
        $count = count($tokens);

        while ($position < $count) {
            $token = $tokens[$position];

            if ($token['type'] === Lexer::T_TEXT) {
                $position++;
                if ($token['value'] !== '') {
                    $nodes[] = ['type' => 'text', 'value' => $token['value']];
                }
                continue;
            }

            if ($token['type'] === Lexer::T_VAR) {
                $position++;
                $nodes[] = ['type' => 'var', 'expr' => $this->expressionParser->parse($token['value'])];
                continue;
            }

            $keyword = $this->keyword($token['value']);

            if ($stopAt !== null && in_array($keyword, $stopAt, true)) {
                return $nodes;
            }

            switch ($keyword) {
                case 'for':
                    $position++;
                    $nodes[] = $this->compileFor($token, $tokens, $position);
                    break;
                case 'if':
                    $position++;
                    $nodes[] = $this->compileIf($token, $tokens, $position);
                    break;
                default:
                    throw new TemplateSyntaxException(
                        sprintf('Unknown tag "%s" on line %d.', $keyword, $token['line'])
                    );
            }
        }

        if ($stopAt !== null) {
            throw new TemplateSyntaxException(
                sprintf('Missing {%% %s %%} before end of template.', implode(' / ', $stopAt))
            );
        }

        return $nodes;
    }

    /**
     * @param array{type: string, value: string, line: int} $token
     * @param array<int, array{type: string, value: string, line: int}> $tokens
     * @return array<string, mixed>
     * @throws TemplateSyntaxException
     */
    private function compileFor(array $token, array $tokens, int &$position): array
    {
        if (preg_match('/^for\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+in\s+(.+)$/s', $token['value'], $m) !== 1) {
            throw new TemplateSyntaxException(
                sprintf('Malformed for-loop on line %d. Expected {%% for x in y %%}.', $token['line'])
            );
        }

        $body = $this->compileBody($tokens, $position, ['endfor']);
        $position++; // consume endfor

        return [
            'type' => 'for',
            'alias' => $m[1],
            'expr' => $this->expressionParser->parse(trim($m[2])),
            'body' => $body,
        ];
    }

    /**
     * @param array{type: string, value: string, line: int} $token
     * @param array<int, array{type: string, value: string, line: int}> $tokens
     * @return array<string, mixed>
     * @throws TemplateSyntaxException
     */
    private function compileIf(array $token, array $tokens, int &$position): array
    {
        $branches = [];
        $test = $this->parseTest(substr(trim($token['value']), 3), $token['line']);

        while (true) {
            $body = $this->compileBody($tokens, $position, ['elsif', 'elseif', 'else', 'endif']);
            $branches[] = ['test' => $test, 'body' => $body];

            $next = $tokens[$position] ?? null;
            if ($next === null) {
                throw new TemplateSyntaxException('Missing {% endif %} before end of template.');
            }

            $keyword = $this->keyword($next['value']);
            $position++;

            if ($keyword === 'endif') {
                break;
            }

            if ($keyword === 'else') {
                $elseBody = $this->compileBody($tokens, $position, ['endif']);
                $branches[] = ['test' => null, 'body' => $elseBody];
                $position++; // consume endif
                break;
            }

            // elsif / elseif - both spellings accepted, because templates in the
            // wild use each and a merchant should not have to know which.
            $rest = trim(substr(trim($next['value']), strlen($keyword)));
            $test = $this->parseTest($rest, $next['line']);
        }

        return ['type' => 'if', 'branches' => $branches];
    }

    /**
     * @return array{left: Expression, operator: ?string, right: ?Expression}
     * @throws TemplateSyntaxException
     */
    private function parseTest(string $source, int $line): array
    {
        $source = trim($source);
        if ($source === '') {
            throw new TemplateSyntaxException(sprintf('Empty condition on line %d.', $line));
        }

        foreach (self::OPERATORS as $operator) {
            $pattern = $operator === 'contains'
                ? '/\s+contains\s+/'
                : '/\s*' . preg_quote($operator, '/') . '\s*/';

            $parts = preg_split($pattern, $source, 2);
            if (is_array($parts) && count($parts) === 2) {
                return [
                    'left' => $this->expressionParser->parse(trim($parts[0])),
                    'operator' => $operator,
                    'right' => $this->expressionParser->parse(trim($parts[1])),
                ];
            }
        }

        return [
            'left' => $this->expressionParser->parse($source),
            'operator' => null,
            'right' => null,
        ];
    }

    private function keyword(string $tagBody): string
    {
        $trimmed = trim($tagBody);
        $space = strpos($trimmed, ' ');

        return strtolower($space === false ? $trimmed : substr($trimmed, 0, $space));
    }
}
