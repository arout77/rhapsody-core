<?php
namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Route;
use Rhapsody\Core\Session;

class VerifyCsrfTokenMiddleware extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     * Wildcards (*) are supported.
     *
     * @var array
     */
    protected array $except = [
        'api/*',           // Exclude all routes starting with 'api/'
        'payment/webhook', // Stripe/PayPal webhooks are authenticated by signature, not a session token
    ];

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @return void
     */
    public function handle(Request $request, ?Route $route = null): ?Response
    {
        if ($this->isMutatingRequest($request) && ! $this->inExceptArray($request)) {
            $token = $request->get('_token') ?? ($request->getBody()['_token'] ?? '');
            if (! Session::verifyCsrfToken($token)) {
                $response = new Response();
                $response->setStatusCode(419);
                $response->setHeader('Content-Type', 'text/plain');
                $response->setContent('CSRF token mismatch.');
                return $response;
            }
        }

        return null;
    }

    /**
     * Determine if the request uses a state-mutating HTTP method that
     * requires CSRF verification (POST, PUT, PATCH, DELETE).
     *
     * @param Request $request
     * @return bool
     */
    protected function isMutatingRequest(Request $request): bool
    {
        return in_array(strtolower($request->getMethod()), ['post', 'put', 'patch', 'delete'], true);
    }

    /**
     * Determine if the request URI is in the exception array.
     *
     * @param Request $request
     * @return bool
     */
    protected function inExceptArray(Request $request): bool
    {
        foreach ($this->except as $except) {
            // The request's `is` method handles wildcard matching
            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }
}
