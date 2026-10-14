<?php
namespace Rhapsody\Core\Contracts;

/**
 * Contract for Rhapsody's encryption / signing service.
 *
 * Every method takes an optional $context string. The context is
 * cryptographically bound to the output: a payload produced under one
 * context will NOT verify or decrypt under another. Use it to stop a valid
 * value from being replayed somewhere it was never meant to go
 * (e.g. 'cookie:remember_me', 'password-reset', 'url').
 */
interface EncrypterInterface
{
    /**
     * Encrypt (and authenticate) a string. The result is URL/cookie-safe.
     */
    public function encrypt(string $plaintext, string $context = ''): string;

    /**
     * Decrypt a payload from encrypt(). Returns null if the payload is
     * malformed, tampered with, bound to a different context, or was made
     * with a key that is no longer configured.
     */
    public function decrypt(string $payload, string $context = ''): ?string;

    /**
     * Sign a string. The value stays readable: "value.signature".
     */
    public function sign(string $value, string $context = ''): string;

    /**
     * Verify a string from sign(). Returns the original value, or null if
     * the signature is missing, wrong, or bound to a different context.
     */
    public function unsign(string $signed, string $context = ''): ?string;

    /**
     * Compute a detached signature (URL-safe) for arbitrary data.
     */
    public function signature(string $data, string $context = ''): string;

    /**
     * Timing-safe check of a detached signature from signature().
     */
    public function verifySignature(string $data, string $signature, string $context = ''): bool;
}
