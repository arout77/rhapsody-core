<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

/**
 * A job a worker has reserved. `reservation` is the token the driver checks
 * on release/delete/fail; `attempts` already counts this attempt.
 */
final readonly class QueuedJob
{
    public function __construct(
        public int $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public string $reservation,
        public int $createdAt,
    ) {
    }
}
