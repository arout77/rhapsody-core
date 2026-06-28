<?php
namespace Rhapsody\Core\Services;

use Omnipay\Omnipay;
use Rhapsody\Core\Contracts\PaymentGatewayInterface;

class PaymentGateway implements PaymentGatewayInterface
{
    protected $gateway;

    public function __construct()
    {
        $gatewayName   = $_ENV['PAYMENT_GATEWAY'] ?? 'Stripe';
        $this->gateway = Omnipay::create($gatewayName);

        // Configure based on gateway type
        switch ($gatewayName) {
            case 'Stripe':
                $this->gateway->setApiKey($_ENV['STRIPE_SECRET_KEY']);
                break;
            case 'PayPal_Rest':
                $this->gateway->setClientId($_ENV['PAYPAL_CLIENT_ID']);
                $this->gateway->setSecret($_ENV['PAYPAL_SECRET']);
                $this->gateway->setTestMode(filter_var($_ENV['PAYPAL_TEST_MODE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
                break;
                // add others as needed
        }
    }

    public function purchase(array $parameters)
    {
        return $this->gateway->purchase($parameters)->send();
    }

    public function refund(array $parameters)
    {
        return $this->gateway->refund($parameters)->send();
    }

    public function completePurchase(array $parameters)
    {
        return $this->gateway->completePurchase($parameters)->send();
    }

    // Add any other methods you need (e.g., fetch transaction, create subscription)
}
