<?php
namespace Rhapsody\Core\Proxy;

use Rhapsody\Core\BaseController;
use Rhapsody\Core\Container;
use Rhapsody\Core\Contracts\ContainerInterface;

class ContainerDecorator implements ContainerInterface
{
    private Container $container;
    private LazyProxyFactory $proxyFactory;
    private bool $enabled;
    private array $eagerServices = [];

    public function __construct(
        Container $container,
        LazyProxyFactory $proxyFactory,
        bool $enabled,
        array $eagerServices = []
    ) {
        $this->container     = $container;
        $this->proxyFactory  = $proxyFactory;
        $this->enabled       = $enabled;
        $this->eagerServices = $eagerServices;
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    public function has(string $abstract): bool
    {
        return $this->container->has($abstract);
    }

    public function bind(string $abstract, callable | string | null $concrete = null): void
    {
        $this->container->bind($abstract, $concrete);
    }

    public function instance(string $abstract, $instance): void
    {
        $this->container->instance($abstract, $instance);
    }

    public function resolve(string $abstract): mixed
    {
        if (! class_exists($abstract) && ! interface_exists($abstract)) {
            return $this->container->resolve($abstract);
        }

        if (! $this->enabled || $this->isEager($abstract)) {
            return $this->container->resolve($abstract);
        }

        // Skip proxying if the abstract is not a class/interface
        // (e.g., string bindings like 'config' or scalar values)
        if (class_exists($abstract) && is_subclass_of($abstract, \Rhapsody\Core\BaseController::class)) {
            return $this->container->resolve($abstract);
        }

        return $this->proxyFactory->create($abstract);
    }

    public static function getTrace(): array
    {
        return Container::getTrace();
    }

    public static function resetTrace(): void
    {
        Container::resetTrace();
    }

    private function isEager(string $abstract): bool
    {
        return in_array($abstract, $this->eagerServices, true);
    }
}
