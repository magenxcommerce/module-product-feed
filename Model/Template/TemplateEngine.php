<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template;

use Magenx\ProductFeed\Model\Template\Exception\TemplateSyntaxException;

/**
 * Facade over lex -> compile -> analyze -> render, with a per-instance compile
 * cache.
 *
 * The cache is what makes a per-product template affordable: a feed body is
 * compiled once and then rendered once per product, so a 50k-product export
 * parses the template once rather than 50,000 times. Keyed on a hash of the
 * source, so editing a template in the admin invalidates it for free.
 */
class TemplateEngine
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $compiled = [];

    public function __construct(
        private readonly Compiler $compiler,
        private readonly Renderer $renderer,
        private readonly Parser $parser
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws TemplateSyntaxException
     */
    public function compile(string $source): array
    {
        // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative -- in-memory cache key for compiled templates, not a security digest.
        $key = md5($source);

        return $this->compiled[$key] ??= $this->compiler->compile($source);
    }

    /**
     * @throws TemplateSyntaxException
     */
    public function render(string $source, RenderContext $context): string
    {
        return $this->renderer->render($this->compile($source), $context);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    public function renderCompiled(array $nodes, RenderContext $context): string
    {
        return $this->renderer->render($nodes, $context);
    }

    /**
     * @throws TemplateSyntaxException
     */
    public function analyze(string $source): Requirements
    {
        return $this->parser->analyze($this->compile($source));
    }

    /**
     * Merge the requirements of several sources - a feed's body plus every
     * expression in its field map, plus its file name, which is itself a template.
     *
     * @param string[] $sources
     * @throws TemplateSyntaxException
     */
    public function analyzeAll(array $sources): Requirements
    {
        $combined = '';
        foreach ($sources as $source) {
            if (trim($source) !== '') {
                $combined .= $source . "\n";
            }
        }

        return $this->analyze($combined);
    }
}
