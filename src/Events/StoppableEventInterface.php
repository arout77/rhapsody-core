<?php
namespace Rhapsody\Core\Events;

/**
 * Opt-in marker for events where a listener's action should be able to
 * feed back into what the dispatching code does next, and where remaining
 * listeners should stop running once that happens.
 *
 * This is the exception, not the rule: plain events like UserRegistered do
 * NOT implement this interface, and EventDispatcher::dispatch() behaves for
 * them exactly as it always has — fire every listener, discard the result.
 * Only an event that explicitly implements this interface and returns true
 * from isPropagationStopped() changes that behavior, and only for itself.
 */
interface StoppableEventInterface
{
    /**
     * Whether a listener has already handled this event and later listeners
     * should be skipped.
     */
    public function isPropagationStopped(): bool;
}
