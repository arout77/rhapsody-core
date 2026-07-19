<?php
namespace Rhapsody\Core\Helpers;

use Omnipay\Common\GatewayInterface;
use Rhapsody\Core\Contracts\PaymentGatewayInterface;

class OmnipayGateway implements PaymentGatewayInterface
{
    protected GatewayInterface $gateway;
    protected string $defaultCurrency;

    public function __construct(GatewayInterface $gateway, string $defaultCurrency = 'USD')
    {
        $this->gateway         = $gateway;
        $this->defaultCurrency = $defaultCurrency;
    }

    public function charge($amount, $paymentMethod, array $options = []): array
    {
        $currency = $options['currency'] ?? $this->defaultCurrency;

        // Leverage Omnipay's unified purchase/authorize API
        $response = $this->gateway->purchase(array_merge([
            'amount'   => $amount,
            'currency' => $currency,
            'token'    => $paymentMethod,
        ], $options))->send();

        if ($response->isSuccessful()) {
            return [
                'success'        => true,
                'transaction_id' => $response->getTransactionReference(),
                'message'        => 'Payment approved.',
                'currency'       => $currency,
            ];
        }

        return [
            'success' => false,
            'message' => $response->getMessage(),
        ];
    }

    public function createCustomer(array $data): string
    {
        // Logic to create a customer via Omnipay (e.g., Stripe/PayPal)
        $response = $this->gateway->createCustomer($data)->send();

        return $response->getCustomerReference();
    }

    public function refund(string $transactionId, $amount = null): array
    {
        $response = $this->gateway->refund([
            'transactionReference' => $transactionId,
            'amount'               => $amount,
        ])->send();

        if ($response->isSuccessful()) {
            return [
                'success'        => true,
                'transaction_id' => $response->getTransactionReference(),
                'message'        => 'Refund processed successfully.',
            ];
        }

        return [
            'success' => false,
            'message' => $response->getMessage(),
        ];
    }
}
