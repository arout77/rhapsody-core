<?php
namespace Rhapsody\Core\Routing;

use Rhapsody\Core\Container;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Middleware\MiddlewareTracer;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;

/**
 * The Rhapsody Router.
 *
 * Responsible for matching incoming requests to controller actions,
 * executing middleware, and using the service container to resolve controllers
 * and their dependencies.
 */
class Router
{
    /**
     * The collection of registered routes.
     * @var Route[]
     */
    protected static array $routes = [];

    /**
     * A map of middleware keys to their fully qualified class names.
     * @var array
     */
    protected static array $middlewareMap = [];

    /**
     * Global middleware runs on every matched request.
     * @var array
     */
    protected static array $globalMiddleware = [];

    /**
     * The route that was successfully matched.
     * @var Route|null
     */
    protected static ?Route $matchedRoute = null;

    /**
     * Set the middleware configuration from the application.
     *
     * @param array $config Associative array with 'global' and 'map' keys.
     */
    public static function setMiddlewareConfig(array $config): void
    {
        self::$globalMiddleware = $config['global'] ?? [];
        self::$middlewareMap    = $config['map'] ?? [];
    }

    /**
     * Adds a new route to the collection.
     */
    protected static function add(string $method, string $path, mixed $callback): Route
    {
        $route          = new Route($method, $path, $callback);
        self::$routes[] = $route;
        return $route;
    }

    public static function get(string $path, mixed $callback): Route
    {
        return self::add('GET', $path, $callback);
    }

    public static function post(string $path, mixed $callback): Route
    {
        return self::add('POST', $path, $callback);
    }

    public static function put(string $path, mixed $callback): Route
    {
        return self::add('PUT', $path, $callback);
    }

    public static function delete(string $path, mixed $callback): Route
    {
        return self::add('DELETE', $path, $callback);
    }

    /**
     * Dispatches the incoming request, matching it against registered routes,
     * executing global and route-specific middleware, and returning a Response.
     *
     * @param Request $request
     * @param Container $container
     * @return Response
     */
    public static function dispatch(Request $request, Container $container): Response
    {
        $uri    = $request->getPath(); // Use getPath() to handle base URL stripping
        $method = $request->getMethod();

        // For debug toolbar
        MiddlewareTracer::reset();

        foreach (self::$routes as $route) {
            // Route::matches() does both method and path matching
            if ($route->matches($method, $uri)) {
                self::$matchedRoute = $route;

                // 1. RUN GLOBAL MIDDLEWARE
                foreach (self::$globalMiddleware as $middlewareClass) {
                    $middlewareInstance = $container->resolve($middlewareClass);
                    $middlewareInstance->handle($request);
                    $middlewareClass = get_class($middlewareInstance);
                    MiddlewareTracer::start($middlewareClass, 'global');
                    $response = $middlewareInstance->handle($request);
                    MiddlewareTracer::stop();
                }

                // 2. RUN ROUTE-SPECIFIC MIDDLEWARE
                foreach ($route->getMiddleware() as $middlewareKey) {
                    if (isset(self::$middlewareMap[$middlewareKey])) {
                        $middlewareInstance = $container->resolve(self::$middlewareMap[$middlewareKey]);
                        // Pass the route as the second argument
                        $middlewareInstance->handle($request, $route);
                        $middlewareClass = get_class($middlewareInstance);
                        MiddlewareTracer::start($middlewareClass, 'route', $route->getPath());
                        $middlewareInstance->handle($request, $route);
                        MiddlewareTracer::stop();
                    }
                }

                // 3. EXECUTE CONTROLLER
                return self::execute($route, $request, $container);
            }
        }

        return self::handleNotFound();
    }

    /**
     * Safely executes the resolved route callback.
     *
     * @param Route $route
     * @param Request $request
     * @param Container $container The application's service container.
     * @return Response
     */
    protected static function execute(Route $route, Request $request, Container $container): Response
    {
        $callback = $route->getCallback();
        $params   = $route->getParams();

        if (is_array($callback)) {
            $controllerClass = $callback[0];
            $action          = $callback[1];

            $controller = $container->resolve($controllerClass);
            return $controller->{$action}($request, ...$params);
        }

        if ($callback instanceof \Closure) {
            $result = call_user_func($callback, $request, ...$params);
            // If the closure already returns a Response, return it directly
            if ($result instanceof Response) {
                return $result;
            }
            // Otherwise, wrap it in a Response object
            $response = new Response();
            $response->setContent($result);
            return $response;
        }

        return self::handleNotFound();
    }

    /**
     * Handles the case where no route is found.
     */
    protected static function handleNotFound(): Response
    {
        throw new HttpException(404, 'Page not found');
    }

    /**
     * Returns the last successfully matched route.
     */
    public static function getMatchedRoute(): ?Route
    {
        return self::$matchedRoute;
    }

    /**
     * Returns all registered routes.
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Sets the route collection (useful for caching).
     */
    public static function setRoutes(array $routes): void
    {
        self::$routes = $routes;
    }
}
