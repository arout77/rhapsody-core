<?php

namespace Rhapsody\Core\Modules;

/**
 * A sanitized, read-only view of a registered Route — what RoutesFacade::registered()
 * hands to module code. Deliberately not the raw Rhapsody\Core\Routing\Route object:
 * modules get the data they need (method, path, name, middleware keys, and the
 * handling controller's class name if there is one) without a reference to the
 * route's actual callback/closure.
 */
final class RegisteredRoute
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $name,
        /** @var string[] */
        public readonly array $middleware,
        /**
         * The controller class handling this route, if it's a
         * [ControllerClass::class, 'method'] or [$instance, 'method']
         * callback — null for closures/first-class callables, where
         * there's no meaningful class to report.
         */
        public readonly ?string $controller = null,
    ) {
    }

    /** True if the path contains a {param} placeholder — can't be enumerated as a single concrete URL. */
    public function hasParams(): bool
    {
        return str_contains($this->path, '{');
    }
}
