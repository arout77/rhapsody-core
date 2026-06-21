<?php
namespace Rhapsody\Core\Helpers;

class Recaptcha
{
    /**
     * Generates the HTML script and widget for the Twig template.
     */
    public static function render(): string
    {
        // Pulling directly from the environment variables defined in rhapsody-app/.env
        $siteKey = $_ENV['RECAPTCHA_SITE_KEY'] ?? '';

        if (empty($siteKey)) {
            return '';
        }

        return sprintf(
            '<script src="https://www.google.com/recaptcha/api.js" async defer></script>
             <div class="g-recaptcha" data-sitekey="%s"></div>',
            htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Verifies the submitted token against Google's API.
     */
    public static function verify(string $responseToken, ?string $clientIp = null): bool
    {
        $secretKey = $_ENV['RECAPTCHA_SECRET_KEY'] ?? '';

        if (empty($secretKey) || empty($responseToken)) {
            return false;
        }

        $url  = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret'   => $secretKey,
            'response' => $responseToken,
            'remoteip' => $clientIp,
        ];

        // Using standard stream context so we don't force a Guzzle dependency on the core
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ],
        ];

        $context = stream_context_create($options);
        $result  = file_get_contents($url, false, $context);

        if ($result === false) {
            return false;
        }

        $response = json_decode($result, true);
        return $response['success'] ?? false;
    }
}
