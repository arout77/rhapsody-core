<?php
namespace Rhapsody\Core\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Charge a customer or a specified amount.
     *
     * @param float|int $amount (in minor units, e.g., cents)
     * @param string|object $paymentMethod Reference to a token or payment method
     * @param array<string, mixed> $options Additional gateway-specific parameters
     * @return array<string, mixed> Returns a standardized result payload
     */
    public function charge($amount, $paymentMethod, array $options = []): array;

    /**
     * Create a customer in the payment provider's system.
     *
     * @param array<string, mixed> $data
     */
    public function createCustomer(array $data): string;

    /**
     * Refund a previously captured transaction.
     *
     * @param float|int|null $amount Minor units; null refunds the full amount.
     * @return array<string, mixed>
     */
    public function refund(string $transactionId, $amount = null): array;
}
