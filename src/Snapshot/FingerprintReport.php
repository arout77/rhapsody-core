<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

/**
 * The result of {@see QueryFingerprint::analyze()}.
 *
 * @phpstan-type QueryGroup array{fingerprint: string, count: int, repeated: bool, total_ms: float, example_sql: string, callers: list<string>}
 */
final readonly class FingerprintReport
{
    /**
     * @param list<array<string, mixed>> $queries Every query, each tagged with `fingerprint` and `is_n_plus_1`
     * @param list<array<string, mixed>> $alerts  The queries that belong to a repeated shape
     * @param list<QueryGroup>           $groups  One entry per distinct shape, most repeated first
     */
    public function __construct(
        public array $queries,
        public array $alerts,
        public array $groups,
    ) {
    }
}
