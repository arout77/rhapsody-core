<?php

declare(strict_types=1);

namespace Rhapsody\Core\Support;

/**
 * Typed access to environment values loaded by vlucas/phpdotenv.
 *
 * phpdotenv populates $_ENV (and $_SERVER) with strings, but PHP types those
 * superglobals as mixed. These accessors narrow once, so callers get real
 * string/bool/int values and a default when the variable is unset.
 */
final class Env
{
    /**
     * Raw value as a string, or null when the variable is unset or not a string.
     */
    public static function get(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::get($key) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * "true", "1", "yes" and "on" are true; "false", "0", "no" and "off" are false.
     * Anything else (including unset) returns $default.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}