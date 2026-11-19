<?php
namespace Rhapsody\Core;

use PDO;

class TraceablePDO extends PDO
{
    /** @var list<array<string, mixed>> */
    private static array $log = [];

    /** Most statements kept for the debug toolbar. A long-running process (queue worker, CLI sim) would otherwise grow without bound. */
    private const MAX_LOGGED = 500;

    /** Record one statement for the toolbar: not at all outside a web request, and only the newest MAX_LOGGED entries. */
    private function record(array $entry): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }
        self::$log[] = $entry;
        if (count(self::$log) > self::MAX_LOGGED * 2) {
            self::$log = array_slice(self::$log, -self::MAX_LOGGED);
        }
    }

    /**
     * @param string $dsn
     * @param string|null $username
     * @param string|null $password
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
    }

    /**
     * @param string $query
     * @param int|null $fetchMode
     * @param mixed ...$fetch_mode_args
     */
    public function query(string $query, ?int $fetchMode = null, ...$fetch_mode_args): \PDOStatement  | false
    {
        $start  = microtime(true);
        $result = parent::query($query, $fetchMode, ...$fetch_mode_args);
        $time   = microtime(true) - $start;

        $this->record([
            'sql'         => $query,
            'params'      => [],
            'executionMS' => $time,
            'caller'      => PHP_SAPI === 'cli' ? null : $this->findQueryCaller(),
        ]);

        return $result;
    }

    /**
     * @param string $query
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement  | false
    {
        // For prepared statements, the execution time is not measured here,
        // but we still want to log the origin of the statement.
        $statement = parent::prepare($query, $options);

        $this->record([
            'sql'         => '[PREPARED] ' . $query,
            'params'      => [],
            'executionMS' => 0,
            'caller'      => PHP_SAPI === 'cli' ? null : $this->findQueryCaller(),
        ]);

        return $statement;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getQueryLog(): array
    {
        return self::$log;
    }

    /**
     * @return array{file: string, line: int}|null
     */
    private function findQueryCaller(): ?array
    {
        $trace       = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $projectRoot = dirname(__DIR__, 2);

        foreach ($trace as $entry) {
            if (! isset($entry['file'])) {
                continue;
            }

            $file = str_replace('\\', '/', $entry['file']);

            // If the file is outside our project root, skip it
            if (strpos($file, str_replace('\\', '/', $projectRoot)) === false) {
                continue;
            }

            // If the file is one of our core DB classes, skip it and check the next one
            if (str_ends_with($file, 'TraceablePDO.php') || str_ends_with($file, 'Database.php')) {
                continue;
            }

            // The first file that is not a core DB class is our caller
            return [
                'file' => str_replace(str_replace('\\', '/', $projectRoot) . '/', '', $file),
                'line' => $entry['line'] ?? 0,
            ];
        }
        return null;
    }
}
