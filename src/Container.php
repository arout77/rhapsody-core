<?php
namespace Rhapsody\Core;

use ReflectionClass;
use ReflectionParameter;
use Rhapsody\Core\Contracts\ContainerInterface;

class Container implements ContainerInterface
{
    protected array $bindings          = [];
    protected array $resolving         = [];
    private static array $resolveTrace = [];
    private static bool $proxyMode     = false;

    public function bind(string $abstract, callable | string | null $concrete = null): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        $this->bindings[$abstract] = $concrete;
    }

    public function instance(string $abstract, $instance): void
    {
        $this->bindings[$abstract] = function () use ($instance) {
            return $instance;
        };
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    public function resolve(string $abstract): mixed
    {
        // --- Handle closure bindings with trace ---
        if (isset($this->bindings[$abstract]) && is_callable($this->bindings[$abstract])) {
            $start    = microtime(true);
            $result   = call_user_func($this->bindings[$abstract], $this);
            $duration = round((microtime(true) - $start) * 1000, 2);
            $trace    = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller   = isset($trace[1]['class']) ? $trace[1]['class'] : 'unknown';
            $entry    = [
                'class'     => $abstract,
                'resolved'  => true,
                'duration'  => $duration,
                'called_by' => $caller,
            ];
            if (self::$proxyMode) {
                $entry['proxy'] = true;
            }
            self::$resolveTrace[] = $entry;
            return $result;
        }

        // --- Circular dependency detection ---
        if (isset($this->resolving[$abstract])) {
            $chain                = implode(' → ', array_keys($this->resolving)) . ' → ' . $abstract;
            self::$resolveTrace[] = [
                'class'    => $abstract,
                'resolved' => false,
                'circular' => true,
                'stack'    => $this->resolving,
            ];
            throw new \Exception("Circular dependency detected: " . $chain);
        }

        $this->resolving[$abstract] = true;
        $start                      = microtime(true);

        try {
            $reflector = new ReflectionClass($abstract);
            if (! $reflector->isInstantiable()) {
                throw new \Exception("Class {$abstract} is not instantiable.");
            }
            $constructor = $reflector->getConstructor();
            if (is_null($constructor)) {
                $instance = new $abstract();
            } else {
                $parameters   = $constructor->getParameters();
                $dependencies = $this->resolveDependencies($parameters);
                $instance     = $reflector->newInstanceArgs($dependencies);
            }

            $duration = round((microtime(true) - $start) * 1000, 2);
            $trace    = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller   = isset($trace[1]['class']) ? $trace[1]['class'] : 'unknown';
            $entry    = [
                'class'     => $abstract,
                'resolved'  => true,
                'duration'  => $duration,
                'called_by' => $caller,
            ];
            if (self::$proxyMode) {
                $entry['proxy'] = true;
            }
            self::$resolveTrace[] = $entry;

            return $instance;
        } finally {
            unset($this->resolving[$abstract]);
        }
    }

    protected function resolveDependencies(array $parameters): array
    {
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type && ! $type->isBuiltin()) {
                $dependencies[] = $this->resolve($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } else {
                throw new \Exception("Cannot resolve parameter \${$parameter->getName()} in {$parameter->getDeclaringClass()?->getName()}. Bind it explicitly or provide a default.");
            }
        }
        return $dependencies;
    }

    public static function getTrace(): array
    {
        return self::$resolveTrace;
    }

    public static function resetTrace(): void
    {
        self::$resolveTrace = [];
    }

    public static function setProxyMode(bool $enabled): void
    {
        self::$proxyMode = $enabled;
    }
}
