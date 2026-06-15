<?php
namespace Rhapsody\Core;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class Mailer
{
    // Make this nullable since it won't be instantiated if the host config is empty
    protected ?SymfonyMailer $mailer = null;
    protected array $mailConfig;

    public function __construct(array $mailConfig = [])
    {
        $this->mailConfig = $mailConfig;

        // Check if the host is empty; if so, skip initialization or use a null transport
        if (empty($mailConfig['host'])) {
            return;
        }

        $dsn          = "{$mailConfig['transport']}://{$mailConfig['username']}:{$mailConfig['password']}@{$mailConfig['host']}:{$mailConfig['port']}";
        $transport    = Transport::fromDsn($dsn);
        $this->mailer = new SymfonyMailer($transport);
    }

    /**
     * Sends an email.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $plainTextBody = null): void
    {
        if (! $this->mailer) {
            throw new \RuntimeException('Mailer not configured. Please set MAIL_HOST in .env');
        }

        // Fixed: Read straight from the correct mailConfig property array saved by the constructor
        $fromAddress = $this->mailConfig['from_address'] ?? 'hello@example.com';
        $fromName    = $this->mailConfig['from_name'] ?? 'Example';

        $email = (new Email())
            ->from("{$fromName} <{$fromAddress}>")
            ->to($to)
            ->subject($subject)
            ->html($htmlBody);

        if ($plainTextBody) {
            $email->text($plainTextBody);
        }

        $this->mailer->send($email);
    }
}
