<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

use DateTimeImmutable;
use JsonException;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Support\Json;
use Throwable;

/**
 * Turns the toolbar's raw {@see \Rhapsody\Core\Debug} data into a redacted
 * {@see RequestSnapshot}.
 *
 * Redaction happens here, while the snapshot is being assembled, so an
 * unredacted version of the request never exists as a snapshot object that
 * could be written, logged or sent by mistake. Only an allowlist of
 * environment variables is included, never all of $_ENV.
 *
 * @example
 * $builder  = new SnapshotBuilder(new Redactor(), new SnapshotSettings());
 * $snapshot = $builder->build(Debug::getInstance()->getData());
 */
final class SnapshotBuilder
{
    /**
     * Environment variables that are safe and useful for diagnosing a request.
     * Apps add more through `debug.snapshots.env_allowlist`.
     *
     * @var list<string>
     */
    public const DEFAULT_ENV_ALLOWLIST = [
        'APP_ENV', 'APP_URL', 'APP_BASE_URL', 'APP_THEME', 'CACHE_DRIVER',
        'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'AI_PROVIDER',
        'LAZY_LOADING_ENABLED', 'DDOS_ENABLED', 'PAYMENT_GATEWAY', 'MAIL_TRANSPORT', 'MAIL_HOST',
    ];

    private const MAX_TRACE_FRAMES = 15;
    private const MAX_MIDDLEWARE   = 30;
    private const MAX_SERVICES     = 25;
    private const MAX_PREVIOUS     = 3;

    public function __construct(
        private readonly Redactor $redactor,
        private readonly SnapshotSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $debugData The data collected by Debug::end()/collect()
     * @param Throwable|null       $exception The failure that ended the request, if any
     * @param DateTimeImmutable|null $now     Injectable clock, for tests
     *
     * @throws \Random\RandomException If no random source is available
     */
    public function build(array $debugData, ?Throwable $exception = null, ?DateTimeImmutable $now = null): RequestSnapshot
    {
        $now ??= new DateTimeImmutable();
        $logs  = self::arr($debugData['logs'] ?? null);

        $data = [
            'framework'   => [
                'app_version' => self::str($debugData['app_version'] ?? null, 'N/A'),
                'app_env'     => self::str($debugData['app_env'] ?? null),
                'php_version' => self::str($debugData['php_version'] ?? null),
                'php_sapi'    => PHP_SAPI,
                'os'          => PHP_OS_FAMILY,
            ],
            'request'     => $this->request(self::arr($debugData['request'] ?? null)),
            'route'       => $this->route($debugData['route'] ?? null),
            'response'    => ['status' => self::int($debugData['response_code'] ?? null)],
            'performance' => [
                'time_ms'   => self::float($debugData['execution_time'] ?? null),
                'memory_mb' => self::float($debugData['memory_usage'] ?? null),
                'memory'    => self::arr($debugData['memory'] ?? null),
            ],
            'queries'     => $this->queries($debugData),
            'cache'       => self::arr($debugData['cache_stats'] ?? null),
            'middleware'  => $this->middleware($debugData['middleware_trace'] ?? null),
            'services'    => $this->services($debugData['container_trace'] ?? null),
            'session'     => $this->redactor->maskValues(self::arr($debugData['session'] ?? null)),
            'environment' => $this->environment($debugData['env'] ?? null),
            'logs'        => [
                'php'    => $this->logTail($logs['php'] ?? null),
                'apache' => $this->logTail($logs['apache'] ?? null),
            ],
            'exception'   => $exception === null ? null : $this->exception($exception),
        ];

        return new RequestSnapshot(
            $now->format('Ymd-His-u') . '-' . bin2hex(random_bytes(2)),
            $now->format('Y-m-d\TH:i:s.vP'),
            $data
        );
    }

    /**
     * @param  array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    private function request(array $request): array
    {
        return [
            'method'     => self::str($request['method'] ?? null, 'GET'),
            'url'        => $this->redactor->scrubString(self::str($request['full_url'] ?? null)),
            'uri'        => $this->redactor->scrubString(self::str($request['uri'] ?? null)),
            'ip'         => self::str($request['ip'] ?? null),
            'headers'    => $this->redactor->redactArray(self::arr($request['headers'] ?? null)),
            'query'      => $this->redactor->redactArray(self::arr($request['query'] ?? null)),
            'body'       => $this->body($request['body'] ?? null),
            'middleware' => self::strings($request['middleware'] ?? null),
        ];
    }

    /**
     * @return array{content: string, bytes: int, truncated: bool}|null
     */
    private function body(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $bytes     = strlen($raw);
        $truncated = $bytes > $this->settings->maxBodyBytes;
        $slice     = $truncated ? substr($raw, 0, $this->settings->maxBodyBytes) : $raw;

        try {
            $decoded = Json::decode($slice);
            $content = is_array($decoded)
                ? Json::encode($this->redactor->redactArray($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : $this->redactor->scrubString($slice);
        } catch (JsonException) {
            // Not JSON (form data, plain text) or cut off mid-document.
            $content = $this->redactor->scrubString($slice);
        }

        return ['content' => $content, 'bytes' => $bytes, 'truncated' => $truncated];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function route(mixed $route): ?array
    {
        $route = self::arr($route);

        return $route === [] ? null : $this->redactor->redactArray($route);
    }

    /**
     * @param  array<string, mixed> $debugData
     * @return array<string, mixed>
     */
    private function queries(array $debugData): array
    {
        $all   = self::rows($debugData['queries'] ?? null);
        $items = [];

        foreach (array_slice($all, 0, $this->settings->maxQueries) as $query) {
            $sql     = self::str($query['sql'] ?? null);
            $items[] = [
                'sql'      => $this->redactor->scrubString($sql),
                'params'   => $this->redactor->redactQueryParams($sql, $query['params'] ?? null),
                'time_ms'  => round(QueryFingerprint::toFloat($query['executionMS'] ?? 0) * 1000, 2),
                'caller'   => self::caller($query['caller'] ?? null),
                'repeated' => ($query['is_n_plus_1'] ?? false) === true,
            ];
        }

        $repeated = [];
        foreach (self::rows($debugData['query_groups'] ?? null) as $group) {
            if (($group['repeated'] ?? false) !== true) {
                continue;
            }

            $repeated[] = [
                'count'    => self::int($group['count'] ?? null),
                'total_ms' => self::float($group['total_ms'] ?? null),
                'sql'      => $this->redactor->scrubString(self::str($group['example_sql'] ?? null)),
                'callers'  => self::strings($group['callers'] ?? null),
            ];
        }

        return [
            'total'      => count($all),
            'recorded'   => count($items),
            'items'      => $items,
            'n_plus_one' => $repeated,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function middleware(mixed $trace): array
    {
        $out = [];
        foreach (array_slice(self::rows($trace), 0, self::MAX_MIDDLEWARE) as $item) {
            $out[] = [
                'class'       => self::str($item['class'] ?? null),
                'type'        => self::str($item['type'] ?? null),
                'route'       => self::str($item['route'] ?? null),
                'duration_ms' => self::float($item['duration'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The slowest resolved services, most expensive first.
     *
     * @return list<array<string, mixed>>
     */
    private function services(mixed $trace): array
    {
        $rows = self::rows($trace);
        usort($rows, self::slowestFirst(...));

        $out = [];
        foreach (array_slice($rows, 0, self::MAX_SERVICES) as $item) {
            $out[] = [
                'class'       => self::str($item['class'] ?? null),
                'duration_ms' => self::float($item['duration'] ?? null),
                'called_by'   => self::str($item['called_by'] ?? null),
                'lazy'        => ($item['proxy'] ?? false) === true,
                'circular'    => ($item['circular'] ?? false) === true,
            ];
        }

        return $out;
    }

    /**
     * Only allowlisted variables, and never the value of one whose name looks secret.
     *
     * @return array<string, string>
     */
    private function environment(mixed $env): array
    {
        $source = self::arr($env);
        $names  = array_values(array_unique([...self::DEFAULT_ENV_ALLOWLIST, ...$this->settings->envAllowlist]));
        $out    = [];

        foreach ($names as $name) {
            $value = $source[$name] ?? getenv($name);
            if ($value === false || $value === null) {
                continue;
            }

            if ($this->redactor->isSensitiveKey($name)) {
                $out[$name] = Redactor::MASK;
            } else {
                $out[$name] = is_scalar($value) ? $this->redactor->scrubString((string) $value) : '[non-scalar]';
            }
        }

        return $out;
    }

    /**
     * The toolbar stores log text HTML-escaped for display; undo that so the
     * snapshot holds the real text, then keep only the last few lines.
     */
    private function logTail(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($text, 'Log file not found') || str_starts_with($text, 'Error reading log file')) {
            return null;
        }

        $lines = preg_split('/\R/', rtrim($text));
        if ($lines === false) {
            return null;
        }

        return $this->redactor->scrubString(implode("\n", array_slice($lines, -$this->settings->logLines)));
    }

    /**
     * @return array<string, mixed>
     */
    private function exception(Throwable $exception): array
    {
        $previous = [];
        $cause    = $exception->getPrevious();
        while ($cause !== null && count($previous) < self::MAX_PREVIOUS) {
            $previous[] = [
                'class'   => $cause::class,
                'message' => $this->redactor->scrubString($cause->getMessage()),
            ];
            $cause = $cause->getPrevious();
        }

        $frames = [];
        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $file     = self::str($frame['file'] ?? null);
            $frames[] = self::str($frame['class'] ?? null)
                . self::str($frame['type'] ?? null)
                . self::str($frame['function'] ?? null) . '()'
                . ($file === '' ? '' : ' at ' . self::relativePath($file) . ':' . self::int($frame['line'] ?? null));
        }

        return [
            'class'       => $exception::class,
            'message'     => $this->redactor->scrubString($exception->getMessage()),
            'code'        => $exception->getCode(),
            'http_status' => $exception instanceof HttpException ? $exception->getStatusCode() : null,
            'file'        => self::relativePath($exception->getFile()),
            'line'        => $exception->getLine(),
            'trace'       => $frames,
            'previous'    => $previous,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function slowestFirst(array $a, array $b): int
    {
        return self::float($b['duration'] ?? null) <=> self::float($a['duration'] ?? null);
    }

    private static function caller(mixed $caller): ?string
    {
        if (! is_array($caller) || ! isset($caller['file']) || ! is_string($caller['file'])) {
            return null;
        }

        return $caller['file'] . ':' . self::int($caller['line'] ?? null);
    }

    /**
     * Strip the application root so snapshots do not carry the developer's
     * machine layout (and stay short).
     */
    private static function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = defined('ROOT_DIR') ? constant('ROOT_DIR') : null;

        if (is_string($root) && $root !== '') {
            $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if (str_starts_with($path, $root)) {
                return substr($path, strlen($root));
            }
        }

        return $path;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Rows of string-keyed arrays from loosely typed collector output.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        $rows = [];
        foreach (self::arr($value) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];
            foreach ($item as $key => $field) {
                if (is_string($key)) {
                    $row[$key] = $field;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $out = [];
        foreach (self::arr($value) as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function str(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : $default;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private static function float(mixed $value): float
    {
        return QueryFingerprint::toFloat($value);
    }
}
