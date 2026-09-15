<?php

namespace Rhapsody\Core\Events;

use Rhapsody\Core\Event;
use Rhapsody\Core\Modules\ModuleManifest;

/**
 * Dispatched by ModuleRegistry::checkHealth() when a module that DID boot
 * successfully reports a failing check from its own healthCheck().
 * Deliberately a separate event from ModuleBootFailed: the module itself
 * is running fine here — this is about something it depends on at
 * runtime (a connection, an API key, a writable path) rather than the
 * module's own code.
 */
final class ModuleHealthCheckFailed extends Event
{
    public function __construct(
        public readonly ModuleManifest $manifest,
        public readonly string $checkName,
        public readonly string $message,
    ) {
    }

    /**
     * Deliberately its own cache-key namespace, separate from
     * ModuleBootFailed::cacheKeyFor(). ModuleRegistry clears a boot-failure
     * alert on the module's next successful *boot* — which happens on
     * basically every request regardless of whether a given health check
     * is passing — so sharing that key would clear a health-check alert
     * within seconds of it firing. Health-check alerts are cleared only
     * when that specific check passes again (see checkHealth()).
     */
    public static function cacheKeyFor(string $slug, string $checkName): string
    {
        $slug      = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $slug);
        $checkName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $checkName);

        return "module_health_alert_{$slug}_{$checkName}";
    }
}
