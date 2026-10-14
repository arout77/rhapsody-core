<?php
namespace Rhapsody\Core;

/**
 * A secure, static wrapper for handling PHP sessions.
 *
 * Provides a consistent and safe interface for managing session data,
 * including user authentication state, flash messages, and CSRF tokens.
 */
class Session
{
    /**
     * Starts the session if it has not already been started.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }

        // 1. Age out old flash data from the PREVIOUS request
        $old = $_SESSION['_flash_old'] ?? [];
        if (is_array($old)) {
            foreach ($old as $key) {
                if (is_string($key)) {
                    unset($_SESSION[$key]);
                }
            }
        }

        // 2. Mark current flashes to be deleted on the NEXT request
        $new                    = $_SESSION['_flash_new'] ?? [];
        $_SESSION['_flash_old'] = is_array($new) ? $new : [];
        $_SESSION['_flash_new'] = [];
    }

    public static function flash(string $key, mixed $message): void
    {
        $pending = $_SESSION['_flash_new'] ?? [];
        $pending = is_array($pending) ? $pending : [];

        $pending[]              = $key;
        $_SESSION[$key]         = $message;
        $_SESSION['_flash_new'] = $pending;
    }

    /**
     * Sets a value in the session.
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Retrieves a value from the session by its key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Retrieves a value and removes it from the session in one step.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    /**
     * Checks if a key exists in the session.
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Removes a value from the session by its key.
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Fully destroys the session: clears session data, expires the cookie,
     * and calls session_destroy(). Without all three steps the client retains
     * a valid cookie and $_SESSION remains populated for the rest of the request.
     */
    public static function destroy(): void
    {
        // 1. Clear the server-side session data immediately.
        $_SESSION = [];

        // 2. Expire the session cookie in the browser.
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            $name   = session_name();
            if ($name !== false) {
                setcookie(
                    $name,
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
        }

        // 3. Destroy the session on the server.
        session_destroy();
    }

    /**
     * Regenerates the session ID to prevent session fixation attacks.
     * Call immediately after a user logs in.
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Writes session data to disk and closes the session.
     * Call before any redirects to ensure data is saved.
     */
    public static function close(): void
    {
        session_write_close();
    }

    /**
     * Retrieves a flash message and then removes it from the session.
     */
    public static function getFlash(string $key, mixed $default = null): ?string
    {
        $message = self::get($key, $default);
        self::remove($key);
        return is_scalar($message) ? (string) $message : null;
    }

    /**
     * Checks if a flash message exists for a given key.
     */
    public static function hasFlash(string $key): bool
    {
        return self::has($key);
    }

    /**
     * Generates and returns a CSRF token, creating one if it doesn't exist.
     */
    public static function csrfToken(): string
    {
        $token = self::get('_token');
        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            self::set('_token', $token);
        }
        return $token;
    }

    /**
     * Verifies a submitted CSRF token against the one stored in the session.
     * Uses hash_equals for timing-attack-safe comparison.
     */
    public static function verifyCsrfToken(string $token): bool
    {
        $stored = self::get('_token');

        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }
}
