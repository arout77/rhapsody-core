<?php
namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\Request;
use Rhapsody\Core\Session;

class AuthMiddleware extends Middleware
{
    /**
     * @param Request $request
     */
    public function handle(Request $request): void
    {
        // If the user is not logged in...
        if (! Session::has('user_id')) {
            // ...redirect them to the login page and stop execution.
            header('Location: ' . getenv('APP_BASE_URL') . '/login');
            exit();
        }
    }
}
