<?php
namespace Rhapsody\Core;

use Doctrine\DBAL\Logging\DebugStack;
use Rhapsody\Core\Middleware\MiddlewareTracer;
use Rhapsody\Core\QueryLogger;
use Rhapsody\Core\Routing\Route;

/**
 * A simple data collector for the developer toolbar.
 * Uses a singleton pattern to be accessible anywhere during the request.
 */
class Debug
{
    private static ?self $instance = null;
    private array $data            = [];
    private float $startTime;
    private int $startMemory;

    private function __construct()
    {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Converts php.ini memory shorthand (e.g., '128M', '2G') into absolute bytes.
     */
    private function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $val  = trim($limit);
        $last = strtolower($val[strlen($val) - 1]);
        $val  = (int) $val;

        switch ($last) {
            case 'g':$val *= 1024;
            case 'm':$val *= 1024;
            case 'k':$val *= 1024;
        }

        return $val;
    }

    /**
     * Starts the timer and records initial memory usage.
     * Should be called at the very beginning of the request.
     */
    public function start(): void
    {
        $this->startTime           = microtime(true);
        $this->startMemory         = memory_get_usage();
        $this->data['php_version'] = phpversion();

        // Reset cache hit/miss counters for this request
        Cache::resetStats();

        // Reset middleware tracer for this request
        MiddlewareTracer::reset();
    }

    /**
     * Gathers final data points at the end of the request.
     */
    public function end(Response $response, array $config, Container $container, ?Route $route = null): void
    {
        $this->data['execution_time'] = round((microtime(true) - $this->startTime) * 1000, 2);
        $this->data['memory_usage']   = round((memory_get_peak_usage() - $this->startMemory) / 1024 / 1024, 2);
        $this->data['response_code']  = $response->getStatusCode();
        $this->data['app_version']    = $config['app_version'] ?? 'N/A';
        $this->data['session']        = $_SESSION ?? [];
        // --- Enhanced HTTP Request data ---
        $this->data['request'] = [
            'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri'        => $_SERVER['REQUEST_URI'] ?? '/',
            'full_url'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'timestamp'  => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
            'headers'    => getallheaders() ?: [],
            'query'      => $_GET ?? [],
            'body'       => file_get_contents('php://input') ?: null,
            'middleware' => $route ? $route->getMiddleware() : [],
        ];

        // Environment variables (collect for Environment tab)
        $this->data['env'] = $_ENV ?? [];

        // Get QueryLogger instance from container
        $queryLogger = null;
        if ($container->has(QueryLogger::class)) {
            $queryLogger = $container->resolve(QueryLogger::class);
        }

        $doctrineQueries       = $queryLogger ? $queryLogger->queries : [];
        $pdoQueries            = TraceablePDO::getQueryLog();
        $this->data['queries'] = array_merge($doctrineQueries, $pdoQueries);

        // Also get Doctrine DebugStack if available (for backward compatibility)
        if ($container->has(DebugStack::class)) {
            $stack = $container->resolve(DebugStack::class);
            if (! empty($stack->queries)) {
                $this->data['queries'] = array_merge($this->data['queries'], $stack->queries);
            }
        }

        // Cache stats
        $this->data['cache_stats'] = Cache::getStats();

        // N+1 queries detection (from QueryLogger fingerprints)
        $nPlusOneAlerts = [];
        if ($queryLogger) {
            $nPlusOneAlerts = array_filter($queryLogger->queries, function ($q) {
                return $q['is_n_plus_1'] ?? false;
            });
        }
        $this->data['n_plus_one_alerts'] = array_values($nPlusOneAlerts);

        // Middleware trace (collected by MiddlewareTracer)
        $this->data['middleware_trace'] = MiddlewareTracer::getTrace();

        // Container trace (collected by Container itself)
        $this->data['container_trace'] = Container::getTrace();

        // Logs
        $phpLogger          = new Logger($config['logging']['php_error_log_path'] ?? '');
        $apacheLogger       = new Logger($config['logging']['apache_error_log_path'] ?? '');
        $this->data['logs'] = [
            'php'    => $phpLogger->read(50),
            'apache' => $apacheLogger->read(50),
        ];

        // Memory details
        $peakUsage  = memory_get_peak_usage();
        $limitBytes = $this->getMemoryLimitBytes();
        $usedMb     = round($peakUsage / 1024 / 1024, 2);
        $limitMb    = $limitBytes === -1 ? 'Unlimited' : round($limitBytes / 1024 / 1024, 2);
        $percentage = 0;
        if ($limitBytes > 0) {
            $percentage = round(($peakUsage / $limitBytes) * 100, 2);
        }
        $status = 'ok';
        if ($percentage > 80) {
            $status = 'warning';
        }

        if ($percentage > 95) {
            $status = 'critical';
        }

        $this->data['memory'] = [
            'used_mb'  => $usedMb,
            'limit_mb' => $limitMb,
            'percent'  => $percentage,
            'status'   => $status,
        ];

        // Route data
        if ($route) {
            $callback = $route->getCallback();
            if (is_array($callback) && count($callback) === 2) {
                $controller          = explode('\\', $callback[0]);
                $this->data['route'] = [
                    'method'     => $route->getMethod(),
                    'path'       => $route->getPath(),
                    'controller' => end($controller),
                    'action'     => $callback[1],
                ];
                // Optionally, capture route parameters
                $params = $route->getParams();
                if (! empty($params)) {
                    $this->data['route']['params'] = $params;
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Reads the last 50 lines of a log file.
     */
    private function readLogFile(string $path): string
    {
        if (empty($path) || ! file_exists($path) || ! is_readable($path)) {
            return "Log file not found or not readable at: " . htmlspecialchars($path);
        }
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $last_line = $file->key();
        $lines     = new \LimitIterator($file, ($last_line > 50 ? $last_line - 50 : 0), $last_line);
        return htmlspecialchars(implode("", iterator_to_array($lines)), ENT_QUOTES, 'UTF-8');
    }
}
