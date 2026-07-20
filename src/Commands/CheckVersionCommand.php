<?php
namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Cache;
use Rhapsody\Core\Mailer;
use Rhapsody\Core\Services\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually (or via cron) check Packagist for a newer release of
 * arout/rhapsody-core — the same check NotificationService performs
 * automatically for the dev-mode web banner, so both always agree.
 *
 * In production, also emails MAIL_ADMIN_EMAIL once per new version found
 * (deduped via cache) — production apps typically don't run in a browser
 * session where the web banner would ever be seen, so this is how a
 * production deployment gets alerted.
 */
class CheckVersionCommand extends Command
{
    protected static $defaultName = 'app:check-version';

    public function __construct(
        protected array $config,
        protected Mailer $mailer,
        protected Cache $cache,
        protected NotificationService $notifications
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:check-version')
            ->setDescription('Checks Packagist for a newer release of arout/rhapsody-core.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $update = $this->notifications->getAvailableUpdate();

        if (! $update) {
            $output->writeln('<info>You are running the latest version of Rhapsody.</info>');
            return Command::SUCCESS;
        }

        $output->writeln("<comment>Current version:</comment> {$update['current']}");
        $output->writeln("<info>A new version ({$update['latest']}) is available!</info>");
        $output->writeln("<comment>See:</comment> {$update['url']}");

        if (($this->config['app_env'] ?? 'production') === 'production') {
            $this->notifyByEmail($update, $output);
        }

        return Command::SUCCESS;
    }

    private function notifyByEmail(array $update, OutputInterface $output): void
    {
        $to = $_ENV['MAIL_ADMIN_EMAIL'] ?? null;
        if (! $to) {
            $output->writeln('<comment>MAIL_ADMIN_EMAIL is not set in your .env file — skipping email notification.</comment>');
            return;
        }

        // Only send one email per version, no matter how often this command runs.
        $notificationCacheKey = 'rhapsody_update_emailed_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $update['latest']);
        if ($this->cache->has($notificationCacheKey)) {
            $output->writeln("<comment>An email notification for {$update['latest']} has already been sent. Skipping.</comment>");
            return;
        }

        try {
            $subject  = "New Rhapsody Version Available: {$update['latest']}";
            $htmlBody = "<p>A new version of Rhapsody is available: <strong>{$update['latest']}</strong> "
                . "(currently running {$update['current']}).</p>"
                . "<p>View it on Packagist: <a href=\"{$update['url']}\">{$update['url']}</a></p>";

            $this->mailer->send($to, $subject, $htmlBody);
            $this->cache->put($notificationCacheKey, true, 43200); // 30 days
            $output->writeln("<info>Email notification sent successfully to {$to}.</info>");
        } catch (\Throwable $e) {
            error_log('CheckVersionCommand Mailer Error: ' . $e->getMessage());
            $output->writeln("<e>Failed to send email. Check your server's error log for details.</e>");
        }
    }
}
