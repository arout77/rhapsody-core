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

        // Leverage Omnipay's unified purchase/authorize API. Wrapped in a
        // try/catch because Omnipay throws on things like a misconfigured
        // gateway, a network blip, or a malformed parameter — none of
        // which are "the card was declined" and none of which should
        // surface as an uncaught exception / 500 to the caller. The
        // interface contract promises a standardized array back either
        // way; this is what makes that true.
        try {
            $response = $this->gateway->purchase(array_merge([
                'amount'   => $amount,
                'currency' => $currency,
                'token'    => $paymentMethod,
            ], $options))->send();
        } catch (\Throwable $e) {
            // TODO: hook into whatever the framework's logging facade is
            // (not visible from this file) so these don't disappear
            // silently — for now this at least fails safe.
            return [
                'success' => false,
                'message' => "We couldn't reach the payment processor. Please try again in a moment.",
            ];
        }

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
        $params = ['transactionReference' => $transactionId];

        // Only send 'amount' when one was actually given — some Omnipay
        // drivers don't handle an explicit null the same as an omitted
        // key when you mean "refund the full amount".
        if ($amount !== null) {
            $params['amount'] = $amount;
        }

        try {
            $response = $this->gateway->refund($params)->send();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Refund could not be processed automatically — please refund manually via the Stripe dashboard.',
            ];
        }

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
