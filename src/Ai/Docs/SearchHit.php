<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

/**
 * One ranked retrieval result.
 */
final readonly class SearchHit
{
    /**
     * @param float $score        BM25 score (comparable only within one search).
     * @param int   $matchedTerms How many distinct query terms this chunk contains.
     * @param int   $queryTerms   How many distinct terms the query had.
     */
    public function __construct(
        public DocChunk $chunk,
        public float $score,
        public int $matchedTerms,
        public int $queryTerms,
    ) {
    }

    /**
     * Share of the query's terms found in this chunk, 0.0 to 1.0.
     */
    public function coverage(): float
    {
        return $this->queryTerms === 0 ? 0.0 : $this->matchedTerms / $this->queryTerms;
    }
}
