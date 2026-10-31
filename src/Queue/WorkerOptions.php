<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use InvalidArgumentException;

/**
 * Settings for one Worker::work() run.
 */
final readonly class WorkerOptions
{
    /**
     * @param list<string> $queues        Queues to poll, in priority order (the first one with a job wins)
     * @param int          $sleep         Seconds to wait when every queue is empty
     * @param int          $maxJobs       Stop after this many jobs (0 = no limit)
     * @param int          $maxTime       Stop after this many seconds (0 = no limit)
     * @param int          $memoryMb      Stop once memory use reaches this many MB (0 = no limit)
     * @param bool         $stopWhenEmpty Exit as soon as every queue is empty (for cron)
     */
    public function __construct(
        public array $queues = ['default'],
        public int $sleep = 3,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int $memoryMb = 128,
        public bool $stopWhenEmpty = false,
    ) {
        if ($queues === []) {
            throw new InvalidArgumentException('A worker needs at least one queue.');
        }
        if ($sleep < 1) {
            throw new InvalidArgumentException('Sleep must be at least 1 second.');
        }
        if ($maxJobs < 0 || $maxTime < 0 || $memoryMb < 0) {
            throw new InvalidArgumentException('Worker limits cannot be negative.');
        }
    }
}
