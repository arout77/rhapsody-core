<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use RuntimeException;

/**
 * A problem the developer can act on (unknown snapshot, bad patch, missing
 * API key...), carrying the HTTP status the web endpoints should answer with.
 * The message is always safe to show: it never contains request data.
 */
final class AssistException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
