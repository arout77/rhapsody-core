<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\BaseController;
use Rhapsody\Core\Contracts\PaymentGatewayInterface;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

abstract class BaseBillingController extends BaseController
{
    protected PaymentGatewayInterface $gateway;

    public function __construct(PaymentGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function charge(Request $request): Response
    {
        $amount       = $request->input('amount');
        $paymentToken = $request->input('token');

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'A valid, positive "amount" is required.',
            ], 400);
        }

        if (empty($paymentToken)) {
            return $this->json([
                'success' => false,
                'message' => 'A payment "token" is required.',
            ], 400);
        }

        $result = $this->gateway->charge((float) $amount, $paymentToken);

        if ($result['success']) {
            return $this->json([
                'success'        => true,
                'transaction_id' => $result['transaction_id'],
                'message'        => $result['message'],
                'currency'       => $result['currency'] ?? null,
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }
}
