<?php
namespace Rhapsody\Core\Ai\Exceptions;

/**
 * The provider rejected the request for rate-limit or quota reasons
 * (HTTP 429) — too many requests, or the account/API key has run out of
 * credits/quota. $retryAfter is populated when the provider supplies a
 * Retry-After header; null means "unknown, use your own backoff."
 */
class AiRateLimitException extends AiException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
