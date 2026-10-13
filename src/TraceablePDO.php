<?php
namespace Rhapsody\Core;

use PDO;

class TraceablePDO extends PDO
{
    /** @var list<array<string, mixed>> */
    private static array $log = [];

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

        self::$log[] = [
            'sql'         => $query,
            'params'      => [],
            'executionMS' => $time,
            'caller'      => $this->findQueryCaller(),
        ];

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

        self::$log[] = [
            'sql'         => '[PREPARED] ' . $query,
            'params'      => [],
            'executionMS' => 0,
            'caller'      => $this->findQueryCaller(),
        ];

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
