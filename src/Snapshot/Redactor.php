<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

/**
 * Masks secrets before anything is stored, displayed or (later) sent anywhere.
 *
 * Two layers, because neither alone is enough:
 *  - KEY based: any array key that looks sensitive has its whole value replaced.
 *  - VALUE based: free text (bodies, SQL, log lines, exception messages) is
 *    scrubbed for bearer tokens, JWTs, URL credentials, well-known API key
 *    shapes and `password=...` style pairs.
 *
 * Positional SQL parameters carry no key, so redactQueryParams() masks the whole
 * parameter list when the statement itself touches a sensitive column or table.
 * That is deliberately conservative: a debug snapshot that hides a harmless
 * parameter is fine, one that leaks a password hash is not.
 *
 * @example
 * $redactor = new Redactor();
 * $safe     = $redactor->redactArray(['Authorization' => 'Bearer abc', 'page' => '2']);
 * // ['Authorization' => '[REDACTED]', 'page' => '2']
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    private const MAX_DEPTH = 8;

    /**
     * Lowercase, separator-free fragments. A key is sensitive when its
     * separator-free form contains one of these ("X-Api-Key" -> "xapikey").
     *
     * @var list<string>
     */
    private const FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'apikey', 'authorization',
        'cookie', 'credential', 'private', 'signature', 'csrf', 'bearer',
        'jwt', 'salt', 'hash', 'phpsessid',
    ];

    /**
     * Whole words (split on separators and camelCase) that mark a key as
     * sensitive. Too short to be safe as plain fragments ("pass" would also
     * hit "bypass"), so they have to match a complete word.
     *
     * @var list<string>
     */
    private const TOKENS = ['pass', 'pwd', 'key', 'auth', 'sig', 'sid'];

    /**
     * Shapes that are secrets wherever they appear in text.
     *
     * @var array<string, string>
     */
    private const VALUE_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i'                         => 'Bearer ' . self::MASK,
        '/\bBasic\s+[A-Za-z0-9+\/]{8,}={0,2}/'                         => 'Basic ' . self::MASK,
        '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/' => self::MASK,
        '#\b([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i'              => '$1' . self::MASK . '@',
        '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/'              => self::MASK,
        '/\bAIza[0-9A-Za-z_\-]{30,}/'                                  => self::MASK,
        '/\bAKIA[0-9A-Z]{16}\b/'                                       => self::MASK,
        '/\bgh[pousr]_[A-Za-z0-9]{30,}/'                               => self::MASK,
        '/\b((?:set-)?cookie\s*:\s*)[^\r\n]+/i'                        => '$1' . self::MASK,
    ];

    /**
     * `password=abc`, `"token":"abc"`, `Authorization: Bearer abc` and friends.
     */
    private const KEY_VALUE = '/\b([A-Za-z0-9_\-]*(?:password|passwd|pwd|secret|token|api[_-]?key|authorization|credential)[A-Za-z0-9_\-]*)("?\s*[=:]\s*)((?:(?:Bearer|Basic)\s+)?(?:"[^"]*"|\'[^\']*\'|\[REDACTED\]|[^\s&,;"\'}\]]+))/i';

    /** @var list<string> */
    private array $fragments;

    /**
     * @param list<string> $extraKeys Extra key fragments to treat as sensitive
     */
    public function __construct(array $extraKeys = [])
    {
        $fragments = self::FRAGMENTS;
        foreach ($extraKeys as $extra) {
            $compact = self::compact($extra);
            if ($compact !== '') {
                $fragments[] = $compact;
            }
        }

        $this->fragments = array_values(array_unique($fragments));
    }

    /**
     * Whether an array key (header name, env name, form field...) names a secret.
     */
    public function isSensitiveKey(string $key): bool
    {
        $compact = self::compact($key);
        foreach ($this->fragments as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        $spaced = self::replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return false;
        }

        foreach ($tokens as $token) {
            if (in_array($token, self::TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scrub secrets out of free text.
     */
    public function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (self::VALUE_PATTERNS as $pattern => $replacement) {
            $value = self::replace($pattern, $replacement, $value);
        }

        return preg_replace_callback(self::KEY_VALUE, self::maskKeyValue(...), $value) ?? $value;
    }

    /**
     * Redact any value: strings are scrubbed, arrays are walked, objects and
     * resources are reduced to a label (their contents are never read).
     */
    public function redact(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->scrubString($value);
        }

        if (is_array($value)) {
            return $this->redactArray($value, $depth);
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['…' => 'max depth reached'];
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? self::MASK
                : $this->redact($value, $depth + 1);
        }

        return $out;
    }

    /**
     * Keep the shape of an array (so you can still see WHICH session keys
     * exist) but replace every leaf with its type. Used for session data,
     * where the values are the sensitive part.
     *
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function maskValues(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['…' => 'max depth reached'];
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = is_array($value)
                ? $this->maskValues($value, $depth + 1)
                : '[REDACTED ' . get_debug_type($value) . ']';
        }

        return $out;
    }

    /**
     * Whether a SQL statement reads or writes something sensitive.
     */
    public function sqlMentionsSensitive(string $sql): bool
    {
        $compact = self::compact($sql);
        foreach ($this->fragments as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bound parameters have no keys, so they are masked as a whole when the
     * SQL touches a sensitive column or table, and scrubbed otherwise.
     */
    public function redactQueryParams(string $sql, mixed $params): mixed
    {
        if ($params === null || $params === []) {
            return $params;
        }

        return $this->sqlMentionsSensitive($sql) ? self::MASK : $this->redact($params);
    }

    /**
     * @param array<int|string, string> $match
     */
    private static function maskKeyValue(array $match): string
    {
        $quote = substr($match[3] ?? '', 0, 1);
        $mask  = ($quote === '"' || $quote === "'") ? $quote . self::MASK . $quote : self::MASK;

        return ($match[1] ?? '') . ($match[2] ?? '') . $mask;
    }

    private static function compact(string $text): string
    {
        return self::replace('/[^a-z0-9]+/', '', strtolower($text));
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
