<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Contracts\EventDispatcherInterface;
use Rhapsody\Core\Event;
use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * The only way a module can touch the event bus. Unlike the real
 * EventDispatcher, both directions are closed to an explicit whitelist:
 *
 *   - listen()   only for classes under events.listen.listen
 *   - dispatch() only for classes under events.dispatch.dispatch, and
 *                ModuleManifest has already verified at load time that
 *                every one of those lives inside the module's own
 *                namespace — so a module can fire its own events but
 *                can never impersonate a core event (UserRegistered,
 *                RouteNotFound, ...) or another module's.
 */
final class EventsFacade
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ModulePermissions $permissions,
        private readonly string $slug,
    ) {
    }

    /** @param string|callable $listener */
    public function listen(string $eventClass, $listener): void
    {
        if (! $this->permissions->can('events.listen')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to listen for events without declaring \"events.listen\" in module.json"
            );
        }

        if (! in_array($eventClass, $this->permissions->grantedEvents(), true)) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to listen for \"{$eventClass}\", which isn't in its declared " .
                'events.listen.listen whitelist'
            );
        }

        $this->dispatcher->listen($eventClass, $listener);
    }

    /**
     * Fires one of the module's OWN events. Returns the event so the
     * caller can read back anything listeners did to it (e.g. a
     * FormSubmitting event that a spam listener marked as rejected).
     *
     * Listener exceptions are already isolated by EventDispatcher, so a
     * misbehaving third-party listener can't turn a successful action
     * into a fatal error here.
     */
    public function dispatch(Event $event): Event
    {
        if (! $this->permissions->can('events.dispatch')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to dispatch an event without declaring \"events.dispatch\" in module.json"
            );
        }

        $eventClass = get_class($event);

        if (! in_array($eventClass, $this->permissions->dispatchableEvents(), true)) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to dispatch \"{$eventClass}\", which isn't in its declared " .
                'events.dispatch.dispatch whitelist'
            );
        }

        $this->dispatcher->dispatch($event);

        return $event;
    }
}
