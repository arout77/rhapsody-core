<?php
namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Routing\Route;

/**
 * The base class for all middleware.
 */
abstract class Middleware
{
    /**
     * Handles the middleware logic.
     * This method should be implemented by all child middleware classes.
     *
     * Return a Response to short-circuit the request (e.g. a redirect,
     * a 403/429 block, etc). Return null to let the request continue
     * through the rest of the pipeline.
     *
     * @param  Request         $request
     * @param  Route|null      $route     The matched route, when running as route-specific middleware.
     * @return Response|null
     */
    abstract public function handle(Request $request, ?Route $route = null): ?Response;
}
