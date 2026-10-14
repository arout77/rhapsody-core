<?php
namespace Rhapsody\Core\Listeners;

use Rhapsody\Core\Events\UserRegistered;
use Rhapsody\Core\Mailer;
use Rhapsody\Core\Support\Env;
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

        $appUrl  = Env::string('APP_URL');
        $context = [
            'name'     => $name,
            'email'    => $email,
            'app_url'  => $appUrl,
            'base_url' => $appUrl . Env::string('APP_BASE_URL'),
        ];

        // Render the welcome email template
        $htmlBody      = $this->twig->render('emails/welcome.twig', $context);
        $plainTextBody = $this->twig->render('emails/welcome.txt.twig', $context);

        try {
            $this->mailer->send(
                $email,
                'Welcome to ' . Env::string('APP_NAME', 'Rhapsody') . '!',
                $htmlBody,
                $plainTextBody
            );
        } catch (\Exception $e) {
            // Log the error but don't prevent registration
            error_log('Failed to send welcome email to ' . $email . ': ' . $e->getMessage());
        }
    }
}
