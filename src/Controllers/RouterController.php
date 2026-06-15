<?php
namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\Container;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Router;

class RouterController extends Router
{
    public static function dispatch(Request $request, Container $container): Response
    {
        $response = parent::dispatch($request, $container);

        // Convert 404 responses into exceptions so the error handler can show a custom page
        if ($response->getStatusCode() === 404) {
            throw new HttpException(404, 'Page not found');
        }

        if ($response->getStatusCode() === 500) {
            throw new HttpException(500, 'Server error');
        }

        return $response;
    }
}
