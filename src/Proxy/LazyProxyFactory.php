<?php
namespace Rhapsody\Core\Proxy;

use Rhapsody\Core\Container;

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
     */
    public function create(string $abstract): object
    {
        // Generate the proxy class code if not already generated.
        $proxyClass = $this->generator->generate($abstract);

        // The proxy class expects an initializer closure.
        $initializer = function (&$wrappedObject, $proxy, string $method, array $params, &$initializer) use ($abstract) {
            $initializer   = null; // prevent re-initialization
            $wrappedObject = $this->container->resolve($abstract);
            return true;
        };

        return new $proxyClass($initializer);
    }
}
