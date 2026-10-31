<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Contracts;

/**
 * A unit of work that runs on the persistent queue.
 *
 * This is a marker: PHP cannot declare a `handle()` whose parameters are
 * injected, so every job simply defines its own public method:
 *
 *     public function handle(Mailer $mailer): void
 *
 * The worker calls it through `Container::call()`, so class-typed parameters
 * are resolved from the container. Constructor arguments are what you
 * dispatch with; keep them small and serialisable (ids, strings, numbers),
 * never closures or open resources.
 *
 * Tune retries with the #[Tries], #[Backoff] and #[Timeout] attributes
 * (see Rhapsody\Core\Queue\Attributes).
 */
interface JobInterface
{
}
