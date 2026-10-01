<?php
namespace Rhapsody\Core\Storage;

use Rhapsody\Core\Services\Encrypter;

enum SameSite: string {
    case Lax    = 'Lax';
    case Strict = 'Strict';
    case None   = 'None';
}

/**
 * Secure cookie manager with encryption, SameSite, and HTTP-only support.
 * All methods are static for simplicity.
 *
 * Values are encrypted by the shared Encrypter service (AES-256-GCM). Each
 * ciphertext is bound to its cookie name, so a valid value copied from one
 * cookie cannot be replayed under another.
 */
final class Cookie
{
    public static function set(
        string $name,
        mixed $value,
        int $expiry = 3600,
        string $path = '/',
        ?string $domain = null,
        bool $secure = true,
        bool $httpOnly = true,
        SameSite $sameSite = SameSite::Lax,
    ): bool {
        $payload = is_string($value) ? $value : json_encode($value);
        $payload = Encrypter::getInstance()->encrypt($payload, self::context($name));

        return setcookie(
            $name,
            $payload,
            [
                'expires'  => $expiry > 0 ? time() + $expiry : 0,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => $httpOnly,
                'samesite' => $sameSite->value,
            ]
        );
    }

    /**
     * Get a cookie value.
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        // A client can send "name[]=x", which PHP turns into an array.
        if (! isset($_COOKIE[$name]) || ! is_string($_COOKIE[$name])) {
            return $default;
        }

        $decrypted = Encrypter::getInstance()->decrypt($_COOKIE[$name], self::context($name));

        // Tampered, corrupted, copied from another cookie, or encrypted under
        // a key that is no longer configured. Deliberately distinct from an
        // empty string, which decrypts successfully. Treated as "not present".
        if ($decrypted === null) {
            return $default;
        }

        // Try JSON decode
        $decoded = json_decode($decrypted, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $decrypted;
    }

    /**
     * Check if a cookie exists.
     */
    public static function has(string $name): bool
    {
        return isset($_COOKIE[$name]);
    }

    /**
     * Delete a cookie.
     */
    public static function delete(string $name, string $path = '/', ?string $domain = null): bool
    {
        unset($_COOKIE[$name]);
        return setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Retrieve all cookies (decrypted and decoded).
     */
    public static function all(): array
    {
        $result = [];
        foreach ($_COOKIE as $name => $raw) {
            $result[$name] = self::get((string) $name);
        }
        return $result;
    }

    /**
     * The encryption context for a cookie: its name. Binding the name into
     * the authentication tag means a ciphertext only decrypts under the
     * cookie it was issued for.
     */
    private static function context(string $name): string
    {
        return 'cookie:' . $name;
    }
}
