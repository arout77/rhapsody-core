<?php

namespace Rhapsody\Core\Modules\Contracts;

use Rhapsody\Core\Modules\ModuleContext;

/**
 * Optional. A module's provider class can additionally implement this to
 * report on whether its actual runtime dependencies — a Redis connection,
 * an external API key, a writable path, whatever it needs to really work —
 * are functioning. This is deliberately separate from boot(): boot() only
 * proves the module registered itself without throwing, which says nothing
 * about whether the things it depends on are reachable right now. A rate
 * limiter or DDoS module can boot cleanly and still be silently
 * non-functional if its backing store is down; healthCheck() is how
 * `module:health` finds that out instead of a real attack silently sailing
 * through later.
 *
 * Not added to ModuleServiceProviderInterface itself — a required method
 * there would break every already-published module that doesn't implement
 * it. Modules that have nothing worth checking simply don't implement this;
 * `module:health` skips them without complaint.
 */
interface ModuleHealthCheckInterface
{
    /**
     * Run this module's own health checks. Only ever called from
     * `module:health` (or similar tooling) — outside the request path — so
     * it's fine for this to make real network calls it wouldn't want to
     * make on every page load.
     *
     * @return array<string, array{ok: bool, message: string}> Keyed by a
     *         short check name (e.g. "redis", "api_key") so one module can
     *         report several independent checks. Return an empty array if,
     *         at the moment, there's nothing meaningful to check.
     */
    public function healthCheck(ModuleContext $context): array;
}
