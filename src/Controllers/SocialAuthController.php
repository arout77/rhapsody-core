<?php
namespace Rhapsody\Core\Controllers;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Entities\User;
use Rhapsody\Core\Events\EventDispatcher;
use Rhapsody\Core\Events\UserRegistered;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Session;
use Twig\Environment;

class SocialAuthController extends BaseController
{
    /** Matches the length of the users.name column. */
    private const NAME_MAX_LENGTH = 50;

    public function __construct(
        protected EntityManager $em,
        protected EventDispatcher $dispatcher,
        Environment $twig
    ) {
        parent::__construct($twig);
    }

    /**
     * Redirect the user to the OAuth provider.
     */
    public function redirectToProvider(Request $request, string $provider): Response
    {
        try {
            $providerInstance = $this->getProvider($provider);
        } catch (\InvalidArgumentException) {
            return new Response('Unsupported login provider.', 404);
        }

        $authorizationUrl = $providerInstance->getAuthorizationUrl();
        Session::set('oauth2_state', $providerInstance->getState());

        // External URL, so build the redirect directly instead of via redirect().
        return new Response('', 302, ['Location' => $authorizationUrl]);
    }

    /**
     * Handle the callback from the OAuth provider.
     */
    public function handleProviderCallback(Request $request, string $provider): Response
    {
        try {
            $providerInstance = $this->getProvider($provider);
        } catch (\InvalidArgumentException) {
            return new Response('Unsupported login provider.', 404);
        }

        // The state is single-use: pull() removes it so it cannot be replayed.
        $expectedState = Session::pull('oauth2_state');
        $state         = $request->get('state');

        if (! is_string($state) || $state === '' || ! is_string($expectedState) || ! hash_equals($expectedState, $state)) {
            return $this->fail('Invalid OAuth state.');
        }

        $code = $request->get('code');
        if (! is_string($code) || $code === '') {
            return $this->fail('Authentication failed. Please try again.');
        }

        try {
            $accessToken = $providerInstance->getAccessToken('authorization_code', ['code' => $code]);
            if (! $accessToken instanceof AccessToken) {
                throw new \UnexpectedValueException('Provider returned an unsupported access token type.');
            }
            $userData = $providerInstance->getResourceOwner($accessToken)->toArray();
        } catch (\Throwable $e) {
            error_log('Social login failed (' . $provider . '): ' . $e->getMessage());

            return $this->fail('Authentication failed. Please try again.');
        }

        $email = $userData['email'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Could not retrieve email from ' . ucfirst(strtolower($provider)) . '.');
        }

        // Matching an existing account by email is only safe if the provider
        // vouches for that address. Providers that report the flag must say true.
        if (array_key_exists('email_verified', $userData) && $userData['email_verified'] !== true) {
            return $this->fail('Your email address has not been verified with ' . ucfirst(strtolower($provider)) . '.');
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (! $user instanceof User) {
            $user = new User();
            $user->setName($this->displayName($userData, $email));
            $user->setEmail($email);
            // Social accounts get a random password; they sign in via the provider.
            $user->setPassword(bin2hex(random_bytes(16)));

            try {
                $this->em->persist($user);
                $this->em->flush();
            } catch (UniqueConstraintViolationException $e) {
                // Another request registered this email between our lookup and flush().
                error_log('Social registration race condition caught: ' . $e->getMessage());

                return $this->fail('Could not complete sign-in. Please try again.');
            }

            $this->dispatcher->dispatch(new UserRegistered($user));
        }

        Session::regenerate();
        Session::set('user_id', $user->getUserId());
        Session::set('user_name', $user->getName());
        Session::set('user_email', $user->getEmail());

        // Only follow local paths, never an external or protocol-relative URL.
        $intended = Session::pull('url.intended');
        $target   = (is_string($intended)
            && str_starts_with($intended, '/')
            && ! str_starts_with($intended, '//')
            && ! str_starts_with($intended, '/\\'))
            ? $intended
            : '/dashboard';

        return redirect($target);
    }

    private function fail(string $message): Response
    {
        return redirect('/login')->with('error', $message);
    }

    /**
     * @param array<string, mixed> $userData
     */
    private function displayName(array $userData, string $email): string
    {
        foreach (['name', 'displayName'] as $key) {
            $value = $userData[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, self::NAME_MAX_LENGTH);
            }
        }

        return mb_substr(explode('@', $email)[0], 0, self::NAME_MAX_LENGTH);
    }

    /**
     * Instantiate the correct provider.
     *
     * @throws \InvalidArgumentException For an unsupported provider.
     */
    private function getProvider(string $provider): AbstractProvider
    {
        return match (strtolower($provider)) {
            'google'   => new Google([
                'clientId'     => $this->env('GOOGLE_CLIENT_ID'),
                'clientSecret' => $this->env('GOOGLE_CLIENT_SECRET'),
                'redirectUri'  => $this->env('GOOGLE_REDIRECT_URI'),
            ]),
            'facebook' => new Facebook([
                'clientId'        => $this->env('FACEBOOK_APP_ID'),
                'clientSecret'    => $this->env('FACEBOOK_APP_SECRET'),
                'redirectUri'     => $this->env('FACEBOOK_REDIRECT_URI'),
                'graphApiVersion' => 'v18.0',
            ]),
            default    => throw new \InvalidArgumentException("Unsupported provider: {$provider}"),
        };
    }

    /**
     * @throws \RuntimeException If the variable is missing or empty.
     */
    private function env(string $key): string
    {
        $value = $_ENV[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }

        return $value;
    }
}