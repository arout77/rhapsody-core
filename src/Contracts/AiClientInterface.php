<?php
namespace Rhapsody\Core\Contracts;

use Rhapsody\Core\Ai\AiResponse;
use Rhapsody\Core\Ai\Exceptions\AiAuthenticationException;
use Rhapsody\Core\Ai\Exceptions\AiException;
use Rhapsody\Core\Ai\Exceptions\AiRateLimitException;
use Rhapsody\Core\Ai\Exceptions\AiServerException;
use Rhapsody\Core\Ai\Exceptions\AiTimeoutException;

/**
 * A provider-agnostic contract for text/chat generation — the same
 * pattern PaymentGatewayInterface uses to keep core decoupled from any one
 * vendor's SDK. v1 is deliberately narrow (text/chat only, no streaming,
 * multimodal, tool-calling, or embeddings) — add methods here, or a sibling
 * interface, when a real caller needs one of those.
 */
interface AiClientInterface
{
    /**
     * Generate content from a single prompt.
     *
     * @param string $prompt
     * @param array $options Provider-specific overrides, e.g. 'model',
     *   'temperature', 'max_tokens', 'top_p', 'top_k', 'timeout'.
     * @throws AiAuthenticationException Missing/invalid API key.
     * @throws AiRateLimitException Rate limit or quota exceeded.
     * @throws AiTimeoutException Request took too long.
     * @throws AiServerException The provider itself failed (5xx).
     * @throws AiException Any other failure to communicate with the provider.
     */
    public function generateContent(string $prompt, array $options = []): AiResponse;

    /**
     * Generate content from a multi-turn conversation.
     *
     * @param array $messages Ordered list of ['role' => 'user'|'assistant', 'content' => string].
     * @param array $options Same as generateContent().
     * @throws AiAuthenticationException Missing/invalid API key.
     * @throws AiRateLimitException Rate limit or quota exceeded.
     * @throws AiTimeoutException Request took too long.
     * @throws AiServerException The provider itself failed (5xx).
     * @throws AiException Any other failure to communicate with the provider.
     */
    public function generateChatContent(array $messages, array $options = []): AiResponse;
}
