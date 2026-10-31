<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

/**
 * A job that used up its attempts (or could not be run) and now waits for
 * queue:retry or queue:flush.
 */
final readonly class FailedJob
{
    public function __construct(
        public int $id,
        public string $queue,
        public string $payload,
        public string $exception,
        public int $failedAt,
    ) {
    }
}
