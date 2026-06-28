<?php
namespace Rhapsody\Core\Contracts;

use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

/**
 * Interface for the router.
 *
 * This matches the non‑static public methods of Rhapsody\Core\Routing\Router.
 */
interface RouterInterface
{
    /**
     * Adds a route with the given method, path and callback.
     *
     * @param string $method
     * @param string $path
     * @param mixed $callback
     * @return \Rhapsody\Core\Routing\Route
     */
    public function addRoute(string $method, string $path, mixed $callback): \Rhapsody\Core\Routing\Route;

    /**
     * Registers a named route for URL generation.
     *
     * @param \Rhapsody\Core\Routing\Route $route
     */
    public function registerNamedRoute(\Rhapsody\Core\Routing\Route $route): void;

    /**
     * Dispatches the request, matching a route, executing middleware,
     * and returning a Response.
     *
     * @param Request $request
     * @param ContainerInterface $container
     * @return Response
     */
    public static function dispatch(Request $request, ContainerInterface $container): Response;
}
