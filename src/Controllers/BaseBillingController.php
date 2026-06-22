<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\BaseController;
use Rhapsody\Core\Contracts\PaymentGatewayInterface;
use Rhapsody\Core\Http\Request;
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

        $result = $this->gateway->charge($amount, $paymentToken);

        if ($result['success']) {
            return $this->json([
                'success'        => true,
                'transaction_id' => $result['transaction_id'],
                'message'        => $result['message'],
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }
}
