<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

/**
 * What happened to one job a worker picked up.
 */
enum JobResult
{
    /**
     * It ran and was removed from the queue.
     */
    case Processed;

    /**
     * It threw, and was put back to try again after a backoff.
     */
    case Released;

    /**
     * It is out of attempts (or unrunnable) and was moved to the failed jobs.
     */
    case Failed;
}
