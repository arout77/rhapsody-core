<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

/**
 * Accumulates blocks of text under the current heading and cuts them into
 * DocChunks. Internal to DocsChunker: it holds per-page mutable state, so a
 * fresh one is made for every page.
 *
 * @internal
 */
final class ChunkBuilder
{
    private const MIN_CHARS = 20;

    /**
     * @var list<DocChunk>
     */
    private array $chunks = [];

    private string $pageTitle;

    /**
     * @var list<string>
     */
    private array $headingPath = [];

    /**
     * @var list<string>
     */
    private array $blocks = [];

    private int $length = 0;

    /**
     * @var array<string, int>
     */
    private array $slugCounts = [];

    public function __construct(
        private readonly string $page,
        private readonly string $url,
        private readonly int $maxChars,
    ) {
        $this->pageTitle = ucfirst(str_replace('-', ' ', $page));
    }

    public function setPageTitle(string $title): void
    {
        $this->flush();
        if ($title !== '') {
            $this->pageTitle = $title;
        }
    }

    /**
     * Start a new section. Level 2 resets the path; level 3 nests under the
     * current level-2 heading.
     */
    public function heading(int $level, string $text): void
    {
        $this->flush();

        if ($level <= 2) {
            $this->headingPath = [$text];

            return;
        }

        $parent            = $this->headingPath[0] ?? null;
        $this->headingPath = $parent !== null ? [$parent, $text] : [$text];
    }

    public function addBlock(string $block): void
    {
        $block = trim($block);
        if ($block === '') {
            return;
        }

        // Start a continuation chunk rather than letting one section grow
        // past the budget. A single oversized block (a long code listing) is
        // kept whole: splitting code mid-way helps nobody.
        if ($this->length > 0 && $this->length + strlen($block) > $this->maxChars) {
            $this->flush();
        }

        $this->blocks[] = $block;
        $this->length  += strlen($block);
    }

    /**
     * @return list<DocChunk>
     */
    public function finish(): array
    {
        $this->flush();

        return $this->chunks;
    }

    private function flush(): void
    {
        $text         = trim(implode("\n\n", $this->blocks));
        $this->blocks = [];
        $this->length = 0;

        if (strlen($text) < self::MIN_CHARS) {
            return;
        }

        $slug = self::slug(implode(' ', $this->headingPath));
        if ($slug === '') {
            $slug = 'intro';
        }

        $count            = ($this->slugCounts[$slug] ?? 0) + 1;
        $this->slugCounts[$slug] = $count;

        $this->chunks[] = new DocChunk(
            id: $this->page . '#' . $slug . ($count > 1 ? '-' . $count : ''),
            page: $this->page,
            url: $this->url,
            pageTitle: $this->pageTitle,
            headingPath: $this->headingPath,
            text: $text,
        );
    }

    private static function slug(string $text): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? ''), '-');
    }
}
