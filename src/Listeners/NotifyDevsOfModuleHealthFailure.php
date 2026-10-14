<?php

namespace Rhapsody\Core\Listeners;

use Rhapsody\Core\Events\ModuleHealthCheckFailed;
use Rhapsody\Core\Services\DevAlertNotifier;

/**
 * Alerts devs when a module's own healthCheck() reports a failing live
 * dependency — Redis unreachable, an API key rejected, etc — as distinct
 * from a boot failure (the module registered itself fine; something it
 * depends on didn't). Only ever fires when ModuleRegistry::checkHealth()
 * runs, which today means only when `module:health` is invoked — so this
 * only helps if that command is actually run on a schedule (cron/CI), not
 * on every web request the way ModuleBootFailed is.
 *
 * Register this against ModuleHealthCheckFailed::class in your app's
 * EventServiceProvider to enable it.
 */
class NotifyDevsOfModuleHealthFailure
{
    private const DEDUP_MINUTES = 1440; // 24 hours

    public function __construct(
        private DevAlertNotifier $notifier,
    ) {
    }

    public function handle(ModuleHealthCheckFailed $event): void
    {
        $manifest = $event->manifest;

        $subject = "Action needed: module \"{$manifest->name}\" health check \"{$event->checkName}\" is failing";

        $body = '<p>The module <code>' . htmlspecialchars($manifest->name, ENT_QUOTES) . '</code> '
            . '(v' . htmlspecialchars($manifest->version, ENT_QUOTES) . ') booted successfully, but its own "'
            . '<strong>' . htmlspecialchars($event->checkName, ENT_QUOTES) . '</strong>" health check is failing:</p>'
            . '<p>' . htmlspecialchars($event->message, ENT_QUOTES) . '</p>'
            . '<p>The module itself is running — something it depends on may not be. Worth checking directly.</p>'
            . '<p>This alert won\'t be sent again for this specific check for ' . (self::DEDUP_MINUTES / 60) . ' hours, '
            . 'or sooner if it passes again in between.</p>';

        $webhookText = sprintf(
            ':warning: Module *%s* (v%s) health check *%s* is failing: %s',
            $manifest->name,
            $manifest->version,
            $event->checkName,
            $event->message,
        );

        $this->notifier->notify(
            ModuleHealthCheckFailed::cacheKeyFor($manifest->slug(), $event->checkName),
            self::DEDUP_MINUTES,
            $subject,
            $body,
            $webhookText,
        );
    }
}
