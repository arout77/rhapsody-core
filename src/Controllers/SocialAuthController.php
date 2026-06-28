<?php
namespace Rhapsody\Core\Controllers;

use App\Models\User;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Session;

class SocialAuthController extends BaseController
{
    /**
     * Redirect the user to the OAuth provider.
     *
     * @param Request $request
     * @param string $provider
     * @return Response
     */
    public function redirectToProvider(Request $request, string $provider): Response
    {
        $providerInstance = $this->getProvider($provider);
        $authorizationUrl = $providerInstance->getAuthorizationUrl();

        Session::set('oauth2_state', $providerInstance->getState());

        // Send the redirect headers immediately and exit
        header('Location: ' . $authorizationUrl);
        exit;

        // This line is never reached, but satisfies the return type
        return new Response();
    }

    /**
     * Handle the callback from the OAuth provider.
     *
     * @param Request $request
     * @param string $provider
     * @return Response
     */
    public function handleProviderCallback(Request $request, string $provider): Response
    {
        $providerInstance = $this->getProvider($provider);

        // Verify state
        $state = $request->get('state');
        if (empty($state) || $state !== Session::get('oauth2_state')) {
            Session::setFlash('error', 'Invalid OAuth state');
            return new Response('', 302, ['Location' => '/login']);
        }

        try {
            $accessToken = $providerInstance->getAccessToken('authorization_code', [
                'code' => $request->get('code'),
            ]);

            $providerUser = $providerInstance->getResourceOwner($accessToken);
            $userData     = $providerUser->toArray();

            // Provider-specific fields
            $email = $userData['email'] ?? null;
            if (! $email) {
                Session::setFlash('error', 'Could not retrieve email from ' . ucfirst($provider));
                return new Response('', 302, ['Location' => '/login']);
            }

            // Find or create user
            $user = User::where('email', $email)->first();

            if (! $user) {
                // Register a new user
                $user           = new User();
                $user->email    = $email;
                $user->name     = $userData['name'] ?? $userData['displayName'] ?? explode('@', $email)[0];
                $user->password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $user->save();

                // Optionally, dispatch a UserRegistered event
                // $this->dispatcher->dispatch(new UserRegistered($user));
            }

            // Log the user in
            Session::set('user_id', $user->id);

            // Redirect to intended page or dashboard
            $intended = Session::pull('url.intended', '/dashboard');
            return new Response('', 302, ['Location' => $intended]);

        } catch (\Exception $e) {
            Session::setFlash('error', 'Authentication failed: ' . $e->getMessage());
            return new Response('', 302, ['Location' => '/login']);
        }
    }

    /**
     * Instantiate the correct provider.
     */
    private function getProvider(string $provider)
    {
        $provider = strtolower($provider);

        return match ($provider) {
            'google'   => new Google([
                'clientId'     => $_ENV['GOOGLE_CLIENT_ID'],
                'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
                'redirectUri'  => $_ENV['GOOGLE_REDIRECT_URI'],
            ]),
            'facebook' => new Facebook([
                'clientId'        => $_ENV['FACEBOOK_APP_ID'],
                'clientSecret'    => $_ENV['FACEBOOK_APP_SECRET'],
                'redirectUri'     => $_ENV['FACEBOOK_REDIRECT_URI'],
                'graphApiVersion' => 'v18.0',
            ]),
            default    => throw new \InvalidArgumentException("Unsupported provider: {$provider}"),
        };
    }
}
