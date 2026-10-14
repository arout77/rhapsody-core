<?php
namespace Rhapsody\Core\Helpers;

use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use League\Glide\Signatures\SignatureInterface;

/**
 * Signs and verifies /img/{path} URLs (Glide HTTP signatures), so only image
 * variants the application itself generated can be requested.
 *
 * The signing key is IMAGE_SIGN_KEY when set, otherwise a sub-key derived from
 * APP_KEY (the raw APP_KEY is never used directly).
 */
final class ImageSigner
{
    private static ?SignatureInterface $signature = null;

    /**
     * Builds a signed image URL, e.g. ImageSigner::url('rhap.png', ['w' => 300]).
     *
     * @param array<string, string|int|float> $params Glide parameters (w, h, fit, q, fm, ...).
     */
    public static function url(string $path, array $params = []): string
    {
        $path = ltrim($path, '/');

        // Signing ignores any 's' already present, so it's safe to add last.
        $params = self::signature()->addSignature($path, $params);

        $base    = rtrim(self::env('APP_URL') . self::env('APP_BASE_URL'), '/');
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return $base . '/img/' . $encoded . '?' . http_build_query($params);
    }

    /**
     * @param array<string, mixed> $params Every query parameter of the request, including 's'.
     * @throws SignatureException When the signature is missing or invalid.
     */
    public static function validate(string $path, array $params): void
    {
        self::signature()->validateRequest($path, $params);
    }

    private static function signature(): SignatureInterface
    {
        if (self::$signature === null) {
            $key = self::env('IMAGE_SIGN_KEY');

            if ($key === '') {
                $appKey = self::env('APP_KEY');
                if ($appKey === '') {
                    throw new \RuntimeException('Set APP_KEY (or IMAGE_SIGN_KEY) to sign image URLs.');
                }

                $key = hash_hmac('sha256', 'rhapsody.image-signature', $appKey);
            }

            self::$signature = SignatureFactory::create($key);
        }

        return self::$signature;
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}