<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

/**
 * Thrown inside a running job when it exceeds its #[Timeout].
 */
final class JobTimeoutException extends QueueException
{
}
