<?php
namespace Rhapsody\Core\Support;

use RuntimeException;

/**
 * Typed, dot-notation reader over the application config array.
 *
 * Wraps the plain array bound as 'config' so callers get real types instead of
 * `mixed`. A missing key (with no default) or a value of the wrong type throws —
 * misconfiguration should fail loudly at the point of use, not three calls later.
 *
 * Scalar getters are tolerant of values that arrive as strings (typical for
 * .env-driven config): '587' reads as an int, 'true'/'1'/'on' reads as a bool.
 *
 * @example
 * $config = $container->get(Config::class);
 * $host   = $config->string('mail.host');
 * $port   = $config->int('mail.port', 587);
 * $debug  = $config->bool('app.debug', false);
 */
final class Config
{
    /**
     * @param array<string, mixed> $items The raw config array
     */
    public function __construct(private readonly array $items)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Whether a key exists (a key set to null still counts as existing).
     */
    public function has(string $key): bool
    {
        [$found] = $this->find($key);

        return $found;
    }

    /**
     * Get a raw value, or $default when the key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : $default;
    }

    /**
     * @throws RuntimeException If the key is missing (and no default given) or not string-like
     */
    public function string(string $key, ?string $default = null): string
    {
        [$found, $value] = $this->find($key);

        if (! $found || $value === null) {
            return $default ?? throw $this->missing($key);
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw $this->wrongType($key, 'string', $value);
    }

    /**
     * @throws RuntimeException If the key is missing (and no default given) or not an integer
     */
    public function int(string $key, ?int $default = null): int
    {
        [$found, $value] = $this->find($key);

        if (! $found || $value === null) {
            return $default ?? throw $this->missing($key);
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw $this->wrongType($key, 'int', $value);
    }

    /**
     * @throws RuntimeException If the key is missing (and no default given) or not boolean-like
     */
    public function bool(string $key, ?bool $default = null): bool
    {
        [$found, $value] = $this->find($key);

        if (! $found || $value === null) {
            return $default ?? throw $this->missing($key);
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

        throw $this->wrongType($key, 'bool', $value);
    }

    /**
     * @param  array<mixed>|null $default
     * @throws RuntimeException  If the key is missing (and no default given) or not an array
     * @return array<mixed>
     */
    public function array(string $key, ?array $default = null): array
    {
        [$found, $value] = $this->find($key);

        if (! $found || $value === null) {
            return $default ?? throw $this->missing($key);
        }
        if (is_array($value)) {
            return $value;
        }

        throw $this->wrongType($key, 'array', $value);
    }

    /**
     * Look a key up, trying an exact match first and then dot-notation.
     *
     * @return array{bool, mixed} [found, value]
     */
    private function find(string $key): array
    {
        if (array_key_exists($key, $this->items)) {
            return [true, $this->items[$key]];
        }

        $segment = $this->items;
        foreach (explode('.', $key) as $part) {
            if (! is_array($segment) || ! array_key_exists($part, $segment)) {
                return [false, null];
            }
            $segment = $segment[$part];
        }

        return [true, $segment];
    }

    private function missing(string $key): RuntimeException
    {
        return new RuntimeException("Missing config key '{$key}'.");
    }

    private function wrongType(string $key, string $expected, mixed $value): RuntimeException
    {
        return new RuntimeException(
            "Config key '{$key}' must be {$expected}, " . get_debug_type($value) . ' given.'
        );
    }
}
