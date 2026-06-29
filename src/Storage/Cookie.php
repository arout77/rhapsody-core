<?php
namespace Rhapsody\Core\Storage;

enum SameSite: string {
    case Lax    = 'Lax';
    case Strict = 'Strict';
    case None   = 'None';
}

/**
 * Secure cookie manager with encryption, SameSite, and HTTP-only support.
 * All methods are static for simplicity.
 */
final class Cookie
{
    private static string $encryptionKey;

    /**
     * Set the encryption key (must be 32 bytes for AES-256).
     * Called automatically from bootstrap if APP_KEY is set.
     */
    public static function setEncryptionKey(string $key): void
    {
        self::$encryptionKey = $key;
    }

    /**
     * Set a cookie.
     *
     * @param string      $name
     * @param mixed       $value         (will be JSON-encoded if not a string)
     * @param int         $expiry        Lifetime in seconds (0 = session)
     * @param string      $path
     * @param string|null $domain
     * @param bool        $secure        Force HTTPS only
     * @param bool        $httpOnly      Prevent JavaScript access
     * @param SameSite    $sameSite
     */
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
        $payload = self::encrypt($payload);

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
        if (! isset($_COOKIE[$name])) {
            return $default;
        }

        $raw       = $_COOKIE[$name];
        $decrypted = self::decrypt($raw);

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
            $result[$name] = self::get($name);
        }
        return $result;
    }

    /**
     * Encrypt a value using APP_KEY (AES-256-CBC).
     */
    private static function encrypt(string $value): string
    {
        $key       = self::getKey();
        $iv        = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value.
     */
    private static function decrypt(string $payload): string
    {
        $key       = self::getKey();
        $data      = base64_decode($payload);
        $ivLength  = openssl_cipher_iv_length('AES-256-CBC');
        $iv        = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv) ?: '';
    }

    private static function getKey(): string
    {
        if (! isset(self::$encryptionKey)) {
            $key = $_ENV['APP_KEY'] ?? 'default-secret-key-change-me';
            if (strlen($key) < 32) {
                $key = str_pad($key, 32, '0');
            }
            self::$encryptionKey = $key;
        }
        return self::$encryptionKey;
    }
}
