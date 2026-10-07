<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Contracts\EventDispatcherInterface;

class EventDispatcher implements EventDispatcherInterface
{
    /**
     * How many dispatch() calls may be nested inside one another before we
     * stop. Modules can now dispatch their own events, so listener A could
     * fire an event that listener B hears, whose listener fires one A hears
     * again. Real chains are 1-2 deep; this is only a backstop.
     */
    protected const MAX_DISPATCH_DEPTH = 8;

    /** @var array<string, array<int, string|callable>> */
    protected array $listeners = [];

    /** Current nesting level of dispatch() calls. */
    protected int $depth = 0;

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

        if ($this->depth >= self::MAX_DISPATCH_DEPTH) {
            error_log(sprintf(
                'EventDispatcher: dispatch of "%s" skipped — nesting deeper than %d (possible listener loop)',
                $eventClass,
                self::MAX_DISPATCH_DEPTH
            ));

            return $event;
        }

        // An event that is already stopped (see StoppableEventInterface)
        // shouldn't reach any listener at all.
        if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
            return $event;
        }

        $this->depth++;

        try {
            $this->notifyListeners($event, $eventClass);
        } finally {
            $this->depth--;
        }

        return $event;
    }

    protected function notifyListeners(object $event, string $eventClass): void
    {
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

            // Opt-in stop-propagation: only events implementing
            // StoppableEventInterface are ever affected; plain events keep
            // the "every listener runs" behavior.
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }
    }
}
