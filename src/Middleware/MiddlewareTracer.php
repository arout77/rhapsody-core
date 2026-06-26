<?php
namespace Rhapsody\Core\Middleware;

class MiddlewareTracer
{
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
        if (empty(self::$trace)) {
            return;
        }
        $last                           = array_key_last(self::$trace);
        self::$trace[$last]['end']      = microtime(true);
        self::$trace[$last]['duration'] = round(
            (self::$trace[$last]['end'] - self::$trace[$last]['start']) * 1000,
            2
        );
    }

    public static function getTrace(): array
    {
        return self::$trace;
    }

    public static function reset(): void
    {
        self::$trace = [];
    }
}
