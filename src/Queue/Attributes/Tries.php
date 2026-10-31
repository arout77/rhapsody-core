<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Maximum number of times a job is attempted before it is moved to the
 * failed jobs. Default: 3.
 *
 *     #[Tries(5)]
 *     final class SendInvoice implements JobInterface { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Tries
{
    public function __construct(public int $times)
    {
        if ($times < 1) {
            throw new InvalidArgumentException('#[Tries] needs at least 1 attempt.');
        }
    }
}
