<?php
namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\RedirectResponse;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Route;
use Rhapsody\Core\Session;

class GuestMiddleware extends Middleware
{
    /**
     * @param Request $request
     * @param Route|null $route
     */
    public function handle(Request $request, ?Route $route = null): ?Response
    {
        // If the user is already logged in, redirect them to their dashboard.
        if (Session::has('user_id')) {
            return new RedirectResponse(getenv('APP_BASE_URL') . '/dashboard');
        }

        return null;
    }
}
