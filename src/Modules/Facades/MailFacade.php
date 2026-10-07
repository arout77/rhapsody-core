<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Mailer;
use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * The only way a module can send email, gated on "mail.send".
 *
 * A module that can send mail can, in the worst case, be used as a spam
 * relay, so this facade is deliberately narrower than Mailer itself:
 *
 *  - The From address and name always come from the site's own mail
 *    configuration. There is no parameter to override them, so a module
 *    can't impersonate anyone else on the site's domain.
 *  - Recipient and Reply-To must be single, syntactically valid addresses
 *    (FILTER_VALIDATE_EMAIL also rejects line breaks, which closes the
 *    classic header-injection route to adding hidden Bcc recipients).
 *  - The subject has line breaks stripped for the same reason.
 *  - At most MAX_PER_REQUEST messages per request. ModuleContext hands out
 *    one cached instance per module, so the cap is shared across every
 *    $context->mail() call the module makes while handling a request.
 *
 * Mail failures (including "mail isn't configured") are thrown as the
 * underlying exceptions. Callers should treat mail as best-effort: do the
 * important work first (e.g. save the submission), then try to send.
 */
final class MailFacade
{
    /** Hard ceiling on messages one module may send while handling a single request. */
    public const MAX_PER_REQUEST = 20;

    private int $sent = 0;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly ModulePermissions $permissions,
        private readonly string $slug,
    ) {
    }

    /**
     * Whether the site has a mail transport configured. Lets a module show
     * a helpful notice instead of discovering the problem via an exception.
     */
    public function isConfigured(): bool
    {
        $this->assertAllowed();

        return $this->mailer->isConfigured();
    }

    /**
     * @throws \InvalidArgumentException for a malformed recipient or Reply-To address
     * @throws ModulePermissionException when the per-request cap is exceeded or "mail.send" wasn't declared
     * @throws \RuntimeException         when the site has no mail transport configured
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $plainTextBody = null,
        ?string $replyTo = null,
    ): void {
        $this->assertAllowed();

        if ($this->sent >= self::MAX_PER_REQUEST) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" exceeded its limit of " . self::MAX_PER_REQUEST . ' emails per request'
            );
        }

        $to = trim($to);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('mail()->send() requires a single, valid recipient email address');
        }

        if ($replyTo !== null && $replyTo !== '') {
            $replyTo = trim($replyTo);
            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('mail()->send() requires a single, valid Reply-To email address');
            }
        } else {
            $replyTo = null;
        }

        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));

        // Count the attempt before sending so a failing transport can't be
        // retried in a loop past the cap.
        $this->sent++;

        $this->mailer->send($to, $subject, $htmlBody, $plainTextBody, $replyTo);
    }

    private function assertAllowed(): void
    {
        if (! $this->permissions->can('mail.send')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to send mail without declaring \"mail.send\" in module.json"
            );
        }
    }
}
