<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Contracts;

use Rhapsody\Core\Queue\FailedJob;
use Rhapsody\Core\Queue\QueuedJob;

/**
 * Storage driver for the persistent queue.
 *
 * Drivers only move opaque payload strings around; turning a job object into
 * a signed payload (and back) is the job of Queue\JobSerializer, so a new
 * driver (Redis, SQS, ...) never has to know about signing or retries.
 *
 * A job is *reserved* by pop(). The reservation token on the returned
 * QueuedJob is what release(), delete() and fail() check, so a worker that
 * lost its reservation (it ran past the retry-after window and another
 * worker took the job) can no longer change it.
 */
interface QueueInterface
{
    /**
     * Store a payload.
     *
     * @param  int $delay Seconds before the job may first be picked up
     * @return int The job id
     */
    public function push(string $payload, string $queue = 'default', int $delay = 0): int;

    /**
     * Atomically reserve the oldest available job, or null when there is none.
     * Reserving increments the job's attempt counter. A job whose reservation
     * is older than the driver's retry-after window counts as abandoned and
     * is reserved again.
     */
    public function pop(string $queue = 'default'): ?QueuedJob;

    /**
     * Give a reserved job back, to run again after $delay seconds. The
     * attempt counter is kept.
     */
    public function release(QueuedJob $job, int $delay = 0): void;

    /**
     * Remove a reserved job that finished successfully.
     */
    public function delete(QueuedJob $job): void;

    /**
     * Move a reserved job to the failed-jobs store, with the reason.
     */
    public function fail(QueuedJob $job, string $exception): void;

    /**
     * Number of jobs waiting in (or reserved from) a queue.
     */
    public function size(string $queue = 'default'): int;

    /**
     * Failed jobs, newest first.
     *
     * @return list<FailedJob>
     */
    public function failedJobs(): array;

    /**
     * Put a failed job back on its queue with a fresh attempt counter.
     *
     * @return bool False if there is no failed job with that id
     */
    public function retry(int $failedId): bool;

    /**
     * Delete one failed job permanently.
     *
     * @return bool False if there is no failed job with that id
     */
    public function forget(int $failedId): bool;

    /**
     * Delete every failed job permanently.
     *
     * @return int How many were removed
     */
    public function flush(): int;
}
