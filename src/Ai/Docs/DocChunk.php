<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

/**
 * One searchable section of the framework docs: a heading (or a slice of one,
 * when a long section is split) plus its prose and fenced code, as clean text.
 *
 * @phpstan-type ChunkArray array{id: string, page: string, url: string, pageTitle: string, headingPath: list<string>, text: string}
 */
final readonly class DocChunk
{
    /**
     * @param string       $id          Stable id, "<page>#<heading-slug>[-n]".
     * @param string       $page        Template name without extension ("routing").
     * @param string       $url         Public docs path ("/docs/routing").
     * @param string       $pageTitle   The page's <h1>.
     * @param list<string> $headingPath Enclosing headings, outermost first.
     * @param string       $text        Section body; code blocks are ``` fenced.
     */
    public function __construct(
        public string $id,
        public string $page,
        public string $url,
        public string $pageTitle,
        public array $headingPath,
        public string $text,
    ) {
    }

    /**
     * Human label for citations: "Page › Heading › Subheading".
     */
    public function label(): string
    {
        return implode(' › ', [$this->pageTitle, ...$this->headingPath]);
    }

    /**
     * @return ChunkArray
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'page'        => $this->page,
            'url'         => $this->url,
            'pageTitle'   => $this->pageTitle,
            'headingPath' => $this->headingPath,
            'text'        => $this->text,
        ];
    }

    /**
     * Rebuild a chunk from decoded JSON, rejecting anything malformed so a
     * corrupt index fails loudly instead of producing half-empty results.
     *
     * @param  array<mixed>      $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $path = $data['headingPath'] ?? null;
        if (! is_array($path)) {
            throw new \InvalidArgumentException('Docs index chunk has no "headingPath" list.');
        }

        $headingPath = [];
        foreach ($path as $heading) {
            if (! is_string($heading)) {
                throw new \InvalidArgumentException('Docs index chunk has a non-string heading.');
            }
            $headingPath[] = $heading;
        }

        return new self(
            id: self::requireString($data, 'id'),
            page: self::requireString($data, 'page'),
            url: self::requireString($data, 'url'),
            pageTitle: self::requireString($data, 'pageTitle'),
            headingPath: $headingPath,
            text: self::requireString($data, 'text'),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException("Docs index chunk is missing string field \"{$key}\".");
        }

        return $value;
    }
}
