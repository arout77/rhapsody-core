<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Seconds a single attempt may run before the worker aborts it (counted as a
 * failed attempt). Default: 60. Enforced where the pcntl extension is
 * available (Linux/macOS CLI); elsewhere it is not enforced.
 *
 * Keep it below the queue driver's retry-after window (90s by default), or a
 * slow job can be handed to a second worker while the first is still on it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Timeout
{
    public function __construct(public int $seconds)
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('#[Timeout] needs at least 1 second.');
        }
    }
}
