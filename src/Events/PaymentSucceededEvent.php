<?php
namespace Rhapsody\Core\Events;

class PaymentSucceededEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $transactionId,
        public readonly float $amount,
        public readonly array $metadata = []
    ) {}
}
