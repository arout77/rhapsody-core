<?php
declare(strict_types=1);

namespace Rhapsody\Core\Payments;

/**
 * The three outcomes a payment attempt can have.
 */
enum PaymentStatus: string
{
    /** Funds were captured. */
    case Succeeded = 'succeeded';

    /** The customer must authenticate (e.g. 3D Secure) before anything is captured. */
    case RequiresRedirect = 'requires_redirect';

    /** The payment was declined or could not be processed. */
    case Failed = 'failed';
}
