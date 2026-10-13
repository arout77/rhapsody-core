<?php
namespace Rhapsody\Core\Routing;

use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Middleware\AdminMiddleware;
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
     * Route middleware aliases core provides to every application. The
     * application's own map (setMiddlewareConfig) is consulted first, so an
     * app that registers its own `admin` keeps using it.
     * @var array<string, class-string>
     */
    protected const CORE_MIDDLEWARE = [
        'admin' => AdminMiddleware::class,
    ];

    /**
     * A map of middleware keys to their fully qualified class names.
     * @var array<string, class-string>
     */
    protected static array $middlewareMap = [];

    /**
     * Global middleware runs on every matched request.
     * @var list<class-string>
     */
    protected static array $globalMiddleware = [];

    /**
     * The route that was successfully matched.
     * @var Route|null
     */
    protected static ?Route $matchedRoute = null;

    /**
     * Named routes, keyed by name. Static so every Router instance and the
     * static helpers share one registry.
     * @var array<string, Route>
     */
    protected static array $namedRoutes = [];

    /**
     * Set the middleware configuration from the application.
     *
     * @param array{global?: list<class-string>, map?: array<string, class-string>} $config The application's middleware configuration.
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
        $route          = new Route($method, $path, $callback);
        self::$routes[] = $route; // must land in the same collection dispatch()/getRoutes() read —
                                  // previously this wrote to a non-existent $this->routes property,
                                  // so routes added this way were never actually matched.
        return $route;            // so you can chain ->name()
    }

    public function registerNamedRoute(Route $route): void
    {
        if ($name = $route->getName()) {
            self::addNamedRoute($name, $route);
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
     * @param  Request    $request
     * @param  ContainerInterface $container
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
                    $response = self::runMiddleware($container, $middlewareClass, $request, $route, 'global');

                    // A middleware that returns a Response wants to short-circuit
                    // the request (e.g. rate-limited, blocked, forbidden).
                    if ($response !== null) {
                        return $response;
                    }
                }

                // 2. RUN ROUTE-SPECIFIC MIDDLEWARE
                foreach ($route->getMiddleware() as $middlewareKey) {
                    $middlewareClass = self::resolveMiddlewareClass($middlewareKey);

                    // Fail closed. This used to skip an alias that wasn't in the
                    // map, which meant a route guarded with ->middleware('admin')
                    // (or a typo of 'auth') silently ran with NO protection on
                    // any app that hadn't registered that alias.
                    if ($middlewareClass === null) {
                        throw new \RuntimeException(sprintf(
                            'Route %s %s uses middleware "%s", which is not registered. ' .
                            'Add it to the application\'s middleware map or remove it from the route; ' .
                            'refusing to run the route without it.',
                            $route->getMethod(),
                            $route->getPath(),
                            $middlewareKey
                        ));
                    }

                    $response = self::runMiddleware($container, $middlewareClass, $request, $route, 'route');

                    if ($response !== null) {
                        return $response;
                    }
                }

                // 3. EXECUTE CONTROLLER
                return self::execute($route, $request, $container);
            }
        }

        return self::handleNotFound();
    }

    /**
     * Resolves a middleware from the container and runs its handle() method.
     *
     * Middleware classes are duck-typed (no shared interface), so the
     * handle() contract is verified here once instead of at every call site.
     *
     * @param  'global'|'route' $scope Which stack the middleware belongs to (for the debug toolbar).
     * @return Response|null    The short-circuit Response, or null to continue the request.
     */
    protected static function runMiddleware(
        ContainerInterface $container,
        string $middlewareClass,
        Request $request,
        Route $route,
        string $scope
    ): ?Response {
        $middleware = $container->resolve($middlewareClass);

        if (! is_object($middleware) || ! method_exists($middleware, 'handle')) {
            throw new \RuntimeException(sprintf('Middleware "%s" must define a handle() method.', $middlewareClass));
        }

        if ($scope === 'route') {
            MiddlewareTracer::start($middleware::class, 'route', $route->getPath());
        } else {
            MiddlewareTracer::start($middleware::class, 'global');
        }

        $response = $middleware->handle($request, $route);
        MiddlewareTracer::stop();

        return $response instanceof Response ? $response : null;
    }

    /**
     * Looks up a route-middleware alias: the application's map first, then
     * the aliases core ships. Null means "not registered anywhere".
     */
    protected static function resolveMiddlewareClass(string $key): ?string
    {
        return self::$middlewareMap[$key] ?? self::CORE_MIDDLEWARE[$key] ?? null;
    }

    /**
     * Safely executes the resolved route callback.
     *
     * @param  Route      $route
     * @param  Request    $request
     * @param  ContainerInterface $container The application's service container.
     * @return Response
     */
    protected static function execute(Route $route, Request $request, ContainerInterface $container): Response
    {
        $callback = $route->getCallback();
        $params   = $route->getParams();

        if (is_array($callback)) {
            $controllerClass = $callback[0];
            $action          = $callback[1];

            // $callback[0] may be a class name (resolve via container, the
            // normal app-route case) or an already-instantiated object (the
            // module-route case, where the provider constructs the controller
            // itself with facade dependencies the container can't autowire).
            $controller = is_string($controllerClass)
                ? $container->resolve($controllerClass)
                : $controllerClass;

            return $controller->{$action}($request, ...$params);
        }

        if ($callback instanceof \Closure) {
            $result = call_user_func($callback, $request, ...$params);
            if ($result instanceof Response) {
                return $result;
            }
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
     * Clears the last matched route.
     *
     * Under classic per-request PHP this is harmless and never observably
     * matters, since the process (and this static property) dies before
     * anything could read a stale value. Under a persistent-worker runtime,
     * where this class's statics survive across requests, it should be called
     * at the start of every request so a route matched by a previous request
     * is never visible before the current request's dispatch() runs.
     */
    public static function resetMatchedRoute(): void
    {
        self::$matchedRoute = null;
    }

    /**
     * Returns all registered routes.
     *
     * @return array<int, Route>
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Sets the route collection (useful for caching).
     *
     * @param array<int, Route> $routes
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
     * @param  string     $name   The route name.
     * @param  array<string, string|int> $params Parameters to replace in the path (e.g., ['id' => 123]).
     * @throws \Exception If the route name is not found.
     * @return string
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
        $appUrl   = $_ENV['APP_URL'] ?? '';
        $baseUrl  = $_ENV['APP_BASE_URL'] ?? '';
        $basePath = (is_string($appUrl) ? $appUrl : '') . (is_string($baseUrl) ? $baseUrl : '');
        if ($basePath && strpos($path, $basePath) !== 0) {
            $path = rtrim($basePath, '/') . '/' . ltrim($path, '/');
        }

        return $path;
    }
}
