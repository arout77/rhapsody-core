<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Contracts\FailableJobInterface;
use Rhapsody\Core\Contracts\JobInterface;
use Rhapsody\Core\Contracts\QueueInterface;
use Rhapsody\Core\Logger;
use Throwable;

/**
 * Pulls jobs off the queue and runs them.
 *
 * Runs two ways with the same code:
 *  - as a daemon (`queue:work`), looping and sleeping when idle;
 *  - from cron (`queue:work --stop-when-empty`), draining the queue and
 *    exiting, which is how it runs on shared hosting.
 *
 * Retry rules, per job class (see Queue\Attributes): `Tries` attempts, with
 * `Backoff` between them. Because the attempt counter is bumped when a job
 * is reserved, a job that keeps killing its worker (fatal error, timeout
 * kill, power cut) still runs out of attempts and lands in the failed jobs
 * instead of looping forever.
 */
final class Worker
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly JobSerializer $serializer,
        private readonly ContainerInterface $container,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Process jobs until a stop condition is met.
     *
     * @param  (callable(JobOutcome): void)|null $report Called after every job
     * @return int                               Number of jobs handled
     */
    public function work(WorkerOptions $options, ?callable $report = null): int
    {
        $this->shouldStop = false;
        $handled          = 0;
        $started          = time();

        $this->listenForStopSignals();

        while (! $this->shouldStop) {
            try {
                $outcome = $this->runNext($options->queues);
            } catch (Throwable $e) {
                // The queue itself failed (database down, tables missing).
                $this->logger->log('Queue error: ' . $e->getMessage(), 'ERROR');
                if ($options->stopWhenEmpty) {
                    throw $e;
                }
                sleep($options->sleep);

                continue;
            }

            if ($outcome === null) {
                if ($options->stopWhenEmpty) {
                    break;
                }
                sleep($options->sleep);
            } else {
                $handled++;
                if ($report !== null) {
                    $report($outcome);
                }
            }

            if (
                ($options->maxJobs > 0 && $handled >= $options->maxJobs)
                || ($options->maxTime > 0 && time() - $started >= $options->maxTime)
                || ($options->memoryMb > 0 && memory_get_usage(true) >= $options->memoryMb * 1048576)
            ) {
                break;
            }
        }

        return $handled;
    }

    /**
     * Take one job from the first queue that has one and run it.
     *
     * @param  list<string> $queues
     * @return JobOutcome|null Null when every queue is empty
     */
    public function runNext(array $queues): ?JobOutcome
    {
        foreach ($queues as $name) {
            $record = $this->queue->pop($name);
            if ($record !== null) {
                return $this->process($record);
            }
        }

        return null;
    }

    private function process(QueuedJob $record): JobOutcome
    {
        try {
            $job = $this->serializer->unserialize($record->payload);
        } catch (Throwable $e) {
            $this->queue->fail($record, $this->describe($e));
            $this->logger->log("Job #{$record->id} could not be read: {$e->getMessage()}", 'ERROR');

            return new JobOutcome(JobResult::Failed, 'unreadable payload', $record->id, $record->attempts, $e->getMessage());
        }

        $options = JobOptions::for($job);
        $name    = $job::class;

        if (! method_exists($job, 'handle')) {
            return $this->giveUp($record, $job, new QueueException($name . ' has no handle() method.'));
        }

        // Reserved more often than allowed: earlier workers died mid-job.
        if ($record->attempts > $options->tries) {
            return $this->giveUp($record, $job, new QueueException(
                "{$name} was attempted {$options->tries} time(s) without finishing (the worker likely crashed or timed out)."
            ));
        }

        try {
            $this->withTimeout($options->timeout, fn() => $this->container->call([$job, 'handle']));
        } catch (Throwable $e) {
            if ($record->attempts >= $options->tries) {
                return $this->giveUp($record, $job, $e);
            }

            $delay = $options->backoffFor($record->attempts);
            $this->queue->release($record, $delay);
            $this->logger->log(
                "Job #{$record->id} {$name} failed (attempt {$record->attempts}/{$options->tries}), retrying in {$delay}s: {$e->getMessage()}",
                'WARNING'
            );

            return new JobOutcome(JobResult::Released, $name, $record->id, $record->attempts, $e->getMessage());
        }

        $this->queue->delete($record);

        return new JobOutcome(JobResult::Processed, $name, $record->id, $record->attempts);
    }

    /**
     * Move the job to the failed jobs and let it know.
     */
    private function giveUp(QueuedJob $record, JobInterface $job, Throwable $e): JobOutcome
    {
        $name = $job::class;

        $this->queue->fail($record, $this->describe($e));
        $this->logger->log("Job #{$record->id} {$name} failed permanently: {$e->getMessage()}", 'ERROR');

        if ($job instanceof FailableJobInterface) {
            try {
                $job->failed($e);
            } catch (Throwable $hookError) {
                $this->logger->log("{$name}::failed() threw: {$hookError->getMessage()}", 'ERROR');
            }
        }

        return new JobOutcome(JobResult::Failed, $name, $record->id, $record->attempts, $e->getMessage());
    }

    private function describe(Throwable $e): string
    {
        return $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    }

    /**
     * Runs $run, aborting it with a JobTimeoutException after $seconds where
     * pcntl is available. Elsewhere the job simply runs unbounded.
     *
     * @param callable(): mixed $run
     */
    private function withTimeout(int $seconds, callable $run): void
    {
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            $run();

            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($seconds): never {
            throw new JobTimeoutException("The job ran longer than its {$seconds}s timeout.");
        });
        pcntl_alarm($seconds);

        try {
            $run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
    }

    /**
     * On SIGTERM / SIGINT (supervisor stop, Ctrl+C) finish the current job,
     * then leave the loop.
     */
    private function listenForStopSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->shouldStop = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
