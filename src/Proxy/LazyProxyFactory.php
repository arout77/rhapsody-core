<?php
namespace Rhapsody\Core\Proxy;

use Rhapsody\Core\Container;

/**
 * Factory for creating lazy-loading proxies for any service.
 * Uses our built‑in ProxyGenerator (no external dependencies).
 */
class LazyProxyFactory
{
    private Container $container;
    private ProxyGenerator $generator;

    public function __construct(Container $container, string $cacheDir)
    {
        $this->container = $container;
        $this->generator = new ProxyGenerator($cacheDir);
    }

    /**
     * Create a lazy proxy for the given class/interface.
     *
     * @param string $abstract The class or interface name.
     * @return object The generated proxy instance.
     */
    public function create(string $abstract): object
    {
        // Generate the proxy class code if not already generated.
        $proxyClass = $this->generator->generate($abstract);

        // The proxy class expects an initializer closure.
        $initializer = function (&$wrappedObject, $proxy, string $method, array $params, &$initializer) use ($abstract) {
            $initializer = null; // prevent re-initialization

            // Enable proxy mode so the container marks the trace as lazy
            Container::setProxyMode(true);
            try {
                $wrappedObject = $this->container->resolve($abstract);
            } finally {
                Container::setProxyMode(false);
            }

            return true;
        };

        return new $proxyClass($initializer);
    }
}
