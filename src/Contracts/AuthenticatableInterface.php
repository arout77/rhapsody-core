<?php
namespace Rhapsody\Core\Contracts;

/**
 * A contract for "something that can be authenticated" — i.e. a user
 * model, not a controller. Deliberately narrow: just enough for auth
 * checks (comparing a submitted password against the stored hash,
 * identifying which user is logged in) — not the controller-facing
 * login/register/logout flow, which is Auth\AuthenticatableInterface
 * (implemented by AuthController).
 *
 * This interface previously also declared showLoginForm()/showRegisterForm()/
 * login()/register()/logout() — controller-shaped methods that don't belong
 * on a data model — while being bound in bootstrap.php to App\Models\User::class.
 * No plausible User model implements those, so resolving this interface and
 * calling any of them would fatal. Narrowed to match what's actually bound
 * to it.
 */
interface AuthenticatableInterface
{
    /**
     * A unique identifier for the user (e.g. the primary key).
     */
    public function getAuthIdentifier(): int|string;

    /**
     * The user's hashed password, for verification against a submitted
     * plaintext password (e.g. via password_verify()).
     */
    public function getAuthPassword(): string;
}
