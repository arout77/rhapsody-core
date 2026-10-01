<?php
namespace Rhapsody\Core\Services;

use Rhapsody\Core\Contracts\EncrypterInterface;

/**
 * Default encryption driver: AES-256-GCM (authenticated encryption) plus
 * HMAC-SHA256 signing, with independent keys derived from APP_KEY via HKDF.
 *
 * Payload layout (before base64url): version(1) | iv(12) | tag(16) | ciphertext
 *
 * Key rotation: pass retired keys as $previousKeys (APP_PREVIOUS_KEYS in
 * .env). New data is always written with the current key; reads try the
 * current key first, then each previous key.
 */
class Encrypter implements EncrypterInterface
{
    private const CIPHER     = 'aes-256-gcm';
    private const IV_LENGTH  = 12;
    private const TAG_LENGTH = 16;
    private const VERSION    = "\x01";
    private const MIN_KEY    = 32;

    private static ?EncrypterInterface $instance = null;

    /**
     * @var string[] Raw key material, current key first.
     */
    private array $keys;

    /**
     * @var array<string,string> HKDF-derived keys, keyed "index:purpose".
     */
    private array $derived = [];

    /**
     * @param string   $key          Current key (at least 32 bytes; 64 hex chars recommended).
     * @param string[] $previousKeys Retired keys that should still be accepted for reading.
     */
    public function __construct(string $key, array $previousKeys = [])
    {
        $keys = [$key];
        foreach ($previousKeys as $previous) {
            $previous = trim((string) $previous);
            if ($previous !== '') {
                $keys[] = $previous;
            }
        }

        foreach ($keys as $k) {
            if (strlen($k) < self::MIN_KEY) {
                throw new \InvalidArgumentException(
                    'Encryption keys must be at least ' . self::MIN_KEY . ' bytes long. ' .
                    'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
                );
            }
        }

        $this->keys = $keys;
    }

    // ---------------------------------------------------------------------
    // Shared instance (same pattern as Cache::getInstance())
    // ---------------------------------------------------------------------

    /**
     * The shared encrypter. Built lazily from .env on first use, so apps
     * that never encrypt anything pay nothing.
     */
    public static function getInstance(): EncrypterInterface
    {
        return self::$instance ??= self::fromEnv();
    }

    /**
     * Swap the shared instance (custom driver, tests, long-lived workers).
     */
    public static function setInstance(?EncrypterInterface $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Build an encrypter from APP_KEY and (optionally) APP_PREVIOUS_KEYS,
     * a comma-separated list.
     */
    public static function fromEnv(): self
    {
        $key = $_ENV['APP_KEY'] ?? '';
        if ($key === '') {
            throw new \RuntimeException(
                'Encryption requires APP_KEY to be set in your .env file. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        return new self($key, explode(',', $_ENV['APP_PREVIOUS_KEYS'] ?? ''));
    }

    // ---------------------------------------------------------------------
    // Encryption
    // ---------------------------------------------------------------------

    public function encrypt(string $plaintext, string $context = ''): string
    {
        $iv  = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(0, 'encryption'),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_LENGTH
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::b64Encode(self::VERSION . $iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload, string $context = ''): ?string
    {
        $data = self::b64Decode($payload);
        if ($data === null || strlen($data) < 1 + self::IV_LENGTH + self::TAG_LENGTH || $data[0] !== self::VERSION) {
            return null;
        }

        $iv         = substr($data, 1, self::IV_LENGTH);
        $tag        = substr($data, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($data, 1 + self::IV_LENGTH + self::TAG_LENGTH);

        foreach (array_keys($this->keys) as $index) {
            $plain = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $this->key($index, 'encryption'),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $context
            );

            // false means wrong key OR failed GCM authentication (tampering,
            // wrong context). Either way, try the next key, then give up.
            if ($plain !== false) {
                return $plain;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Signing
    // ---------------------------------------------------------------------

    public function sign(string $value, string $context = ''): string
    {
        return $value . '.' . $this->signature($value, $context);
    }

    public function unsign(string $signed, string $context = ''): ?string
    {
        $pos = strrpos($signed, '.');
        if ($pos === false) {
            return null;
        }

        $value = substr($signed, 0, $pos);

        return $this->verifySignature($value, substr($signed, $pos + 1), $context) ? $value : null;
    }

    public function signature(string $data, string $context = ''): string
    {
        return self::b64Encode($this->mac($data, $context, 0));
    }

    public function verifySignature(string $data, string $signature, string $context = ''): bool
    {
        $given = self::b64Decode($signature);
        if ($given === null) {
            return false;
        }

        foreach (array_keys($this->keys) as $index) {
            if (hash_equals($this->mac($data, $context, $index), $given)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * HMAC over a length-prefixed context + data, so ("ab","c") and
     * ("a","bc") can never produce the same MAC input.
     */
    private function mac(string $data, string $context, int $index): string
    {
        return hash_hmac(
            'sha256',
            pack('N', strlen($context)) . $context . $data,
            $this->key($index, 'signing'),
            true
        );
    }

    /**
     * Derive a purpose-specific 32-byte key from the raw key via HKDF, so
     * the encryption key and the signing key are independent.
     */
    private function key(int $index, string $purpose): string
    {
        return $this->derived[$index . ':' . $purpose] ??= hash_hkdf('sha256', $this->keys[$index], 32, 'rhapsody/' . $purpose . '/v1');
    }

    private static function b64Encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64Decode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
