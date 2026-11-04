<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

use Rhapsody\Core\Support\Fs;

/**
 * In-memory BM25 keyword index over the bundled docs chunks. Pure PHP, no
 * services, no network: this is what makes `rhapsody ask` useful with no API key.
 *
 * Headings, page title and page name are weighted above body text, so a
 * question that names a feature ("middleware", "route group") lands on the
 * section about it rather than one that merely mentions it.
 */
final class DocsIndex
{
    private const K1 = 1.5;
    private const B  = 0.75;

    private const WEIGHT_BODY    = 1.0;
    private const WEIGHT_HEADING = 3.0;
    private const WEIGHT_TITLE   = 2.0;

    /**
     * @var list<DocChunk>
     */
    private array $chunks;

    /**
     * term => [chunk index => weighted term frequency]
     *
     * @var array<string, array<int, float>>
     */
    private array $postings = [];

    /**
     * Weighted length of each chunk, by chunk index.
     *
     * @var list<float>
     */
    private array $lengths = [];

    private float $averageLength = 1.0;

    /**
     * @param list<DocChunk> $chunks
     */
    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;

        $total = 0.0;
        foreach ($chunks as $i => $chunk) {
            $length = 0.0;

            foreach ($this->weightedTerms($chunk) as $term => $weight) {
                $this->postings[$term][$i] = $weight;
                $length                   += $weight;
            }

            $this->lengths[] = $length;
            $total          += $length;
        }

        if ($chunks !== []) {
            $this->averageLength = max($total / count($chunks), 1.0);
        }
    }

    /**
     * Where the docs index ships inside the package.
     */
    public static function defaultPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/docs-index/chunks.json';
    }

    /**
     * @throws \RuntimeException           If the file is missing or unreadable.
     * @throws \InvalidArgumentException   If it is not a valid docs index.
     */
    public static function fromFile(string $path): self
    {
        $data = json_decode(Fs::read($path), true);
        if (! is_array($data) || ! is_array($data['chunks'] ?? null)) {
            throw new \InvalidArgumentException("{$path} is not a valid docs index.");
        }

        $chunks = [];
        foreach ($data['chunks'] as $row) {
            if (! is_array($row)) {
                throw new \InvalidArgumentException("{$path} contains a malformed chunk.");
            }
            $chunks[] = DocChunk::fromArray($row);
        }

        return new self($chunks);
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function search(string $query, int $limit = 4): SearchResult
    {
        $terms = array_values(array_unique(Tokenizer::tokenize($query)));
        if ($terms === [] || $this->chunks === []) {
            return new SearchResult([], false, $terms);
        }

        $total   = count($this->chunks);
        $scores  = [];
        $matched = [];

        foreach ($terms as $term) {
            $postings = $this->postings[$term] ?? [];
            if ($postings === []) {
                continue;
            }

            $idf = log(1 + ($total - count($postings) + 0.5) / (count($postings) + 0.5));

            foreach ($postings as $i => $tf) {
                $norm       = $tf + self::K1 * (1 - self::B + self::B * $this->lengths[$i] / $this->averageLength);
                $scores[$i] = ($scores[$i] ?? 0.0) + $idf * ($tf * (self::K1 + 1)) / $norm;
                $matched[$i] = ($matched[$i] ?? 0) + 1;
            }
        }

        if ($scores === []) {
            return new SearchResult([], false, $terms);
        }

        $ranked = array_keys($scores);
        usort($ranked, fn (int $a, int $b): int => [$scores[$b], $this->chunks[$a]->id] <=> [$scores[$a], $this->chunks[$b]->id]);

        $hits = [];
        foreach (array_slice($ranked, 0, max(1, $limit)) as $i) {
            $hits[] = new SearchHit($this->chunks[$i], $scores[$i], $matched[$i], count($terms));
        }

        $confident = $hits[0]->matchedTerms >= max(1, (int) ceil(count($terms) / 2));

        return new SearchResult($hits, $confident, $terms);
    }

    /**
     * @return array<string, float> term => weighted frequency
     */
    private function weightedTerms(DocChunk $chunk): array
    {
        $weights = [];

        $add = static function (string $text, float $weight) use (&$weights): void {
            foreach (Tokenizer::tokenize($text) as $term) {
                $weights[$term] = ($weights[$term] ?? 0.0) + $weight;
            }
        };

        $add($chunk->text, self::WEIGHT_BODY);
        $add(implode(' ', $chunk->headingPath), self::WEIGHT_HEADING);
        $add($chunk->pageTitle . ' ' . $chunk->page, self::WEIGHT_TITLE);

        return $weights;
    }
}
