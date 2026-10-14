<?php
namespace Rhapsody\Core\Ai\Exceptions;

/**
 * The configured model is invalid, not found, deprecated, or no longer
 * available (e.g. retired, or restricted from new accounts) — distinct
 * from the generic AiException catch-all specifically so this can be
 * detected and alerted on separately. This is a config problem, not a
 * transient failure: it will keep happening on every request until
 * someone updates GEMINI_MODEL, which is exactly the kind of thing you
 * want a human notified about rather than silently logged.
 */
class AiModelUnavailableException extends AiException
{
    public function __construct(
        string $message,
        public readonly ?string $model = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
