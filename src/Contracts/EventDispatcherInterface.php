<?php
namespace Rhapsody\Core\Contracts;

interface EventDispatcherInterface
{
    /**
     * Dispatches an event to all registered listeners.
     */
    public function dispatch(object $event): object;

    /**
     * Registers a listener for a given event.
     *
     * @param string|callable $listener A container-resolvable class name, or a callable.
     */
    public function listen(string $event, string|callable $listener): void;

    /**
     * Registers an event subscriber (class with multiple listeners).
     */
    public function subscribe(object $subscriber): void;
}
