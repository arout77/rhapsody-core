<?php
namespace Rhapsody\Core;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class Mailer
{
    /**
     * The underlying Symfony Mailer instance or null if unconfigured.
     */
    protected ?SymfonyMailer $mailer = null;

    /**
     * @var array<string, mixed>
     */
    protected array $mailConfig;

    /**
     * @param array<string, mixed> $mailConfig
     */
    public function __construct(array $mailConfig = [])
    {
        $this->mailConfig = $mailConfig;

        // Check if the host is empty; if so, skip initialization
        if (empty($mailConfig['host'])) {
            return;
        }

        $transportStr = $mailConfig['transport'] ?? 'smtp';
        $username     = $mailConfig['username'] ?? '';
        $password     = $mailConfig['password'] ?? '';
        $host         = $mailConfig['host'];
        $port         = $mailConfig['port'] ?? 25;

        $dsn          = "{$transportStr}://{$username}:{$password}@{$host}:{$port}";
        $transport    = Transport::fromDsn($dsn);
        $this->mailer = new SymfonyMailer($transport);
    }

    /**
     * Sends an email.
     * * @param string $to
     * @param string $subject
     * @param string $htmlBody
     * @param string|null $plainTextBody
     * @throws \RuntimeException
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $plainTextBody = null): void
    {
        if (! $this->mailer) {
            throw new \RuntimeException('Mailer not configured. Please set MAIL_HOST in .env');
        }

        $fromAddress = (string) ($this->mailConfig['from_address'] ?? 'hello@example.com');
        $fromName    = (string) ($this->mailConfig['from_name'] ?? 'Example');

        $email = (new Email())
            ->from("{$fromName} <{$fromAddress}>")
            ->to($to)
            ->subject($subject)
            ->html($htmlBody);

        if ($plainTextBody !== null) {
            $email->text($plainTextBody);
        }

        $this->mailer->send($email);
    }
}
