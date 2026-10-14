<?php
namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\RedirectResponse;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Route;
use Rhapsody\Core\Session;

class AuthMiddleware extends Middleware
{
    /**
     * @param Request $request
     * @param Route|null $route
     */
    public function handle(Request $request, ?Route $route = null): ?Response
    {
        // If the user is not logged in, redirect them to the login page.
        if (! Session::has('user_id')) {
            return new RedirectResponse(getenv('APP_BASE_URL') . '/login');
        }

        return null;
    }
}
