<?php
namespace Rhapsody\Core\Support;

use JsonException;

/**
 * JSON helpers that always throw on failure and always return real PHP types.
 *
 * @example
 * $composer = Json::decodeArray(Fs::read($path));
 */
final class Json
{
    /**
     * Decode any JSON document into PHP values (objects become associative arrays).
     *
     * @throws JsonException If the input is not valid JSON
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a JSON object or array, guaranteeing an array result.
     *
     * @throws JsonException If the input is invalid JSON or decodes to a scalar/null
     * @return array<mixed>
     */
    public static function decodeArray(string $json): array
    {
        $data = self::decode($json);

        if (! is_array($data)) {
            throw new JsonException('Expected a JSON object or array, got ' . get_debug_type($data));
        }

        return $data;
    }

    /**
     * Encode a value as JSON.
     *
     * @param  int           $flags Any combination of the JSON_* flags
     * @throws JsonException        If the value cannot be encoded
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, $flags | JSON_THROW_ON_ERROR);
    }
}
