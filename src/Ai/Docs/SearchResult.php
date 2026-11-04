<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

/**
 * The outcome of a docs search.
 */
final readonly class SearchResult
{
    /**
     * @param list<SearchHit> $hits      Best first.
     * @param bool            $confident True when the top hit contains at least half of the
     *                                   query's terms: a cheap signal that the docs actually
     *                                   address the question rather than brushing past it.
     * @param list<string>    $terms     The distinct normalized query terms that were searched.
     */
    public function __construct(
        public array $hits,
        public bool $confident,
        public array $terms,
    ) {
    }
}
