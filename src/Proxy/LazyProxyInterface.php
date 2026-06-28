<?php
namespace Rhapsody\Core\Proxy;

/**
 * Minimal interface for lazy‑loading proxies.
 */
interface LazyProxyInterface
{
    /**
     * Returns the real wrapped object, initializing it if necessary.
     */
    public function getWrappedObject(): ?object;

    /**
     * Checks whether the real object has been initialized.
     */
    public function isProxyInitialized(): bool;

    /**
     * Sets the initializer closure.
     */
    public function setInitializer( ? \Closure $initializer) : void;

    /**
     * Returns the current initializer closure.
     */
    public function getInitializer(): ?\Closure;
}
