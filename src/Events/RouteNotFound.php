<?php
namespace Rhapsody\Core\Events;

use Rhapsody\Core\Event;
use Rhapsody\Core\Response;

/**
 * Dispatched by Router immediately before it gives up and throws a 404.
 *
 * This is a StoppableEventInterface event: a listener that resolves the
 * requested URI to something real (e.g. a redirect-manager module matching
 * an old slug against its own redirect table) can call setResponse() to
 * hand back a Response of its own. Router will return that response instead
 * of 404ing, and no further RouteNotFound listeners will run.
 *
 * A listener that finds no match for this URI should simply do nothing and
 * return — the event falls through to the normal 404 behavior unchanged,
 * and the next listener (if any) still gets a turn.
 */
class RouteNotFound extends Event implements StoppableEventInterface
{
    private ?Response $response = null;

    private bool $propagationStopped = false;

    /**
     * @param string $uri    The unmatched request path, as Request::getPath() returned it.
     * @param string $method The HTTP method of the unmatched request.
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $method,
    ) {
    }

    /**
     * Supply a response to short-circuit the 404. Also stops propagation, so
     * call this only once you're sure this listener's response should win.
     */
    public function setResponse(Response $response): void
    {
        $this->response           = $response;
        $this->propagationStopped = true;
    }

    /**
     * Null unless (and until) a listener has called setResponse().
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
