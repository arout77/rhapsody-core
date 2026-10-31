<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Seconds to wait before retrying a failed attempt. Default: 10, doubling
 * after every failed attempt (10s, 20s, 40s, ...), capped at one day.
 *
 *     #[Backoff(30)]                       // 30s, 60s, 120s, ...
 *     #[Backoff(30, exponential: false)]   // always 30s
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Backoff
{
    public function __construct(
        public int $seconds = 10,
        public bool $exponential = true,
    ) {
        if ($seconds < 0) {
            throw new InvalidArgumentException('#[Backoff] seconds cannot be negative.');
        }
    }
}
