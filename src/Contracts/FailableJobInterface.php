<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Contracts;

use Throwable;

/**
 * A job that wants to know when it has permanently failed (all tries used,
 * or the payload could not be run). Called once, after the job has been
 * moved to the failed-jobs store. An exception thrown here is logged and
 * otherwise ignored.
 */
interface FailableJobInterface extends JobInterface
{
    public function failed(Throwable $exception): void;
}
