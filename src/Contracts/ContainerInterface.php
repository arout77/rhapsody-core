<?php
namespace Rhapsody\Core\Contracts;

/**
 * Interface for the dependency injection container.
 *
 * This matches the public API of Rhapsody\Core\Container.
 */
interface ContainerInterface
{
    /**
     * Binds an abstract to a concrete implementation or closure.
     *
     * @param string               $abstract
     * @param callable|string|null $concrete
     */
    public function bind(string $abstract, callable | string | null $concrete = null): void;

    /**
     * Stores a shared instance (singleton) in the container.
     *
     * @param string $abstract
     * @param mixed  $instance
     */
    public function instance(string $abstract, $instance): void;

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param  string       $id
     * @throws \Exception
     * @return mixed
     */
    public function get(string $id): mixed;

    /**
     * Checks if a binding exists in the container.
     *
     * @param  string $abstract
     * @return bool
     */
    public function has(string $abstract): bool;

    /**
     * Resolves a class from the container, automatically injecting dependencies.
     *
     * @param  string       $abstract
     * @throws \Exception
     * @return mixed
     */
    public function resolve(string $abstract): mixed;
}
