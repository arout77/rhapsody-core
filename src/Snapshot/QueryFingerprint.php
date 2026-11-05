<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

/**
 * The one place that decides whether two SQL statements are "the same query".
 *
 * Literals are collapsed to `?`, IN lists to `IN (?)` and whitespace to a
 * single space, so `WHERE id = 7` and `WHERE id = 912` share a fingerprint. A
 * shape that runs more than {@see self::REPEAT_THRESHOLD} times in one request
 * is flagged as a likely N+1.
 *
 * Previously QueryLogger, Debug and Toolbar each carried their own slightly
 * different copy of this logic.
 *
 * @phpstan-import-type QueryGroup from FingerprintReport
 */
final class QueryFingerprint
{
    public const REPEAT_THRESHOLD = 3;

    private const CALLERS_PER_GROUP = 3;

    /**
     * Collapse literals and whitespace so structurally identical queries compare equal.
     */
    public static function normalize(string $sql): string
    {
        // Quoted strings first, so digits inside them are not treated as numbers.
        $sql = self::replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $sql = self::replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
        $sql = self::replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql);

        return trim(self::replace('/\s+/', ' ', $sql));
    }

    /**
     * Group queries by shape, tag each one, and flag repeated shapes.
     *
     * @param list<array<string, mixed>> $queries
     */
    public static function analyze(array $queries): FingerprintReport
    {
        /** @var array<string, list<int>> $indexesByShape */
        $indexesByShape = [];
        foreach ($queries as $index => $query) {
            $sql                                         = $query['sql'] ?? '';
            $indexesByShape[self::normalize(is_string($sql) ? $sql : '')][] = $index;
        }

        $alerts = [];
        $groups = [];

        foreach ($indexesByShape as $shape => $indexes) {
            $count    = count($indexes);
            $repeated = $count > self::REPEAT_THRESHOLD;
            $totalMs  = 0.0;
            $callers  = [];

            foreach ($indexes as $index) {
                $queries[$index]['fingerprint'] = $shape;
                $queries[$index]['is_n_plus_1'] = $repeated;

                if ($repeated) {
                    $alerts[] = $queries[$index];
                }

                $totalMs += self::toFloat($queries[$index]['executionMS'] ?? 0) * 1000;
                $caller   = self::caller($queries[$index]);
                if ($caller !== null && ! in_array($caller, $callers, true) && count($callers) < self::CALLERS_PER_GROUP) {
                    $callers[] = $caller;
                }
            }

            $example  = $queries[$indexes[0]]['sql'] ?? '';
            $groups[] = [
                'fingerprint' => $shape,
                'count'       => $count,
                'repeated'    => $repeated,
                'total_ms'    => round($totalMs, 2),
                'example_sql' => is_string($example) ? $example : '',
                'callers'     => $callers,
            ];
        }

        usort($groups, self::compareGroups(...));

        return new FingerprintReport($queries, $alerts, $groups);
    }

    /**
     * Most repeated first; ties broken by total time.
     *
     * @param QueryGroup $a
     * @param QueryGroup $b
     */
    private static function compareGroups(array $a, array $b): int
    {
        return [$b['count'], $b['total_ms']] <=> [$a['count'], $a['total_ms']];
    }

    /**
     * Doctrine's loggers report seconds as float; some entries carry the
     * string 'unfinished' instead.
     */
    public static function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function caller(array $query): ?string
    {
        $caller = $query['caller'] ?? null;
        if (! is_array($caller) || ! isset($caller['file']) || ! is_string($caller['file'])) {
            return null;
        }

        $line = $caller['line'] ?? '-';

        return $caller['file'] . ':' . (is_int($line) || is_string($line) ? $line : '-');
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
