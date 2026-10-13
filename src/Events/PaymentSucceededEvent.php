<?php
namespace Rhapsody\Core\Events;

class PaymentSucceededEvent
{
    public function __construct(
        public readonly string $transactionId,
        public readonly float $amount,
        public readonly array $metadata = []
    ) {}
}
