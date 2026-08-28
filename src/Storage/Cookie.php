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
 *
 * Encryption uses AES-256-GCM (authenticated encryption — provides both
 * confidentiality and tamper-detection in one primitive, unlike plain CBC).
 */
final class Cookie
{
    private static string $encryptionKey;

    /**
     * Explicitly set the raw source key (e.g. from bootstrap.php).
     * The actual AES key is always derived from this via SHA-256 — see
     * getKey() — never used directly as key material.
     */
    public static function setEncryptionKey(string $key): void
    {
        if ($key === '') {
            return;
        }
        self::$encryptionKey = $key;
    }

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

        // Tampered, corrupted, or encrypted under a different/rotated key —
        // deliberately distinct from "the stored value is an empty string",
        // which would decrypt successfully. Treat all of these as "not
        // present" rather than surfacing a confusing empty value.
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
            $result[$name] = self::get($name);
        }
        return $result;
    }

    /**
     * Encrypt a value using APP_KEY (AES-256-GCM).
     */
    private static function encrypt(string $value): string
    {
        $key = self::getKey();
        $iv  = random_bytes(12); // 12 bytes is the recommended/standard GCM IV length
        $tag = '';

        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt cookie value.');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt a value using APP_KEY (AES-256-GCM). Returns null (rather
     * than an empty string) if the payload is malformed, was tampered
     * with, or was encrypted under a different/rotated key — GCM's
     * built-in authentication tag makes tampering detectable, unlike
     * plain CBC.
     */
    private static function decrypt(string $payload): ?string
    {
        $key  = self::getKey();
        $data = base64_decode($payload, true);
        if ($data === false || strlen($data) < 12 + 16) {
            return null;
        }

        $iv        = substr($data, 0, 12);
        $tag       = substr($data, 12, 16);
        $encrypted = substr($data, 28);

        $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        // openssl_decrypt() returns false both on a wrong key AND on a
        // failed GCM authentication check (i.e. the ciphertext was
        // tampered with) — either way, there is no usable value.
        return $decrypted === false ? null : $decrypted;
    }

    /**
     * Derives the actual AES-256 key from APP_KEY (or an explicitly set
     * source key) via SHA-256 — this both normalizes it to exactly 32
     * bytes and avoids ever using raw secret material directly as key
     * bytes. Throws if no key is configured at all, rather than silently
     * falling back to a fixed default that's visible in the framework's
     * own public source — a fallback like that provides no real security
     * for any deployment that forgets to set APP_KEY.
     */
    private static function getKey(): string
    {
        if (! isset(self::$encryptionKey) || self::$encryptionKey === '') {
            $key = $_ENV['APP_KEY'] ?? '';
            if ($key === '') {
                throw new \RuntimeException(
                    'Cookie encryption requires APP_KEY to be set in your .env file. ' .
                    'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
                );
            }
            self::$encryptionKey = $key;
        }

        return hash('sha256', self::$encryptionKey, true);
    }
}
