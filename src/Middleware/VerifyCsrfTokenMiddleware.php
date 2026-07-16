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
        'api/*', // Exclude all routes starting with 'api/'
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Request $request
     * @return void
     */
    public function handle(Request $request, ?Route $route = null): ?Response
    {
        if ($this->isPostRequest($request) && ! $this->inExceptArray($request)) {
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
     * Determine if the request is a POST request.
     *
     * @param  Request $request
     * @return bool
     */
    protected function isPostRequest(Request $request): bool
    {
        // Request::getMethod() returns a lowercase string (see Request.php) —
        // comparing against uppercase 'POST' here meant this always returned
        // false, so CSRF verification silently never ran on any request.
        return strtolower($request->getMethod()) === 'post';
    }

    /**
     * Determine if the request URI is in the exception array.
     *
     * @param  Request $request
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
