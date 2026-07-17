<?php
namespace Rhapsody\Core\Routing;

use Rhapsody\Core\Container;
use Rhapsody\Core\Contracts\ContainerInterface;
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
class Router implements \Rhapsody\Core\Contracts\RouterInterface
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

    protected static array $namedRoutes = []; // static, not instance

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

    public function addRoute(string $method, string $path, $callback): Route
    {
        $route                   = new Route($method, $path, $callback);
        $this->routes[$method][] = $route;
        return $route; // so you can chain ->name()
    }

    public function registerNamedRoute(Route $route): void
    {
        if ($name = $route->getName()) {
            $this->namedRoutes[$name] = $route;
        }
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
    public static function dispatch(Request $request, ContainerInterface $container): Response
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
                    $resolvedClass      = get_class($middlewareInstance);

                    MiddlewareTracer::start($resolvedClass, 'global');
                    $response = $middlewareInstance->handle($request, $route);
                    MiddlewareTracer::stop();

                    // A middleware that returns a Response wants to short-circuit
                    // the request (e.g. rate-limited, blocked, forbidden).
                    if ($response instanceof Response) {
                        return $response;
                    }
                }

                // 2. RUN ROUTE-SPECIFIC MIDDLEWARE
                foreach ($route->getMiddleware() as $middlewareKey) {
                    if (isset(self::$middlewareMap[$middlewareKey])) {
                        $middlewareInstance = $container->resolve(self::$middlewareMap[$middlewareKey]);
                        $resolvedClass      = get_class($middlewareInstance);

                        MiddlewareTracer::start($resolvedClass, 'route', $route->getPath());
                        $response = $middlewareInstance->handle($request, $route);
                        MiddlewareTracer::stop();

                        if ($response instanceof Response) {
                            return $response;
                        }
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
    protected static function execute(Route $route, Request $request, ContainerInterface $container): Response
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

    public static function addNamedRoute(string $name, Route $route): void
    {
        self::$namedRoutes[$name] = $route;
    }

    /**
     * Generate a URL from a named route.
     *
     * @param string $name   The route name.
     * @param array  $params Parameters to replace in the path (e.g., ['id' => 123]).
     * @return string
     * @throws \Exception If the route name is not found.
     */
    public static function generateUrl(string $name, array $params = []): string
    {
        if (! isset(self::$namedRoutes[$name])) {
            $available = implode(', ', array_keys(self::$namedRoutes));
            throw new \Exception("Route [{$name}] not found. Available routes: " . ($available ?: 'none'));
        }

        $route = self::$namedRoutes[$name];
        $path  = $route->getPath();

        // Replace {param} placeholders with values
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
        }

        // If there are still un-replaced placeholders, throw an exception
        if (preg_match('/\{[a-zA-Z0-9_]+\}/', $path)) {
            throw new \Exception("Missing parameters for route [{$name}].");
        }

        // Prepend base path if defined and not already present
        $basePath = $_ENV['APP_URL'] . $_ENV['APP_BASE_URL'];
        if ($basePath && strpos($path, $basePath) !== 0) {
            $path = rtrim($basePath, '/') . '/' . ltrim($path, '/');
        }

        return $path;
    }
}
