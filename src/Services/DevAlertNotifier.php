<?php

namespace Rhapsody\Core\Services;

use Rhapsody\Core\Cache;
use Rhapsody\Core\Mailer;

/**
 * Shared "alert devs, at most once per dedupe key, via whichever of
 * email/webhook is configured" primitive behind NotifyDevsOfModuleFailure
 * and NotifyDevsOfModuleHealthFailure. Pulled out so both listeners share
 * exactly one dedupe/timeout/failure-handling implementation rather than
 * two copies that could quietly drift apart.
 *
 * Never throws — a broken mail server or unreachable webhook must not
 * turn an already-failing module into a fatal error for whatever
 * triggered the alert.
 */
class DevAlertNotifier
{
    private const HTTP_TIMEOUT_S = 2.0; // never let a slow/dead webhook stall the caller

    public function __construct(
        private Mailer $mailer,
        private Cache $cache,
    ) {
    }

    /**
     * @param  string $dedupeKey     Cache key gating repeat alerts.
     * @param  int    $dedupeMinutes How long to stay quiet after a successful send.
     * @return bool   True if an alert went out, or one was already sent within the dedupe window.
     */
    public function notify(string $dedupeKey, int $dedupeMinutes, string $subject, string $emailBodyHtml, string $webhookText): bool
    {
        if ($this->cache->has($dedupeKey)) {
            return true;
        }

        $to         = $_ENV['MODULE_ALERT_EMAIL'] ?? $_ENV['MAIL_ADMIN_EMAIL'] ?? null;
        $webhookUrl = $_ENV['MODULE_ALERT_WEBHOOK_URL'] ?? null;

        if (! $to && ! $webhookUrl) {
            error_log('DevAlertNotifier: neither MODULE_ALERT_EMAIL nor MODULE_ALERT_WEBHOOK_URL is set — cannot alert.');
            return false;
        }

        $sentAny = false;

        if ($to) {
            $sentAny = $this->sendEmail($to, $subject, $emailBodyHtml) || $sentAny;
        }

        if ($webhookUrl) {
            $sentAny = $this->sendWebhook($webhookUrl, $webhookText) || $sentAny;
        }

        // Only start the dedupe window once at least one channel actually
        // went out — if every configured channel failed, try again on the
        // very next occurrence rather than going quiet having alerted no one.
        if ($sentAny) {
            $this->cache->put($dedupeKey, true, $dedupeMinutes);
        }

        return $sentAny;
    }

    private function sendEmail(string $to, string $subject, string $bodyHtml): bool
    {
        try {
            $this->mailer->send($to, $subject, $bodyHtml);
            return true;
        } catch (\Throwable $e) {
            error_log('DevAlertNotifier: failed to send alert email: ' . $e->getMessage());
            return false;
        }
    }

    private function sendWebhook(string $url, string $text): bool
    {
        // Slack/Discord/Teams-compatible generic shape: a top-level "text"
        // field renders as the message body on all three without needing
        // per-service payload formats.
        $payload = json_encode(['text' => $text]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => self::HTTP_TIMEOUT_S,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                error_log("DevAlertNotifier: webhook POST to {$url} failed (no response).");
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('DevAlertNotifier: failed to send alert webhook: ' . $e->getMessage());
            return false;
        }
    }
}
