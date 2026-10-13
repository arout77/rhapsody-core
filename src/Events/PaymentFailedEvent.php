<?php
namespace Rhapsody\Core\Events;

class PaymentFailedEvent
{
    public function __construct(
        public readonly string $message,
        public readonly array $context = []
    ) {}
}
