<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

/**
 * Report for one job handled by the worker, handed to the work() callback.
 */
final readonly class JobOutcome
{
    public function __construct(
        public JobResult $result,
        public string $job,
        public int $id,
        public int $attempts,
        public ?string $error = null,
    ) {
    }
}
