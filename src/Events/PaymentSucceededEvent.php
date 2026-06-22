<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Events\Event;

class PaymentSucceededEvent extends Event
{
    public function __construct(
        public readonly string $transactionId,
        public readonly float $amount,
        public readonly array $metadata = []
    ) {}
}
