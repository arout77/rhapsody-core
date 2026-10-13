<?php
namespace Rhapsody\Core\Contracts;

use Rhapsody\Core\Events\Event;

/**
 * Defines the contract for all event listeners.
 * Each listener must implement a `handle` method, which will be called
 * by the dispatcher when the corresponding event is fired.
 */
interface ListenerInterface
{
    /**
     * Handle the event.
     *
     * @param Event $event The event object containing relevant data.
     */
    public function handle(Event $event): void;
}