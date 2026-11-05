<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

/**
 * Options for the toolbar "Ask AI" panel and the error page "Explain and fix",
 * read from `config['debug']['assist']`. Every key is optional.
 *
 * Like every AI feature in Rhapsody this is development-only: nothing here can
 * switch it on outside `app_env = development`.
 *
 * @example
 * 'debug' => ['assist' => ['apply' => false, 'context_lines' => 20]],
 */
final readonly class AssistSettings
{
    public function __construct(
        public bool $enabled = true,
        public bool $apply = true,
        public int $contextLines = 15,
        public int $maxSourceFiles = 3,
        public int $maxPayloadBytes = 60000,
        public int $keep = 50,
    ) {
    }

    /**
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
        $section  = is_array($debug) ? ($debug['assist'] ?? null) : null;
        $defaults = new self();

        if (! is_array($section)) {
            return $defaults;
        }

        return new self(
            enabled: ($section['enabled'] ?? $defaults->enabled) !== false,
            apply: ($section['apply'] ?? $defaults->apply) !== false,
            contextLines: self::positiveInt($section['context_lines'] ?? null, $defaults->contextLines, 80),
            maxSourceFiles: self::positiveInt($section['max_source_files'] ?? null, $defaults->maxSourceFiles, 8),
            maxPayloadBytes: self::positiveInt($section['max_payload_bytes'] ?? null, $defaults->maxPayloadBytes, 400000),
            keep: self::positiveInt($section['keep'] ?? null, $defaults->keep, 500),
        );
    }

    private static function positiveInt(mixed $value, int $default, int $max): int
    {
        return is_int($value) && $value > 0 ? min($value, $max) : $default;
    }
}
