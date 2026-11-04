<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

/**
 * Everything `ask` knows about one question, ready to render or serialize.
 *
 * @phpstan-type SourceArray array{n: int, id: string, label: string, url: string, score: float, cited: bool, text: string}
 * @phpstan-type ResultArray array{question: string, answer: string|null, confident: bool, gap: string|null, notices: list<string>, sources: list<SourceArray>}
 */
final readonly class AskResult
{
    /**
     * @param list<SearchHit> $hits      The sections retrieved, in the order they were numbered [1], [2], ...
     * @param list<int>       $cited     1-based numbers of the sections the AI answer cited.
     * @param list<string>    $notices   Plain-language caveats (AI skipped, rate limited, weak match, ...).
     * @param string|null     $gapReason Why the docs look weak for this question, or null if they don't.
     */
    public function __construct(
        public string $question,
        public array $hits,
        public bool $confident,
        public ?string $answer,
        public array $cited,
        public array $notices,
        public ?string $gapReason,
    ) {
    }

    /**
     * @return ResultArray
     */
    public function toArray(): array
    {
        $sources = [];
        foreach ($this->hits as $i => $hit) {
            $n         = $i + 1;
            $sources[] = [
                'n'     => $n,
                'id'    => $hit->chunk->id,
                'label' => $hit->chunk->label(),
                'url'   => $hit->chunk->url,
                'score' => round($hit->score, 3),
                'cited' => in_array($n, $this->cited, true),
                'text'  => $hit->chunk->text,
            ];
        }

        return [
            'question'  => $this->question,
            'answer'    => $this->answer,
            'confident' => $this->confident,
            'gap'       => $this->gapReason,
            'notices'   => $this->notices,
            'sources'   => $sources,
        ];
    }
}
