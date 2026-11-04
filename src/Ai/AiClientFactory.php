<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Rhapsody\Core\Ai\Exceptions\AiException;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Services\GeminiClient;

/**
 * Builds the default AiClientInterface from the app's `ai` config block.
 *
 * Core binds this lazily (a singleton closure), so an app that never uses AI
 * pays nothing for it. A missing API key is not an error here: the client is
 * still built, and it throws AiAuthenticationException on first use, which
 * callers already handle. To use another provider, bind your own
 * AiClientInterface in bootstrap.php; core allows that replacement.
 */
final class AiClientFactory
{
    /**
     * @param array<mixed> $config The full application config (reads $config['ai']).
     *
     * @throws AiException If `ai.provider` names a provider core does not ship.
     */
    public static function fromConfig(array $config, ?ClientInterface $http = null): AiClientInterface
    {
        $ai       = is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $provider = is_string($ai['provider'] ?? null) && $ai['provider'] !== '' ? strtolower($ai['provider']) : 'gemini';

        return match ($provider) {
            'gemini' => new GeminiClient($http ?? new Client(), self::geminiConfig($ai)),
            default  => throw new AiException(
                "Unsupported AI provider \"{$provider}\". Core ships \"gemini\"; "
                . 'bind your own AiClientInterface in bootstrap.php to use another.'
            ),
        };
    }

    /**
     * @param  array<mixed> $ai
     * @return array{api_key?: string, model?: string, temperature?: float|int, max_tokens?: int, timeout?: float|int, connect_timeout?: float|int}
     */
    private static function geminiConfig(array $ai): array
    {
        $raw = is_array($ai['gemini'] ?? null) ? $ai['gemini'] : [];
        $out = [];

        if (is_string($raw['api_key'] ?? null)) {
            $out['api_key'] = $raw['api_key'];
        }
        if (is_string($raw['model'] ?? null) && $raw['model'] !== '') {
            $out['model'] = $raw['model'];
        }
        if (is_int($raw['temperature'] ?? null) || is_float($raw['temperature'] ?? null)) {
            $out['temperature'] = $raw['temperature'];
        }
        if (is_int($raw['max_tokens'] ?? null)) {
            $out['max_tokens'] = $raw['max_tokens'];
        }
        if (is_int($raw['timeout'] ?? null) || is_float($raw['timeout'] ?? null)) {
            $out['timeout'] = $raw['timeout'];
        }
        if (is_int($raw['connect_timeout'] ?? null) || is_float($raw['connect_timeout'] ?? null)) {
            $out['connect_timeout'] = $raw['connect_timeout'];
        }

        return $out;
    }
}
