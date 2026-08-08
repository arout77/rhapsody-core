<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Contracts\RouterInterface;
use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;
use Rhapsody\Core\Modules\RegisteredRoute;
use Rhapsody\Core\Routing\Route;
use Rhapsody\Core\Routing\Router;

/**
 * Three things a module can do with routes, all gated on "routes.register":
 *
 *  - get()/post()/put()/delete() — namespaced under /{prefix}/..., where
 *    prefix defaults to the module's own slug. This is the default and
 *    covers most modules; it can never collide with an app route or
 *    another module's routes, and isn't forced to contain any particular
 *    literal segment (no mandatory "/modules/" prefix).
 *
 *  - root() — registers an exact, unprefixed path (e.g. "/sitemap.xml",
 *    "/robots.txt"). Only allowed for paths the manifest explicitly
 *    whitelists under routes.register.paths — same reasoning as
 *    events.listen's whitelist: a module can ask for precisely the root
 *    path it needs without getting the run of the whole path space.
 *
 *  - registered() — read-only access to the live route table (app routes,
 *    core routes, and any other module's routes registered so far this
 *    request). Returns sanitized RegisteredRoute DTOs, never the raw
 *    Route objects, so a module can't reach a route's callback/closure.
 *    Gated by the same permission as writing, since reading the route
 *    table is strictly less powerful than being able to register into it.
 */
final class RoutesFacade
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ModulePermissions $permissions,
        private readonly string $slug,
    ) {
    }

    public function get(string $path, mixed $callback): Route
    {
        return $this->registerNamespaced('GET', $path, $callback);
    }

    public function post(string $path, mixed $callback): Route
    {
        return $this->registerNamespaced('POST', $path, $callback);
    }

    public function put(string $path, mixed $callback): Route
    {
        return $this->registerNamespaced('PUT', $path, $callback);
    }

    public function delete(string $path, mixed $callback): Route
    {
        return $this->registerNamespaced('DELETE', $path, $callback);
    }

    /**
     * Register an exact root-level path with no namespace prefix. Throws
     * unless $path is in this module's routes.register.paths whitelist.
     */
    public function root(string $method, string $path, mixed $callback): Route
    {
        $this->assertAllowed();

        $normalized = '/' . ltrim($path, '/');

        if (! in_array($normalized, $this->permissions->rootPaths(), true)) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to register root-level route \"{$normalized}\", " .
                'which isn\'t in its declared routes.register.paths whitelist'
            );
        }

        return $this->router->addRoute(strtoupper($method), $normalized, $callback);
    }

    /**
     * @return RegisteredRoute[] every route registered so far this request —
     *   app routes, core routes, and any module routes booted before this one.
     */
    public function registered(): array
    {
        $this->assertAllowed();

        return array_map(
            static fn (Route $route) => new RegisteredRoute(
                method: $route->getMethod(),
                path: $route->getPath(),
                name: $route->getName(),
                middleware: $route->getMiddleware(),
            ),
            Router::getRoutes(),
        );
    }

    private function registerNamespaced(string $method, string $path, mixed $callback): Route
    {
        $this->assertAllowed();

        $prefix   = $this->permissions->routePrefix($this->slug);
        $fullPath = '/' . trim($prefix, '/') . '/' . ltrim($path, '/');

        return $this->router->addRoute($method, $fullPath, $callback);
    }

    private function assertAllowed(): void
    {
        if (! $this->permissions->can('routes.register')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to register a route without declaring \"routes.register\" in module.json"
            );
        }
    }
}
