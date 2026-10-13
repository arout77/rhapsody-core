<?php
namespace Rhapsody\Core;

use Doctrine\DBAL\Logging\DebugStack;
use Rhapsody\Core\Contracts\ContainerInterface;
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
    /** @var array<string, mixed> */
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
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $bytes = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g'     => $bytes * 1024 ** 3,
            'm'     => $bytes * 1024 ** 2,
            'k'     => $bytes * 1024,
            default => $bytes,
        };
    }

    /**
     * Starts the timer and records initial memory usage.
     * Should be called at the very beginning of the request.
     */
    public function start(): void
    {
        $this->data                = [];
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
     *
     * @param Response             $response
     * @param array<string, mixed> $config
     * @param ContainerInterface   $container
     * @param Route|null           $matchedRoute
     */
    public function end(Response $response, array $config, ContainerInterface $container, ?Route $matchedRoute = null): void
    {
        $this->data['execution_time'] = round((microtime(true) - $this->startTime) * 1000, 2);
        $this->data['memory_usage']   = round((memory_get_peak_usage() - $this->startMemory) / 1024 / 1024, 2);
        $this->data['response_code']  = $response->getStatusCode();
        $this->data['app_version']    = $config['app_version'] ?? 'N/A';
        $this->data['session']        = $_SESSION ?? [];

        // --- Enhanced HTTP Request data ---
        $https       = self::serverString('HTTPS');
        $requestTime = $_SERVER['REQUEST_TIME'] ?? null;
        $requestUri  = self::serverString('REQUEST_URI', '/');

        $this->data['request'] = [
            'method'     => self::serverString('REQUEST_METHOD', 'GET'),
            'uri'        => $requestUri,
            'full_url'   => ($https !== '' && $https !== 'off' ? 'https' : 'http') . '://' . self::serverString('HTTP_HOST', 'localhost') . $requestUri,
            'ip'         => self::serverString('REMOTE_ADDR', '127.0.0.1'),
            'timestamp'  => date('Y-m-d H:i:s', is_int($requestTime) ? $requestTime : time()),
            'headers'    => getallheaders() ?: [],
            'query'      => $_GET,
            'body'       => file_get_contents('php://input') ?: null,
            'middleware' => $matchedRoute ? $matchedRoute->getMiddleware() : [],
        ];

        // Environment variables (collect for Environment tab)
        $this->data['env'] = $_ENV;

        $stackQueries = [];

        // Also get Doctrine DebugStack if available (for backward compatibility)
        if ($container->has(DebugStack::class)) {
            $stack = $container->resolve(DebugStack::class);
            if ($stack instanceof DebugStack) {
                $stackQueries = $stack->queries;
            }
        }

        /** @var list<array<string, mixed>> $queries */
        $queries = array_merge(
            QueryLogger::getInstance()->queries,
            TraceablePDO::getQueryLog(),
            $stackQueries
        );

        // Detect N+1 queries: group by normalized SQL, flag any shape seen more than 3 times
        $groups = [];
        foreach ($queries as $index => $query) {
            $sql                                  = $query['sql'] ?? '';
            $groups[self::normalizeSql(is_string($sql) ? $sql : '')][] = $index;
        }

        $nPlusOneAlerts = [];
        foreach ($groups as $indexes) {
            if (count($indexes) > 3) {
                foreach ($indexes as $index) {
                    $queries[$index]['is_n_plus_1'] = true;
                    $nPlusOneAlerts[]               = $queries[$index];
                }
            }
        }

        $this->data['queries']           = $queries;
        $this->data['total_queries']     = count($queries);
        $this->data['n_plus_one_alerts'] = $nPlusOneAlerts;
        $this->data['n_plus_1_count']    = count($nPlusOneAlerts);

        // Cache stats
        $this->data['cache_stats'] = Cache::getStats();

        // Middleware trace (collected by MiddlewareTracer)
        $this->data['middleware_trace'] = MiddlewareTracer::getTrace();

        // Container trace (collected by Container itself)
        $this->data['container_trace'] = Container::getTrace();

        // Logs
        $phpLogger          = new Logger(self::logPath($config, 'php_error_log_path'));
        $apacheLogger       = new Logger(self::logPath($config, 'apache_error_log_path'));
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
        if ($matchedRoute) {
            $callback = $matchedRoute->getCallback();
            if (is_array($callback) && count($callback) === 2) {
                // $callback[0] is a class-name string for a typical
                // "Controller@method"-style route, but an already-built
                // instance for any route registered as [$instance, 'method']
                // (which is what a module controller needing injected
                // facades from ModuleContext has to use). Handle both.
                $handler             = $callback[0] ?? null;
                $controllerName      = is_object($handler) ? $handler::class : (is_string($handler) ? $handler : '');
                $controller          = explode('\\', $controllerName);
                $this->data['route'] = [
                    'method'     => $matchedRoute->getMethod(),
                    'path'       => $matchedRoute->getPath(),
                    'controller' => end($controller),
                    'action'     => $callback[1] ?? null,
                ];
                // Optionally, capture route parameters
                $params = $matchedRoute->getParams();
                if (! empty($params)) {
                    $this->data['route']['params'] = $params;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Read a $_SERVER value as a string, falling back to $default when the
     * key is missing or holds a non-string value.
     */
    private static function serverString(string $key, string $default = ''): string
    {
        $value = $_SERVER[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Read a path from the config's "logging" section, or '' when it isn't set.
     *
     * @param array<string, mixed> $config
     */
    private static function logPath(array $config, string $key): string
    {
        $logging = $config['logging'] ?? null;
        $path    = is_array($logging) ? ($logging[$key] ?? null) : null;

        return is_string($path) ? $path : '';
    }

    /**
     * Collapse literals and whitespace so structurally identical queries compare equal.
     */
    private static function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace("/'[^']*'/", '?', $sql) ?? $sql;

        return preg_replace('/\s+/', ' ', $sql) ?? $sql;
    }
}