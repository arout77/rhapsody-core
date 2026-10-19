<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Contracts;

/**
 * Runs work after the HTTP response has been delivered to the client.
 *
 * Always resolve this through its interface: the container hands out the
 * real, shared instance for `DeferrerInterface::class`.
 */
interface DeferrerInterface
{
    /**
     * Queue a task to run once the response has been sent.
     *
     * Tasks run first-in, first-out. A task may itself call defer(); the new
     * task runs later in the same flush. Capture what the task needs with
     * `use (...)`; it is invoked with no arguments.
     *
     * @param callable(): mixed $task
     */
    public function defer(callable $task): void;

    /**
     * Finish the response (where the SAPI allows) and run every queued task.
     * Never throws: a failing task is logged and the remaining tasks still run.
     */
    public function flush(): void;

    /**
     * Drop every queued task without running it.
     */
    public function discard(): void;

    /**
     * Number of tasks currently waiting to run.
     */
    public function pending(): int;
}
