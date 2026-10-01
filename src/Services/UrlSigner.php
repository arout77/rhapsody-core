<?php
namespace Rhapsody\Core\Services;

use Rhapsody\Core\Contracts\EncrypterInterface;

/**
 * Tamper-proof URLs (email verification, unsubscribe links, temporary
 * downloads). The URL stays readable; a `signature` query parameter proves
 * it was issued by this application and hasn't been altered. An optional
 * `expires` parameter (covered by the signature) makes the link temporary.
 *
 * Sign and verify the same form of URL: a relative URL signed as
 * "/verify?id=5" will not verify if later checked as "https://host/verify?id=5".
 */
class UrlSigner
{
    private const CONTEXT = 'url';

    public function __construct(private EncrypterInterface $encrypter)
    {}

    /**
     * Return the URL with a signature (and, if $ttlSeconds is given, an
     * expiry timestamp) appended to its query string.
     */
    public function sign(string $url, ?int $ttlSeconds = null): string
    {
        [$base, $query] = $this->split($url);

        unset($query['signature'], $query['expires']);
        if ($ttlSeconds !== null) {
            $query['expires'] = time() + $ttlSeconds;
        }

        $query['signature'] = $this->encrypter->signature($this->canonical($base, $query), self::CONTEXT);

        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * True if the signature is valid and the link hasn't expired.
     */
    public function verify(string $url): bool
    {
        [$base, $query] = $this->split($url);

        $signature = $query['signature'] ?? null;
        unset($query['signature']);

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        if (! $this->encrypter->verifySignature($this->canonical($base, $query), $signature, self::CONTEXT)) {
            return false;
        }

        if (isset($query['expires']) && (! ctype_digit((string) $query['expires']) || (int) $query['expires'] < time())) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0:string,1:array} URL without its query string, and the parsed query.
     */
    private function split(string $url): array
    {
        $url   = explode('#', $url, 2)[0];
        $parts = explode('?', $url, 2);
        $query = [];
        parse_str($parts[1] ?? '', $query);

        return [$parts[0], $query];
    }

    private function canonical(string $base, array $query): string
    {
        ksort($query);

        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
