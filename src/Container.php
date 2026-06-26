<?php
namespace Rhapsody\Core;

use ReflectionClass;
use ReflectionParameter;

class Container
{
    /**
     * Holds the registered bindings (recipes for how to build objects).
     * @var array
     */
    protected array $bindings = [];

    /**
     * Tracks currently resolving classes to detect circular dependencies.
     * @var array
     */
    protected array $resolving = [];

    /**
     * Static trace of all resolved services (for the debug toolbar).
     * @var array
     */
    private static array $resolveTrace = [];

    /**
     * Binds a class or interface to the container.
     *
     * @param string $abstract The class/interface name to bind.
     * @param callable|string|null $concrete The concrete implementation or a closure.
     */
    public function bind(string $abstract, callable | string | null $concrete = null): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Stores a shared instance (singleton) in the container.
     *
     * @param string $abstract
     * @param mixed $instance
     */
    public function instance(string $abstract, $instance): void
    {
        $this->bindings[$abstract] = function () use ($instance) {
            return $instance;
        };
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     * Maps as an alias to the autowired resolve method.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed
     * @throws \Exception
     */
    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * Checks if a binding exists in the container.
     *
     * @param string $abstract The class/interface name.
     * @return bool
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    /**
     * Resolves a class from the container, automatically injecting dependencies.
     *
     * @param string $abstract The class name to resolve.
     * @return mixed
     * @throws \Exception
     */
    public function resolve(string $abstract): mixed
    {
        // If we have a specific recipe (a closure) for this class, use it.
        if (isset($this->bindings[$abstract]) && is_callable($this->bindings[$abstract])) {
            return call_user_func($this->bindings[$abstract], $this);
        }

        // --- Circular dependency detection ---
        if (isset($this->resolving[$abstract])) {
            // Build the circular chain for the error message
            $chain                = implode(' → ', array_keys($this->resolving)) . ' → ' . $abstract;
            self::$resolveTrace[] = [
                'class'    => $abstract,
                'resolved' => false,
                'circular' => true,
                'stack'    => $this->resolving,
            ];
            throw new \Exception("Circular dependency detected: " . $chain);
        }

        // Mark this class as currently being resolved
        $this->resolving[$abstract] = true;
        $start                      = microtime(true);

        try {
            // Otherwise, try to autowire it using PHP's Reflection API.
            $reflector = new ReflectionClass($abstract);

            if (! $reflector->isInstantiable()) {
                throw new \Exception("Class {$abstract} is not instantiable.");
            }

            $constructor = $reflector->getConstructor();

            if (is_null($constructor)) {
                // If there's no constructor, we can just create a new instance.
                $instance = new $abstract();
            } else {
                // Get the constructor's parameters.
                $parameters   = $constructor->getParameters();
                $dependencies = $this->resolveDependencies($parameters);
                $instance     = $reflector->newInstanceArgs($dependencies);
            }

            // Record successful resolution for debugging
            $duration             = round((microtime(true) - $start) * 1000, 2);
            $trace                = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller               = isset($trace[1]['class']) ? $trace[1]['class'] : 'unknown';
            self::$resolveTrace[] = [
                'class'     => $abstract,
                'resolved'  => true,
                'duration'  => $duration,
                'called_by' => $caller,
            ];

            return $instance;
        } finally {
            // Always remove the resolution marker, even if an exception is thrown
            unset($this->resolving[$abstract]);
        }
    }

    /**
     * Resolves the dependencies for a given set of reflection parameters.
     * Handles class-typed params via the container, and falls back to default
     * values for primitive/untyped params rather than silently dropping them.
     *
     * @param ReflectionParameter[] $parameters
     * @return array
     * @throws \Exception
     */
    protected function resolveDependencies(array $parameters): array
    {
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type && ! $type->isBuiltin()) {
                // It's a class type — recursively resolve it from the container.
                $dependencies[] = $this->resolve($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                // It's a primitive or untyped param with a default — use it.
                $dependencies[] = $parameter->getDefaultValue();
            } else {
                // No type hint and no default — we cannot resolve this.
                throw new \Exception(
                    "Cannot resolve parameter \${$parameter->getName()} in {$parameter->getDeclaringClass()?->getName()}. " .
                    "Bind it explicitly in the container or provide a default value."
                );
            }
        }
        return $dependencies;
    }

    /**
     * Returns the trace of all resolved services for the current request.
     * Used by the debug toolbar.
     *
     * @return array
     */
    public static function getTrace(): array
    {
        return self::$resolveTrace;
    }

    /**
     * Resets the resolution trace (called at the start of each request).
     */
    public static function resetTrace(): void
    {
        self::$resolveTrace = [];
    }
}
