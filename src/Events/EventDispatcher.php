<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Contracts\EventDispatcherInterface;

class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<int, string|callable>> */
    protected array $listeners = [];

    public function __construct(
        protected ContainerInterface $container,
        array $listeners = []
    ) {
        foreach ($listeners as $event => $eventListeners) {
            foreach ((array) $eventListeners as $listener) {
                $this->listen($event, $listener);
            }
        }
    }

    public function listen(string $event, $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function subscribe(object $subscriber): void
    {
        // A subscriber is a class that wires up several listeners at once.
        // Convention: if it exposes a subscribe(EventDispatcherInterface) method,
        // let it register its own listeners on this dispatcher.
        if (method_exists($subscriber, 'subscribe')) {
            $subscriber->subscribe($this);
        }
    }

    public function dispatch(object $event): object
    {
        $eventClass = get_class($event);

        foreach ($this->listeners[$eventClass] ?? [] as $listener) {
            try {
                // A listener can be a container-resolvable class name (with its own
                // dependencies auto-injected, e.g. Mailer, EntityManager) or a plain callable.
                if (is_string($listener)) {
                    $instance = $this->container->resolve($listener);
                    if (method_exists($instance, 'handle')) {
                        $instance->handle($event);
                    } elseif (is_callable($instance)) {
                        $instance($event);
                    }
                } elseif (is_callable($listener)) {
                    $listener($event);
                }
            } catch (\Throwable $e) {
                // A listener failing (e.g. mail server down) shouldn't turn an
                // otherwise-successful action (e.g. a payment that already went
                // through) into a fatal error for the rest of the request.
                error_log(sprintf(
                    'EventDispatcher: listener "%s" for event "%s" threw: %s',
                    is_string($listener) ? $listener : gettype($listener),
                    $eventClass,
                    $e->getMessage()
                ));
            }
        }

        return $event;
    }
}
