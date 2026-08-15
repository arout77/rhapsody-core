<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\Ai\Exceptions\AiAuthenticationException;
use Rhapsody\Core\Ai\Exceptions\AiException;
use Rhapsody\Core\Ai\Exceptions\AiRateLimitException;
use Rhapsody\Core\Ai\Exceptions\AiServerException;
use Rhapsody\Core\Ai\Exceptions\AiTimeoutException;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

/**
 * Generic "send a prompt, get JSON back" endpoint over any AiClientInterface
 * implementation. Extend this (see BaseBillingController/BaseWebhookController
 * for the established pattern) to add app-specific behavior — rate limiting
 * per user, prompt templates, logging usage, etc. — without duplicating the
 * error-handling below.
 */
class BaseAiController extends BaseController
{
    public function __construct(protected AiClientInterface $ai)
    {
    }

    public function generate(Request $request): Response
    {
        $prompt = trim((string) $request->input('prompt'));
        if ($prompt === '') {
            return $this->json(['error' => 'A "prompt" is required.'], 400);
        }

        $options = array_filter([
            'temperature' => $request->input('temperature'),
            'max_tokens'  => $request->input('max_tokens'),
        ], fn ($v) => $v !== null);

        try {
            $response = $this->ai->generateContent($prompt, $options);
        } catch (AiAuthenticationException $e) {
            error_log('AI auth error: ' . $e->getMessage());
            return $this->json(['error' => 'The AI service is not configured correctly.'], 500);
        } catch (AiRateLimitException $e) {
            $payload = ['error' => 'The AI service is temporarily rate-limited. Please try again shortly.'];
            $res     = $this->json($payload, 429);
            if ($e->retryAfter !== null) {
                $res->setHeader('Retry-After', (string) $e->retryAfter);
            }
            return $res;
        } catch (AiTimeoutException $e) {
            return $this->json(['error' => 'The AI service took too long to respond. Please try again.'], 504);
        } catch (AiServerException $e) {
            return $this->json(['error' => 'The AI service is currently unavailable. Please try again shortly.'], 502);
        } catch (AiException $e) {
            error_log('AI error: ' . $e->getMessage());
            return $this->json(['error' => 'Something went wrong generating a response.'], 500);
        }

        return $this->json([
            'text'      => $response->getText(),
            'truncated' => $response->wasTruncated(),
            'blocked'   => $response->wasBlocked(),
            'usage'     => $response->getUsage(),
        ]);
    }
}
