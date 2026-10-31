<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use Rhapsody\Core\Queue\Attributes\Backoff;
use Rhapsody\Core\Queue\Attributes\Timeout;
use Rhapsody\Core\Queue\Attributes\Tries;
use ReflectionClass;

/**
 * How a job class wants to be retried, read from its attributes (with
 * defaults) once per class per process.
 */
final class JobOptions
{
    public const int DEFAULT_TRIES   = 3;
    public const int DEFAULT_BACKOFF = 10;
    public const int DEFAULT_TIMEOUT = 60;

    private const int MAX_BACKOFF = 86400;

    /**
     * @var array<class-string, self>
     */
    private static array $cache = [];

    public function __construct(
        public readonly int $tries = self::DEFAULT_TRIES,
        public readonly int $backoffSeconds = self::DEFAULT_BACKOFF,
        public readonly bool $exponentialBackoff = true,
        public readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {
    }

    public static function for (object $job): self
    {
        return self::$cache[$job::class] ??= self::read($job);
    }

    /**
     * Seconds to wait after the given (1-based) failed attempt.
     */
    public function backoffFor(int $attempt): int
    {
        if (! $this->exponentialBackoff) {
            return min($this->backoffSeconds, self::MAX_BACKOFF);
        }

        $doublings = max(0, min($attempt - 1, 20));

        return min($this->backoffSeconds * (2 ** $doublings), self::MAX_BACKOFF);
    }

    private static function read(object $job): self
    {
        $reflection = new ReflectionClass($job);

        $tries   = $reflection->getAttributes(Tries::class)[0] ?? null;
        $backoff = $reflection->getAttributes(Backoff::class)[0] ?? null;
        $timeout = $reflection->getAttributes(Timeout::class)[0] ?? null;

        $backoffAttribute = $backoff?->newInstance();

        return new self(
            tries: $tries?->newInstance()->times ?? self::DEFAULT_TRIES,
            backoffSeconds: $backoffAttribute->seconds ?? self::DEFAULT_BACKOFF,
            exponentialBackoff: $backoffAttribute->exponential ?? true,
            timeout: $timeout?->newInstance()->seconds ?? self::DEFAULT_TIMEOUT,
        );
    }
}
