<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Contracts\EventDispatcherInterface;

class EventDispatcher implements EventDispatcherInterface
{
    protected array $listeners = [];

    public function listen(string $event, $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function subscribe(object $subscriber): void
    {
        // Your existing subscriber logic
    }

    public function dispatch(object $event): object
    {
        // Your existing dispatch logic
        return $event;
    }
}
