<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use InvalidArgumentException;
use Rhapsody\Core\Contracts\JobInterface;
use Rhapsody\Core\Contracts\QueueInterface;

/**
 * The front door for queueing work: signs the job and hands it to the driver.
 *
 *     $queue->dispatch(new SendWelcomeEmail($userId));
 *     $queue->dispatch(new SendWelcomeEmail($userId), queue: 'emails', delay: 60);
 *
 * Application code usually uses the global dispatch() helper instead.
 */
final class QueueManager
{
    private const int MAX_QUEUE_NAME = 100;

    public function __construct(
        private readonly QueueInterface $driver,
        private readonly JobSerializer $serializer,
    ) {
    }

    /**
     * @param  int $delay Seconds before the job may first run
     * @throws InvalidArgumentException On a negative delay or a bad queue name
     * @throws QueueException           If the job cannot be serialised
     * @return int                      The job id
     */
    public function dispatch(JobInterface $job, string $queue = 'default', int $delay = 0): int
    {
        if ($delay < 0) {
            throw new InvalidArgumentException('A job delay cannot be negative.');
        }
        if ($queue === '' || strlen($queue) > self::MAX_QUEUE_NAME) {
            throw new InvalidArgumentException('A queue name must be 1-' . self::MAX_QUEUE_NAME . ' characters.');
        }

        return $this->driver->push($this->serializer->serialize($job), $queue, $delay);
    }

    /**
     * Jobs waiting in a queue.
     */
    public function size(string $queue = 'default'): int
    {
        return $this->driver->size($queue);
    }
}
