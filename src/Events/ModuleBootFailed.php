<?php

namespace Rhapsody\Core\Events;

use Rhapsody\Core\Event;
use Rhapsody\Core\Modules\ModuleManifest;

/**
 * Dispatched by ModuleRegistry whenever an installed module doesn't
 * actually end up running for a request — a version mismatch, a provider
 * class that couldn't be resolved, a settings_schema validation failure,
 * or boot() itself throwing. ModuleRegistry always error_log()s these too;
 * this event exists so something more visible than a log line (email,
 * webhook, whatever) can also react, without ModuleRegistry needing to
 * know anything about how that notification happens.
 */
final class ModuleBootFailed extends Event
{
    public const PHASE_COMPATIBILITY       = 'compatibility';
    public const PHASE_PROVIDER            = 'provider';
    public const PHASE_SETTINGS_VALIDATION = 'settings_validation';
    public const PHASE_BOOT                = 'boot';

    public function __construct(
        public readonly ModuleManifest $manifest,
        public readonly string $phase,
        public readonly string $message,
    ) {
    }

    /**
     * Cache key used to dedupe/rate-limit alerts for this module. Shared
     * between ModuleRegistry (which clears it on a subsequent successful
     * boot) and any listener that debounces on it, so both sides agree on
     * the same key without either hardcoding the other's format.
     */
    public static function cacheKeyFor(string $slug): string
    {
        return 'module_alert_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $slug);
    }
}
