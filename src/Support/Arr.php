<?php
declare(strict_types=1);

namespace Rhapsody\Core\Support;

use RuntimeException;

/**
 * Typed, dot-notation reader for arrays you did not build yourself
 * (decoded JSON, webhook payloads, API responses).
 *
 * Every getter returns a real type instead of `mixed`. A missing key (with no
 * default) or a value of the wrong type throws, so malformed input fails loudly
 * at the point of use instead of three calls later.
 *
 * @example
 * $event = Json::decodeArray($payload);
 * $id    = Arr::string($event, 'data.object.id');
 * $cents = Arr::int($event, 'data.object.amount');
 * $meta  = Arr::assoc($event, 'data.object.metadata', []);
 */
final class Arr
{
    /**
     * @param array<mixed> $data
     */
    public static function has(array $data, string $key): bool
    {
        [$found] = self::find($data, $key);

        return $found;
    }

    /**
     * Raw value, or $default when the key does not exist.
     *
     * @param array<mixed> $data
     */
    public static function get(array $data, string $key, mixed $default = null): mixed
    {
        [$found, $value] = self::find($data, $key);

        return $found ? $value : $default;
    }

    /**
     * @param  array<mixed>     $data
     * @throws RuntimeException If the key is missing (and no default given) or not string-like
     */
    public static function string(array $data, string $key, ?string $default = null): string
    {
        [$found, $value] = self::find($data, $key);

        if (! $found || $value === null) {
            return $default ?? throw self::missing($key);
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw self::wrongType($key, 'string', $value);
    }

    /**
     * @param  array<mixed>     $data
     * @throws RuntimeException If the key is missing (and no default given) or not an integer
     */
    public static function int(array $data, string $key, ?int $default = null): int
    {
        [$found, $value] = self::find($data, $key);

        if (! $found || $value === null) {
            return $default ?? throw self::missing($key);
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::wrongType($key, 'int', $value);
    }

    /**
     * @param  array<mixed>     $data
     * @throws RuntimeException If the key is missing (and no default given) or not numeric
     */
    public static function float(array $data, string $key, ?float $default = null): float
    {
        [$found, $value] = self::find($data, $key);

        if (! $found || $value === null) {
            return $default ?? throw self::missing($key);
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw self::wrongType($key, 'float', $value);
    }

    /**
     * @param  array<mixed>     $data
     * @throws RuntimeException If the key is missing (and no default given) or not boolean-like
     */
    public static function bool(array $data, string $key, ?bool $default = null): bool
    {
        [$found, $value] = self::find($data, $key);

        if (! $found || $value === null) {
            return $default ?? throw self::missing($key);
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw self::wrongType($key, 'bool', $value);
    }

    /**
     * @param  array<mixed>      $data
     * @param  array<mixed>|null $default
     * @throws RuntimeException  If the key is missing (and no default given) or not an array
     * @return array<mixed>
     */
    public static function array(array $data, string $key, ?array $default = null): array
    {
        [$found, $value] = self::find($data, $key);

        if (! $found || $value === null) {
            return $default ?? throw self::missing($key);
        }
        if (is_array($value)) {
            return $value;
        }

        throw self::wrongType($key, 'array', $value);
    }

    /**
     * Like array(), but typed as a string-keyed map: use it for decoded JSON
     * objects (metadata, context). PHP stores numeric-string keys such as "123"
     * as ints; they are kept as they are.
     *
     * @param  array<mixed>               $data
     * @param  array<string, mixed>|null  $default
     * @throws RuntimeException           If the key is missing (and no default given) or not an array
     * @return array<string, mixed>
     */
    public static function assoc(array $data, string $key, ?array $default = null): array
    {
        [$found, $value] = self::find($data, $key);

        if (! $found || $value === null) {
            return $default ?? throw self::missing($key);
        }
        if (! is_array($value)) {
            throw self::wrongType($key, 'array', $value);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Look a key up, trying an exact match first and then dot-notation.
     *
     * @param  array<mixed>         $data
     * @return array{bool, mixed}   [found, value]
     */
    private static function find(array $data, string $key): array
    {
        if (array_key_exists($key, $data)) {
            return [true, $data[$key]];
        }

        $segment = $data;
        foreach (explode('.', $key) as $part) {
            if (! is_array($segment) || ! array_key_exists($part, $segment)) {
                return [false, null];
            }
            $segment = $segment[$part];
        }

        return [true, $segment];
    }

    private static function missing(string $key): RuntimeException
    {
        return new RuntimeException("Missing key '{$key}'.");
    }

    private static function wrongType(string $key, string $expected, mixed $value): RuntimeException
    {
        return new RuntimeException(
            "Key '{$key}' must be {$expected}, " . get_debug_type($value) . ' given.'
        );
    }
}
