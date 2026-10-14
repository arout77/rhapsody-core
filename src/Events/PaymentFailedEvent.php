<?php
namespace Rhapsody\Core\Events;

class PaymentFailedEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $message,
        public readonly array $context = []
    ) {}
}
