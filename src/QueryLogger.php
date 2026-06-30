<?php
namespace Rhapsody\Core;

use Doctrine\DBAL\Logging\SQLLogger;

/**
 * A simple SQL logger that counts and times queries.
 * This is used by the Debug Toolbar.
 */
class QueryLogger implements SQLLogger
{
    private static ?self $instance = null; // <-- ADD THIS

    public array $queries      = [];
    public array $fingerprints = []; // Track counts per SQL structure
    private float $start_time  = 0;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param string     $sql
     * @param array|null $params
     * @param array|null $types
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null)
    {
        // Simple normalization: replace numeric/string literals with '?'
        $fingerprint = preg_replace("/\b(\d+|'[^']+')\b/", '?', $sql);

        $this->fingerprints[$fingerprint] = ($this->fingerprints[$fingerprint] ?? 0) + 1;
        $this->start_time                 = microtime(true);
        $this->queries[]                  = [
            'sql'         => $sql,
            'fingerprint' => $fingerprint,
            'is_n_plus_1' => $this->fingerprints[$fingerprint] > 3,
            'params'      => $params,
            'types'       => $types,
            'executionMS' => 0,
            'caller'      => $this->findQueryCaller(),
        ];
    }

    public function stopQuery()
    {
        $last_query_key = array_key_last($this->queries);
        if ($last_query_key !== null) {
            $this->queries[$last_query_key]['executionMS'] = microtime(true) - $this->start_time;
        }
    }

    /**
     * Finds the file and line that initiated the query.
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

            if (strpos($file, str_replace('\\', '/', $projectRoot)) === false) {
                continue;
            }

            $filename = basename($file);
            if ($filename === 'QueryLogger.php' || $filename === 'TraceablePDO.php' || $filename === 'Database.php') {
                continue;
            }

            if (strpos($file, '/vendor/doctrine/') !== false) {
                continue;
            }

            return [
                'file' => str_replace(str_replace('\\', '/', $projectRoot) . '/', '', $file),
                'line' => $entry['line'],
            ];
        }
        return null;
    }

    public function __destruct()
    {
        $last_query_key = array_key_last($this->queries);
        if ($last_query_key !== null) {
            if (isset($this->queries[$last_query_key]['executionMS']) && $this->queries[$last_query_key]['executionMS'] === 0) {
                $this->queries[$last_query_key]['executionMS'] = 'unfinished';
            }
        }
    }
}
