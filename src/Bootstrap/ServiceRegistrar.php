<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Bootstrap;

use LogicException;
use Rhapsody\Core\Container;

/**
 * The only door application code gets to the container during boot.
 *
 * Users can add new services freely and replace the core services core has
 * marked extendable. Replacing any other core binding throws.
 */
final class ServiceRegistrar
{
    /**
     * @var array<string, true>
     */
    private readonly array $extendable;

    /**
     * @var array<string, true> Keys registered through this registrar, always re-bindable.
     */
    private array $owned = [];

    /**
     * @param list<string> $extendable Core abstracts users may replace.
     */
    public function __construct(
        private readonly Container $container,
        array $extendable = [],
    ) {
        $this->extendable = array_fill_keys($extendable, true);
    }

    public function bind(string $abstract, callable | string | null $concrete = null): void
    {
        $this->guard($abstract);
        $this->container->bind($abstract, $concrete);
        $this->claim($abstract);
    }

    public function singleton(string $abstract, callable | string | null $concrete = null): void
    {
        $this->guard($abstract);
        $this->container->singleton($abstract, $concrete);
        $this->claim($abstract);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->guard($abstract);
        $this->container->instance($abstract, $instance);
        $this->claim($abstract);
    }

    public function has(string $abstract): bool
    {
        return $this->container->has($abstract);
    }

    public function isExtendable(string $abstract): bool
    {
        return isset($this->extendable[$abstract]);
    }

    private function guard(string $abstract): void
    {
        if (
            isset($this->owned[$abstract])
            || isset($this->extendable[$abstract])
            || ! $this->container->has($abstract)
        ) {
            return;
        }

        throw new LogicException(sprintf(
            '"%s" is a core binding and cannot be replaced from bootstrap.php. '
            . 'Extend the class and bind your subclass only if core lists it as extendable.',
            $abstract
        ));
    }

    private function claim(string $abstract): void
    {
        // Container::bind()/instance() leave an already-resolved singleton cached.
        $this->container->forgetSingleton($abstract);
        $this->owned[$abstract] = true;
    }
}
