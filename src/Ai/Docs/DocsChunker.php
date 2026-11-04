<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Rhapsody\Core\Support\Fs;

/**
 * Turns the docs Twig templates into clean, searchable chunks.
 *
 * The templates are mostly plain HTML inside a `doc_content` block, with a
 * little Twig on top (`{% verbatim %}` around code samples, `{{ base_url }}`
 * in links). Rather than rendering them, which would need a full Twig
 * environment, theme and request, this works on the source: unwrap verbatim,
 * drop the remaining Twig syntax, parse the HTML, and split on headings.
 *
 * Output is plain text with ``` fenced code, so the same chunks serve keyword
 * retrieval, model prompts, and (later) llms.txt and an MCP docs tool.
 */
final class DocsChunker
{
    /**
     * Elements that continue the current line of text rather than starting a block.
     */
    private const INLINE = [
        'a', 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'img', 'kbd', 'mark',
        'small', 'span', 'strong', 'sub', 'sup', 'u',
    ];

    /**
     * Elements whose content is never documentation text.
     */
    private const SKIPPED = ['script', 'style', 'svg', 'noscript', 'template'];

    public function __construct(private readonly int $maxChars = 1800)
    {
    }

    /**
     * Chunk every page template in a docs directory (files starting with "_"
     * are layouts and are skipped).
     *
     * @return list<DocChunk>
     */
    public function chunkDirectory(string $directory, DocsUrlMap $urls): array
    {
        $files = glob(rtrim($directory, '/\\') . '/*.twig');
        if ($files === false) {
            return [];
        }
        sort($files);

        $chunks = [];
        foreach ($files as $file) {
            $page = basename($file, '.twig');
            if (str_starts_with($page, '_')) {
                continue;
            }

            foreach ($this->chunkSource($page, $urls->urlFor($page), Fs::read($file)) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * @return list<DocChunk>
     */
    public function chunkSource(string $page, string $url, string $twigSource): array
    {
        $html = $this->twigToHtml(str_replace("\r\n", "\n", $twigSource));
        $body = $this->parse($html);
        if ($body === null) {
            return [];
        }

        $builder = new ChunkBuilder($page, $url, $this->maxChars);
        $this->walk($body, $builder);

        return $builder->finish();
    }

    /**
     * Reduce a template to the HTML a visitor would see: keep verbatim
     * content, evaluate trivial string-literal expressions, drop all other Twig.
     */
    private function twigToHtml(string $twig): string
    {
        $body = $twig;
        if (preg_match('/\{%-?\s*block\s+doc_content\s*-?%\}(.*)\{%-?\s*endblock[^%]*%\}/s', $twig, $match) === 1) {
            $body = $match[1];
        }

        // Park verbatim regions behind placeholders so the Twig stripping
        // below cannot touch the code samples they protect.
        $verbatim = [];
        $body     = preg_replace_callback(
            '/\{%-?\s*verbatim\s*-?%\}(.*?)\{%-?\s*endverbatim\s*-?%\}/s',
            static function (array $m) use (&$verbatim): string {
                $key            = '@@RHVERB' . count($verbatim) . '@@';
                $verbatim[$key] = $m[1];

                return $key;
            },
            $body
        ) ?? $body;

        // Comments, tags, `{{ 'literal' }}` (kept as its text) and any other
        // expression (dropped), matched in one left-to-right pass.
        $body = preg_replace_callback(
            '/\{#.*?#\}|\{%.*?%\}|\{\{\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*\}\}|\{\{.*?\}\}/s',
            static fn (array $m): string => isset($m[2]) ? stripslashes($m[2]) : '',
            $body
        ) ?? $body;

        $body = strtr($body, $verbatim);

        // libxml parses HTML 4, so the HTML5 named entities for braces would
        // survive as literal text. The docs also contain "&lcur;" and
        // "&lcurl;", which are not entities at all (browsers show them
        // verbatim): both are plainly meant as "{", and that is what the
        // chunks should carry so a model is never handed "&lcur;% block %}".
        return str_replace(['&lcub;', '&lcurl;', '&lcur;', '&rcub;'], ['{', '{', '{', '}'], $body);
    }

    private function parse(string $html): ?DOMElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $document->loadHTML(
                '<html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : null;
    }

    /**
     * Walk block-level structure, feeding headings and text blocks to the
     * builder. Inline content between blocks is gathered into paragraphs.
     */
    private function walk(DOMNode $parent, ChunkBuilder $builder): void
    {
        $run = '';

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $run .= $child->data;
                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::SKIPPED, true)) {
                continue;
            }

            if (in_array($tag, self::INLINE, true)) {
                $run .= $this->inline($child);
                continue;
            }

            $builder->addBlock($this->collapse($run));
            $run = '';

            switch ($tag) {
                case 'h1':
                    $builder->setPageTitle($this->collapse($child->textContent));
                    break;
                case 'h2':
                case 'h3':
                    $builder->heading((int) substr($tag, 1), $this->collapse($child->textContent));
                    break;
                case 'h4':
                case 'h5':
                case 'h6':
                    $builder->addBlock('**' . $this->collapse($child->textContent) . '**');
                    break;
                case 'pre':
                    $builder->addBlock($this->code($child));
                    break;
                case 'ul':
                case 'ol':
                    $builder->addBlock($this->lines($child, 0));
                    break;
                case 'table':
                    $builder->addBlock($this->table($child));
                    break;
                case 'hr':
                    break;
                default:
                    $this->walk($child, $builder);
            }
        }

        $builder->addBlock($this->collapse($run));
    }

    /**
     * Inline text for an element: `code` keeps its backticks, everything else
     * contributes its text.
     */
    private function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->data;
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'br') {
            return ' ';
        }

        if ($tag === 'img') {
            return '';
        }

        if ($tag === 'code') {
            $text = $this->collapse($node->textContent);

            return $text === '' ? '' : '`' . $text . '`';
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->inline($child);
        }

        return $text;
    }

    private function code(DOMElement $pre): string
    {
        $language = '';
        foreach ($pre->getElementsByTagName('code') as $code) {
            if (preg_match('/\blanguage-([\w+-]+)/', $code->getAttribute('class'), $match) === 1) {
                $language = $match[1];
            }
            break;
        }

        $source = rtrim(preg_replace('/\A(?:[ \t]*\n)+/', '', $pre->textContent) ?? $pre->textContent);

        return "```{$language}\n{$source}\n```";
    }

    /**
     * Render a ul/ol (with nesting) as "- item" / "1. item" lines.
     */
    private function lines(DOMElement $list, int $depth): string
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $indent  = str_repeat('  ', $depth);
        $number  = 0;
        $out     = [];

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $number++;
            $own    = '';
            $nested = [];

            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMText) {
                    $own .= $child->data;
                    continue;
                }

                if (! $child instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($child->tagName);

                if ($tag === 'ul' || $tag === 'ol') {
                    $nested[] = $this->lines($child, $depth + 1);
                } elseif ($tag === 'pre') {
                    $nested[] = $this->indentBlock($this->code($child), $indent . '  ');
                } elseif (in_array($tag, self::SKIPPED, true)) {
                    continue;
                } else {
                    $own .= ' ' . $this->inline($child);
                }
            }

            $out[] = $indent . ($ordered ? $number . '. ' : '- ') . $this->collapse($own);
            foreach ($nested as $block) {
                $out[] = $block;
            }
        }

        return implode("\n", $out);
    }

    private function table(DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = $this->collapse($this->inline($cell));
                }
            }

            if ($cells !== []) {
                $rows[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $rows);
    }

    private function indentBlock(string $block, string $indent): string
    {
        return $indent . str_replace("\n", "\n" . $indent, $block);
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
