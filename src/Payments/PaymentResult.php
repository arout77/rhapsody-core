<?php
declare(strict_types=1);

namespace Rhapsody\Core\Payments;

use LogicException;

/**
 * The outcome of a payment gateway call.
 *
 * Replaces the loose `array{success, transaction_id, ...}` payloads: the three
 * named constructors make an inconsistent result (a "success" with no
 * transaction id, a redirect with no URL) impossible to build.
 *
 * @example
 * $result = $gateway->initiatePurchase(1999, $paymentMethodId);
 *
 * return match ($result->status) {
 *     PaymentStatus::Succeeded        => $this->finalize($result->transactionId()),
 *     PaymentStatus::RequiresRedirect => redirect($result->redirectUrl()),
 *     PaymentStatus::Failed           => $this->showError($result->message),
 * };
 */
final readonly class PaymentResult
{
    private function __construct(
        public PaymentStatus $status,
        public string $message,
        private ?string $transactionId = null,
        private ?string $redirectUrl = null,
        public ?string $currency = null,
    ) {
    }

    public static function success(string $transactionId, string $message = 'Payment approved.', ?string $currency = null): self
    {
        return new self(PaymentStatus::Succeeded, $message, transactionId: $transactionId, currency: $currency);
    }

    public static function redirect(string $redirectUrl, string $message = 'Additional authentication is required.'): self
    {
        return new self(PaymentStatus::RequiresRedirect, $message, redirectUrl: $redirectUrl);
    }

    public static function failure(string $message): self
    {
        return new self(PaymentStatus::Failed, $message);
    }

    public function succeeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    public function needsRedirect(): bool
    {
        return $this->status === PaymentStatus::RequiresRedirect;
    }

    public function failed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    /**
     * @throws LogicException If the payment did not succeed
     */
    public function transactionId(): string
    {
        return $this->transactionId ?? throw new LogicException('Only a successful payment has a transaction id.');
    }

    /**
     * @throws LogicException If the payment does not require a redirect
     */
    public function redirectUrl(): string
    {
        return $this->redirectUrl ?? throw new LogicException('Only a payment that requires a redirect has a redirect URL.');
    }

    /**
     * Client-safe payload for JSON responses. Gateway redirect URLs are
     * deliberately left out.
     *
     * @return array{success: bool, message: string, transaction_id?: string, currency?: string}
     */
    public function toArray(): array
    {
        $payload = ['success' => $this->succeeded(), 'message' => $this->message];

        if ($this->transactionId !== null) {
            $payload['transaction_id'] = $this->transactionId;
        }
        if ($this->currency !== null) {
            $payload['currency'] = $this->currency;
        }

        return $payload;
    }
}
