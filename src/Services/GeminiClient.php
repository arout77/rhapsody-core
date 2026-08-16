<?php
namespace Rhapsody\Core\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Rhapsody\Core\Ai\AiResponse;
use Rhapsody\Core\Ai\Exceptions\AiAuthenticationException;
use Rhapsody\Core\Ai\Exceptions\AiException;
use Rhapsody\Core\Ai\Exceptions\AiRateLimitException;
use Rhapsody\Core\Ai\Exceptions\AiServerException;
use Rhapsody\Core\Ai\Exceptions\AiTimeoutException;
use Rhapsody\Core\Contracts\AiClientInterface;

/**
 * Google AI Studio (generativelanguage.googleapis.com) implementation of
 * AiClientInterface. Auth is a single API key (GEMINI_API_KEY), not the
 * GCP-project/service-account flow Vertex AI uses — that's a deliberately
 * different, heavier integration this class does not attempt.
 */
class GeminiClient implements AiClientInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly array $config
    ) {
    }

    public function generateContent(string $prompt, array $options = []): AiResponse
    {
        return $this->generateChatContent(
            [['role' => 'user', 'content' => $prompt]],
            $options
        );
    }

    public function generateChatContent(array $messages, array $options = []): AiResponse
    {
        $apiKey = $this->config['api_key'] ?? '';
        if ($apiKey === '') {
            throw new AiAuthenticationException(
                'Gemini API key is not configured. Set GEMINI_API_KEY in your .env file.'
            );
        }

        $model = $options['model'] ?? $this->config['model'] ?? 'gemini-2.5-flash';
        $url   = self::API_BASE . rawurlencode($model) . ':generateContent';

        $generationConfig = array_filter([
            'temperature'     => $options['temperature'] ?? $this->config['temperature'] ?? null,
            'maxOutputTokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? null,
            'topP'            => $options['top_p'] ?? null,
            'topK'            => $options['top_k'] ?? null,
        ], fn ($value) => $value !== null);

        $body = [
            'contents' => array_map(
                fn (array $m) => [
                    // Gemini calls the assistant turn "model", not "assistant".
                    'role'  => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content'] ?? '']],
                ],
                $messages
            ),
        ];

        // PHP's json_encode([]) produces a JSON array ('[]'), not an object
        // ('{}') — there's no way to distinguish an empty associative array
        // from an empty list. Gemini's API expects generationConfig to be an
        // object when present, so when there's nothing to configure, omit
        // the key entirely rather than sending a malformed empty array.
        if (! empty($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        // Generation can genuinely take tens of seconds for long output —
        // this default is deliberately more generous than a typical web
        // request's timeout, not a bug. Override per-call via $options['timeout']
        // or globally via config['ai']['gemini']['timeout'] if your use case
        // needs something different.
        $timeout        = $options['timeout'] ?? $this->config['timeout'] ?? 60;
        $connectTimeout = $this->config['connect_timeout'] ?? 5;

        try {
            $response = $this->http->request('POST', $url, [
                'query'           => ['key' => $apiKey],
                'json'            => $body,
                'timeout'         => $timeout,
                'connect_timeout' => $connectTimeout,
            ]);
        } catch (ConnectException $e) {
            throw new AiTimeoutException(
                'Could not connect to the Gemini API within ' . $connectTimeout . 's.',
                previous: $e
            );
        } catch (RequestException $e) {
            throw $this->translateRequestException($e, $timeout);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (! is_array($data)) {
            throw new AiException('Gemini returned a response that could not be parsed as JSON.');
        }

        return AiResponse::fromGeminiPayload($data);
    }

    private function translateRequestException(RequestException $e, float $timeout): AiException
    {
        $response = $e->getResponse();

        // No response at all usually means the connection dropped or the
        // read timed out (the request was sent but nothing came back in
        // time) — Guzzle doesn't always surface this as ConnectException,
        // so check the underlying handler's errno as a fallback signal.
        if ($response === null) {
            $errno = $e->getHandlerContext()['errno'] ?? null;
            if ($errno === CURLE_OPERATION_TIMEDOUT || str_contains($e->getMessage(), 'timed out')) {
                return new AiTimeoutException(
                    "The Gemini API did not respond within {$timeout}s.",
                    previous: $e
                );
            }

            return new AiException('Failed to reach the Gemini API: ' . $e->getMessage(), previous: $e);
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $message = $decoded['error']['message'] ?? $e->getMessage();

        return match (true) {
            $status === 429 => new AiRateLimitException(
                $message,
                retryAfter: $this->parseRetryAfter($response),
                previous: $e
            ),
            $status === 401 || $status === 403 => new AiAuthenticationException($message, previous: $e),
            $status >= 500 => new AiServerException($message, previous: $e),
            default => new AiException($message, previous: $e),
        };
    }

    private function parseRetryAfter(\Psr\Http\Message\ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');
        return $header !== '' && is_numeric($header) ? (int) $header : null;
    }
}
