<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use RuntimeException;

/**
 * Anything wrong with the queue itself: missing tables, a payload that
 * cannot be read, a job that cannot be serialised.
 */
class QueueException extends RuntimeException
{
}
