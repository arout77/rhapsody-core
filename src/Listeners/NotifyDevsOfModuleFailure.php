<?php

namespace Rhapsody\Core\Listeners;

use Rhapsody\Core\Events\ModuleBootFailed;
use Rhapsody\Core\Services\DevAlertNotifier;

/**
 * Alerts devs the first time a module fails to boot, then stays quiet
 * about that same module for DEDUP_MINUTES so a module that's broken on
 * every request doesn't send one alert per request. ModuleRegistry clears
 * the dedupe key on the module's next successful boot, so fixing it
 * re-arms the alert immediately rather than leaving it dormant for the
 * rest of the window.
 *
 * Register this against ModuleBootFailed::class in your app's
 * EventServiceProvider to enable it — it's not wired up automatically,
 * same as every other core-provided listener (see SendWelcomeEmail).
 */
class NotifyDevsOfModuleFailure
{
    private const DEDUP_MINUTES = 1440; // 24 hours

    public function __construct(
        private DevAlertNotifier $notifier,
    ) {
    }

    public function handle(ModuleBootFailed $event): void
    {
        $manifest = $event->manifest;

        $subject = "Action needed: module \"{$manifest->name}\" failed to boot";

        $body = '<p>The module <code>' . htmlspecialchars($manifest->name, ENT_QUOTES) . '</code> '
            . '(v' . htmlspecialchars($manifest->version, ENT_QUOTES) . ') did not boot on the most recent request.</p>'
            . '<p><strong>Phase:</strong> ' . htmlspecialchars($event->phase, ENT_QUOTES) . '<br>'
            . '<strong>Reason:</strong> ' . htmlspecialchars($event->message, ENT_QUOTES) . '</p>'
            . '<p>Until this is fixed, none of this module\'s functionality is active — check '
            . 'settings.json and the provider class for this module.</p>'
            . '<p>This alert won\'t be sent again for this module for ' . (self::DEDUP_MINUTES / 60) . ' hours, '
            . 'or sooner if it boots successfully in between.</p>';

        $webhookText = sprintf(
            ':rotating_light: Module *%s* (v%s) failed to boot — phase: %s. Reason: %s',
            $manifest->name,
            $manifest->version,
            $event->phase,
            $event->message,
        );

        $this->notifier->notify(
            ModuleBootFailed::cacheKeyFor($manifest->slug()),
            self::DEDUP_MINUTES,
            $subject,
            $body,
            $webhookText,
        );
    }
}
