<?php

namespace Rhapsody\Core\Modules;

/**
 * A sanitized, read-only view of a registered Route — what RoutesFacade::registered()
 * hands to module code. Deliberately not the raw Rhapsody\Core\Routing\Route object:
 * modules get the data they need (method, path, name, middleware keys) without a
 * reference to framework internals like the route's raw callback/closure.
 */
final class RegisteredRoute
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $name,
        /** @var string[] */
        public readonly array $middleware,
    ) {
    }

    /** True if the path contains a {param} placeholder — can't be enumerated as a single concrete URL. */
    public function hasParams(): bool
    {
        return str_contains($this->path, '{');
    }
}
