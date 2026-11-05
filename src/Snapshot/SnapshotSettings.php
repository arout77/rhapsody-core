<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

/**
 * Snapshot options, read from `config['debug']['snapshots']`.
 *
 * Every key is optional, so apps that never add the section get safe defaults.
 *
 * @example
 * 'debug' => ['snapshots' => ['keep' => 50, 'redact_keys' => ['ssn']]],
 */
final readonly class SnapshotSettings
{
    /**
     * @param list<string> $redactKeys     Extra key fragments to mask (on top of the built-in list)
     * @param list<string> $envAllowlist   Extra environment variable names to include
     * @param list<string> $ignorePrefixes Request paths containing any of these are not recorded
     */
    public function __construct(
        public bool $enabled = true,
        public int $keep = 25,
        public int $maxBodyBytes = 8192,
        public int $maxQueries = 100,
        public int $logLines = 20,
        public array $redactKeys = [],
        public array $envAllowlist = [],
        public array $ignorePrefixes = ['/img/'],
    ) {
    }

    /**
     * Snapshots exist only in development, whatever else is configured.
     *
     * @param array<string, mixed> $config
     */
    public static function isDevelopment(array $config): bool
    {
        return ($config['app_env'] ?? 'production') === 'development';
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $debug    = $config['debug'] ?? null;
        $section  = is_array($debug) ? ($debug['snapshots'] ?? null) : null;
        $defaults = new self();

        if (! is_array($section)) {
            return $defaults;
        }

        return new self(
            enabled: ($section['enabled'] ?? $defaults->enabled) !== false,
            keep: self::positiveInt($section['keep'] ?? null, $defaults->keep),
            maxBodyBytes: self::positiveInt($section['max_body_bytes'] ?? null, $defaults->maxBodyBytes),
            maxQueries: self::positiveInt($section['max_queries'] ?? null, $defaults->maxQueries),
            logLines: self::positiveInt($section['log_lines'] ?? null, $defaults->logLines),
            redactKeys: self::strings($section['redact_keys'] ?? null, []),
            envAllowlist: self::strings($section['env_allowlist'] ?? null, []),
            ignorePrefixes: self::strings($section['ignore_prefixes'] ?? null, $defaults->ignorePrefixes),
        );
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private static function strings(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
