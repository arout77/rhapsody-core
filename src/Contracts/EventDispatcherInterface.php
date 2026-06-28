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
     */
    public function listen(string $event, $listener): void;

    /**
     * Registers an event subscriber (class with multiple listeners).
     */
    public function subscribe(object $subscriber): void;
}
