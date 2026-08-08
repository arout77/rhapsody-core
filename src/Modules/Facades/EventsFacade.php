<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Contracts\EventDispatcherInterface;
use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * The only way a module can touch the event bus. Unlike the real
 * EventDispatcher, listen() is closed to whatever event classes the
 * module's manifest explicitly whitelisted under events.listen.listen.
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
}
