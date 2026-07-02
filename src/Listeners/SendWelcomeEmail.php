<?php
namespace Rhapsody\Core\Listeners;

use Rhapsody\Core\Events\UserRegistered;
use Rhapsody\Core\Mailer;
use Twig\Environment;

/**
 * Listener that sends a welcome email when a new user registers.
 */
class SendWelcomeEmail
{
    public function __construct(
        private Mailer $mailer,
        private Environment $twig
    ) {
    }

    /**
     * Handle the UserRegistered event.
     *
     * @param  UserRegistered $event
     * @return void
     */
    public function handle(UserRegistered $event): void
    {
        $user  = $event->user;
        $name  = $user->getName();
        $email = $user->getEmail();

        // Render the welcome email template
        $htmlBody = $this->twig->render('emails/welcome.twig', [
            'name'     => $name,
            'email'    => $email,
            'app_url'  => $_ENV['APP_URL'] ?? '',
            'base_url' => ($_ENV['APP_URL'] ?? '') . ($_ENV['APP_BASE_URL'] ?? ''),
        ]);

        $plainTextBody = $this->twig->render('emails/welcome.txt.twig', [
            'name'     => $name,
            'email'    => $email,
            'app_url'  => $_ENV['APP_URL'] ?? '',
            'base_url' => ($_ENV['APP_URL'] ?? '') . ($_ENV['APP_BASE_URL'] ?? ''),
        ]);

        try {
            $this->mailer->send(
                $email,
                'Welcome to ' . ($_ENV['APP_NAME'] ?? 'Rhapsody') . '!',
                $htmlBody,
                $plainTextBody
            );
        } catch (\Exception $e) {
            // Log the error but don't prevent registration
            error_log('Failed to send welcome email to ' . $email . ': ' . $e->getMessage());
        }
    }
}
