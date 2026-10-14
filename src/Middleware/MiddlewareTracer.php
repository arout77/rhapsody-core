<?php
namespace Rhapsody\Core\Middleware;

/**
 * @phpstan-type TraceEntry array{class: string, type: string, route: string|null, start: float, end: float|null, duration: float|null}
 */
class MiddlewareTracer
{
    /** @var list<TraceEntry> */
    private static array $trace = [];

    /**
     * Start tracing a middleware execution.
     *
     * @param string $class   The middleware class name
     * @param string $type    'global' or 'route'
     * @param string $route   The route path (for route middleware)
     */
    public static function start(string $class, string $type = 'global', ?string $route = null): void
    {
        self::$trace[] = [
            'class'    => $class,
            'type'     => $type,
            'route'    => $route,
            'start'    => microtime(true),
            'end'      => null,
            'duration' => null,
        ];
    }

    /**
     * Stop the last traced middleware.
     */
    public static function stop(): void
    {
        $last = array_key_last(self::$trace);
        if ($last === null) {
            return;
        }

        $end                            = microtime(true);
        self::$trace[$last]['end']      = $end;
        self::$trace[$last]['duration'] = round(($end - self::$trace[$last]['start']) * 1000, 2);
    }

    /**
     * @return list<TraceEntry>
     */
    public static function getTrace(): array
    {
        return self::$trace;
    }

    public static function reset(): void
    {
        self::$trace = [];
    }
}
