<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Events\Event;

class PaymentFailedEvent extends Event
{
    public function __construct(
        public readonly string $message,
        public readonly array $context = []
    ) {}
}
