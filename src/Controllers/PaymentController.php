<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\Contracts\PaymentGatewayInterface;
use Rhapsody\Core\Controller;
use Rhapsody\Core\Request;
use Rhapsody\Core\Session;

class PaymentController extends Controller
{
    public function checkout(Request $request, PaymentGatewayInterface $gateway)
    {
        $amount   = $request->post('amount', 10.00);
        $currency = 'USD';

        $response = $gateway->purchase([
            'amount'    => $amount,
            'currency'  => $currency,
            'returnUrl' => route('payment.return'),
            'cancelUrl' => route('payment.cancel'),
            // Additional parameters for Stripe: 'paymentMethod' => 'pm_card_visa', etc.
        ]);

        if ($response->isRedirect()) {
            // Off-site gateway (PayPal, etc.)
            return $response->redirect();
        } elseif ($response->isSuccessful()) {
            // On-site gateway (Stripe with direct charge)
            // Save transaction ID, update order, etc.
            Session::setFlash('success', 'Payment successful!');
            return redirect('payment.success');
        } else {
            Session::setFlash('error', $response->getMessage());
            return redirect('payment.failed');
        }
    }

    public function handleReturn(Request $request, PaymentGatewayInterface $gateway)
    {
        // For off-site gateways (PayPal, etc.) – the user is redirected back after payment.
        $response = $gateway->completePurchase([
            'amount'               => $request->get('amount', 10.00),
            'currency'             => 'USD',
            'transactionReference' => $request->get('transactionId'),
            // Other required parameters vary by gateway
        ])->send();

        if ($response->isSuccessful()) {
            // Save transaction reference, update order status
            Session::setFlash('success', 'Payment confirmed.');
            return redirect('payment.success');
        } else {
            Session::setFlash('error', $response->getMessage());
            return redirect('payment.failed');
        }
    }

    public function cancel()
    {
        Session::setFlash('info', 'Payment cancelled.');
        return redirect('payment.failed');
    }
}
