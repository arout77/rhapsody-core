<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\Ai\Exceptions\AiAuthenticationException;
use Rhapsody\Core\Ai\Exceptions\AiException;
use Rhapsody\Core\Ai\Exceptions\AiModelUnavailableException;
use Rhapsody\Core\Ai\Exceptions\AiRateLimitException;
use Rhapsody\Core\Ai\Exceptions\AiServerException;
use Rhapsody\Core\Ai\Exceptions\AiTimeoutException;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

/**
 * Generic AI endpoints over any AiClientInterface implementation:
 *   - generate(): single-prompt completion
 *   - chat(): multi-turn conversation
 *
 * Extend this (see BaseBillingController/BaseWebhookController for the
 * established pattern) to add app-specific behavior — rate limiting per
 * user, prompt templates, logging usage, etc. — without duplicating the
 * error-handling below.
 */
class BaseAiController extends BaseController
{
    public function __construct(protected AiClientInterface $ai)
    {
    }

    public function generate(Request $request): Response
    {
        $prompt = trim($request->string('prompt'));
        if ($prompt === '') {
            return $this->json(['error' => 'A "prompt" is required.'], 400);
        }

        $options = $this->extractOptions($request);

        try {
            $response = $this->ai->generateContent($prompt, $options);
        } catch (AiException $e) {
            return $this->respondToAiException($e);
        }

        return $this->json([
            'text'      => $response->getText(),
            'truncated' => $response->wasTruncated(),
            'blocked'   => $response->wasBlocked(),
            'usage'     => $response->getUsage(),
        ]);
    }

    public function chat(Request $request): Response
    {
        $messages = $request->array('messages');

        if (empty($messages)) {
            return $this->json(['error' => 'A non-empty "messages" array is required.'], 400);
        }

        $normalized = [];
        foreach ($messages as $message) {
            $role       = is_array($message) ? ($message['role'] ?? null) : null;
            $rawContent = is_array($message) ? ($message['content'] ?? '') : '';
            $content    = is_scalar($rawContent) ? trim((string) $rawContent) : '';

            if (($role !== 'user' && $role !== 'assistant') || $content === '') {
                return $this->json([
                    'error' => 'Each message must have a "role" of "user" or "assistant" and non-empty "content".',
                ], 400);
            }

            $normalized[] = ['role' => $role, 'content' => $content];
        }

        $options = $this->extractOptions($request);

        try {
            $response = $this->ai->generateChatContent($normalized, $options);
        } catch (AiException $e) {
            return $this->respondToAiException($e);
        }

        return $this->json([
            'text'      => $response->getText(),
            'truncated' => $response->wasTruncated(),
            'blocked'   => $response->wasBlocked(),
            'usage'     => $response->getUsage(),
        ]);
    }

    /**
     * Pull the supported generation options out of the request. Numeric strings
     * (as sent by HTML forms) are converted; anything invalid is dropped.
     *
     * @return array{temperature?: float|int, max_tokens?: int}
     */
    protected function extractOptions(Request $request): array
    {
        $options = [];

        $temperature = $request->input('temperature');
        if (is_int($temperature) || is_float($temperature)) {
            $options['temperature'] = $temperature;
        } elseif (is_string($temperature) && is_numeric($temperature)) {
            $options['temperature'] = (float) $temperature;
        }

        $maxTokens = $request->input('max_tokens');
        if (is_int($maxTokens)) {
            $options['max_tokens'] = $maxTokens;
        } elseif (is_string($maxTokens) && filter_var($maxTokens, FILTER_VALIDATE_INT) !== false) {
            $options['max_tokens'] = (int) $maxTokens;
        }

        return $options;
    }

    protected function respondToAiException(AiException $e): Response
    {
        $debug = $this->debugContext($e);

        if ($e instanceof AiRateLimitException) {
            $response = $this->json(array_merge([
                'error' => 'The AI service is temporarily rate-limited. Please try again shortly.',
            ], $debug), 429);
            if ($e->retryAfter !== null) {
                $response->setHeader('Retry-After', (string) $e->retryAfter);
            }
            return $response;
        }

        if ($e instanceof AiTimeoutException) {
            return $this->json(array_merge([
                'error' => 'The AI service took too long to respond. Please try again.',
            ], $debug), 504);
        }

        if ($e instanceof AiServerException) {
            return $this->json(array_merge([
                'error' => 'The AI service is currently unavailable. Please try again shortly.',
            ], $debug), 502);
        }

        if ($e instanceof AiModelUnavailableException) {
            error_log('AI model unavailable [' . ($e->model ?? 'unknown') . ']: ' . $e->getMessage());
            // This is a config problem (a stale/retired model name), not a
            // transient failure — it'll keep happening on every request
            // until GEMINI_MODEL gets updated. Give a subclass a chance to
            // do something about that (e.g. alert an admin) — core has no
            // opinion on how; see BaseAiController::onModelUnavailable().
            $this->onModelUnavailable($e);

            return $this->json(array_merge([
                'error' => 'The AI service is temporarily unavailable. Please try again shortly.',
            ], $debug), 503);
        }

        if ($e instanceof AiAuthenticationException) {
            error_log('AI auth error [' . get_class($e) . ']: ' . $e->getMessage());
            return $this->json(array_merge([
                'error' => 'The AI service is not configured correctly.',
            ], $debug), 500);
        }

        error_log('AI error [' . get_class($e) . ']: ' . $e->getMessage());
        return $this->json(array_merge([
            'error' => 'Something went wrong generating a response.',
        ], $debug), 500);
    }

    /**
     * Called whenever a request fails because the configured model is
     * invalid, deprecated, or no longer available — a no-op by default.
     * Core has no opinion on how you want to be notified (email, Slack,
     * an incident tool, whatever); override this in an app-level subclass
     * to hook in whichever mechanism fits, the same way BillingController
     * extends BaseBillingController to dispatch PaymentSucceededEvent/
     * PaymentFailedEvent on top of the base charge-handling.
     *
     * Example:
     *   protected function onModelUnavailable(AiModelUnavailableException $e): void
     *   {
     *       $this->dispatcher->dispatch(new AiModelUnavailableEvent($e->model, $e->getMessage()));
     *   }
     */
    protected function onModelUnavailable(AiModelUnavailableException $e): void
    {
        // Intentionally empty — see docblock above.
    }

    /**
     * In development, include the real exception class and message in the
     * JSON response — so a failure is visible directly in the API response
     * without needing log file access (which can be its own source of
     * confusion: php.ini's error_log target, log rotation, or just missing
     * the newest line in a growing file). Never included outside
     * development, to avoid leaking internals to real users/production
     * traffic.
     *
     * Reads APP_ENV directly rather than an injected config array, so this
     * doesn't require any change to how BaseAiController is constructed or
     * bound in the container.
     *
     * @return array<string, mixed>
     */
    protected function debugContext(\Throwable $e): array
    {
        if (($_ENV['APP_ENV'] ?? 'production') !== 'development') {
            return [];
        }

        return [
            'debug' => [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ],
        ];
    }
}
