<?php
/**
 * ============================================================================
 * RHAPSODY DEPENDENCY INJECTION CONTAINER
 * ============================================================================
 *
 * This container implements a dependency injection (DI) container that manages
 * class dependencies, auto-wires objects, and resolves dependencies recursively.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ WHAT IT DOES:                                                           │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ • Binds interfaces/abstracts to concrete implementations                │
 * │ • Auto-resolves constructor dependencies via Reflection API             │
 * │ • Detects and prevents circular dependencies                            │
 * │ • Supports closure-based bindings (factory pattern)                     │
 * │ • Stores singleton instances via instance() method                      │
 * │ • Provides resolution tracing for debugging/profiling                   │
 * │ • Tracks resolution duration (milliseconds) per dependency              │
 * │ • Includes proxy mode flag for extended functionality                   │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ KEY CONCEPTS:                                                           │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ BINDING     → Associating an abstract name with concrete                │
 * │               implementation or factory closure                         │
 * │ RESOLVING   → Instantiating a class with all its dependencies           │
 * │ CIRCULAR    → When A depends on B, and B depends on A                   │
 * │ TRACE       → Log of all resolutions with timing and caller info        │
 * │ PROXY MODE  → Flag to mark entries as proxy (e.g., lazy loading)        │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ USAGE EXAMPLES:                                                         │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ $container = new Container();                                           │
 * │                                                                         │
 * │ // Bind interface to implementation                                     │
 * │ $container->bind(LoggerInterface::class, Logger::class);                │
 * │                                                                         │
 * │ // Bind factory closure                                                 │
 * │ $container->bind('db', function() {                                     │
 * │     return new PDO('mysql:host=localhost;dbname=test', 'user', 'pwd');  │
 * │ });                                                                     │
 * │                                                                         │
 * │ // Store singleton instance                                             │
 * │ $container->instance(Config::class, new Config($configArray));          │
 * │                                                                         │
 * │ // Resolve a class (auto-wires dependencies)                            │
 * │ $userService = $container->resolve(UserService::class);                 │
 * │                                                                         │
 * │ // Get resolution trace for debugging                                   │
 * │ $trace = Container::getTrace();                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ EXCEPTIONS:                                                             │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ • Circular dependency: "Circular dependency detected: A → B → A"        │
 * │ • Non-instantiable: "Class X is not instantiable"                       │
 * │ • Unresolvable parameter: "Cannot resolve parameter $x in ClassY"       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ DESIGN PATTERNS USED:                                                   │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ • Service Locator (via get() method)                                    │
 * │ • Dependency Injection (auto-wiring)                                    │
 * │ • Factory Pattern (via closure bindings)                                │
 * │ • Singleton Pattern (via instance() method)                             │
 * │ • Observer/Logging (via trace system)                                   │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ LIMITATIONS:                                                            │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ • No singleton caching (resolves new instance each time)                │
 * │ • No contextual binding (different implementations per context)         │
 * │ • Static trace is shared across all instances                           │
 * │ • No interface autowiring unless explicitly bound                       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * @version    1.0.0
 * ============================================================================
 * @package    Rhapsody\Core
 *
 * @author     Rhapsody Framework
 *
 * @see        ContainerInterface
 * @see        ReflectionClass
 * @see        ReflectionParameter
 */

namespace Rhapsody\Core;

use ReflectionClass;
use ReflectionParameter;
use Rhapsody\Core\Contracts\ContainerInterface;

/**
 * Class Container
 *
 * The dependency injection container that manages class instantiation,
 * dependency resolution, and binding management.
 *
 * @package Rhapsody\Core
 */
class Container implements ContainerInterface
{
    /**
     * @var array<string, callable|string> Binding map: abstract → concrete
     *
     * Stores all bindings where the key is the abstract name (interface/class)
     * and the value is either a concrete class name or a closure that returns
     * an instance.
     */
    protected array $bindings = [];

    /**
     * @var array<string, bool> Currently resolving classes (for circular detection)
     *
     * Tracks which classes are in the process of being resolved to detect
     * circular dependencies. Keys are abstract names, values are boolean flags.
     */
    protected array $resolving = [];

    /**
     * @var array<int, array<string, mixed>> Resolution trace log
     *
     * Each entry contains:
     * - 'class': The abstract/class name resolved
     * - 'resolved': boolean (true if successful)
     * - 'duration': float (milliseconds taken)
     * - 'called_by': string (caller class or 'unknown')
     * - 'proxy': boolean (if proxy mode is enabled)
     * - 'circular': boolean (if circular dependency detected)
     * - 'stack': array (the dependency chain if circular)
     */
    private static array $resolveTrace = [];

    /**
     * @var bool Whether proxy mode is enabled for trace entries
     *
     * When enabled, adds 'proxy' => true to all trace entries.
     * Useful for lazy loading, AOP, or other proxy patterns.
     */
    private static bool $proxyMode = false;

    /**
     * Binds an abstract name to a concrete implementation or factory closure.
     *
     *                                       If null, binds to itself.
     * @example
     * // Bind interface to class
     * $container->bind(LoggerInterface::class, FileLogger::class);
     * // Bind factory closure
     * $container->bind('db', function() {
     *     return new DatabaseConnection($config);
     * });
     * // Self-bind (resolves itself)
     * $container->bind(UserService::class);
     * @param  string               $abstract The abstract name (interface, class name, or alias)
     * @param  callable|string|null $concrete The concrete class name or factory closure.
     * @return void
     */
    public function bind(string $abstract, callable | string | null $concrete = null): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Binds an already-created instance to an abstract name (singleton pattern).
     *
     * The instance is wrapped in a closure so it's returned when resolved.
     *
     * @example
     * $logger = new Logger($config);
     * $container->instance(LoggerInterface::class, $logger);
     * // Subsequent resolutions return the same instance
     * @param  string  $abstract The abstract name to bind to
     * @param  mixed   $instance The instance to store
     * @return void
     */
    public function instance(string $abstract, $instance): void
    {
        $this->bindings[$abstract] = function () use ($instance) {
            return $instance;
        };
    }

    /**
     * Resolves and returns an instance of the given abstract.
     *
     * Alias for resolve().
     *
     * @param  string $id The abstract name to resolve
     * @return mixed  The resolved instance
     */
    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * Checks if the given abstract has been bound.
     *
     * @param  string $abstract The abstract name to check
     * @return bool   True if bound, false otherwise
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    /**
     * Resolves a class with all its dependencies (the core resolution logic).
     *
     * Resolution flow:
     * 1. Check if binding exists and is callable → execute closure with timing
     * 2. Check for circular dependency → throw exception if detected
     * 3. Mark as resolving
     * 4. Use ReflectionClass to inspect the class
     * 5. Resolve constructor dependencies recursively
     * 6. Instantiate the class
     * 7. Log trace entry with timing and caller info
     * 8. Unmark as resolving (in finally block)
     *
     * @example
     * // Resolve a simple class with no dependencies
     * $logger = $container->resolve(Logger::class);
     * // Resolve a class with dependencies (auto-injected)
     * $userService = $container->resolve(UserService::class);
     * // UserService dependencies: LoggerInterface, UserRepositoryInterface
     * // Both are resolved recursively
     * @param  string     $abstract The class/abstract name to resolve
     * @throws \Exception If circular dependency, non-instantiable class, or unresolvable parameter
     * @return mixed      The resolved instance
     */
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

        // --- Mark as resolving and start timing ---
        $this->resolving[$abstract] = true;
        $start                      = microtime(true);

        try {
            // --- Reflection-based instantiation ---
            $reflector = new ReflectionClass($abstract);
            if (! $reflector->isInstantiable()) {
                throw new \Exception("Class {$abstract} is not instantiable.");
            }
            $constructor = $reflector->getConstructor();
            if (is_null($constructor)) {
                // No constructor → instantiate without arguments
                $instance = new $abstract();
            } else {
                // Resolve constructor parameters recursively
                $parameters   = $constructor->getParameters();
                $dependencies = $this->resolveDependencies($parameters);
                $instance     = $reflector->newInstanceArgs($dependencies);
            }

            // --- Log successful resolution ---
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
            // Always unmark as resolving, even if an exception occurs
            unset($this->resolving[$abstract]);
        }
    }

    /**
     * Resolves an array of constructor parameters recursively.
     *
     * For each parameter:
     * - If type-hinted with a class/interface → resolve it recursively
     * - If has a default value → use the default
     * - Otherwise → throw exception (cannot resolve)
     *
     * @example
     * // Given constructor: __construct(Logger $logger, Config $config, $debug = false)
     * // Returns: [Logger instance, Config instance, false]
     * @param  array<ReflectionParameter> $parameters The constructor parameters
     * @throws \Exception                 If a parameter cannot be resolved
     * @return array                      Resolved dependency instances
     */
    protected function resolveDependencies(array $parameters): array
    {
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type && ! $type->isBuiltin()) {
                // Type is a class/interface → resolve it
                $dependencies[] = $this->resolve($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                // Has default value → use it
                $dependencies[] = $parameter->getDefaultValue();
            } else {
                // No type and no default → cannot resolve
                throw new \Exception("Cannot resolve parameter \${$parameter->getName()} in {$parameter->getDeclaringClass()?->getName()}. Bind it explicitly or provide a default.");
            }
        }
        return $dependencies;
    }

    /**
     * Returns the resolution trace log.
     *
     * The trace contains entries for every resolution attempt with:
     * - class name
     * - success/failure status
     * - duration in milliseconds
     * - caller class
     * - proxy flag (if enabled)
     * - circular dependency info (if applicable)
     *
     * @example
     * $trace = Container::getTrace();
     * foreach ($trace as $entry) {
     *     echo "Resolved {$entry['class']} in {$entry['duration']}ms\n";
     * }
     * @return array<int, array<string, mixed>> The resolution trace
     */
    public static function getTrace(): array
    {
        return self::$resolveTrace;
    }

    /**
     * Resets the resolution trace log.
     *
     * @example
     * Container::resetTrace();
     * // All previous trace entries are cleared
     * @return void
     */
    public static function resetTrace(): void
    {
        self::$resolveTrace = [];
    }

    /**
     * Enables or disables proxy mode for trace entries.
     *
     * When enabled, all trace entries include a 'proxy' => true flag.
     * Useful for identifying resolutions that involve lazy loading,
     * AOP proxies, or other proxy patterns.
     *
     * @example
     * Container::setProxyMode(true);
     * // Future trace entries will include 'proxy' => true
     * @param  bool    $enabled True to enable proxy mode, false to disable
     * @return void
     */
    public static function setProxyMode(bool $enabled): void
    {
        self::$proxyMode = $enabled;
    }
}
