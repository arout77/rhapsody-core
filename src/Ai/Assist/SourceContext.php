<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use Rhapsody\Core\Snapshot\Redactor;
use Rhapsody\Core\Support\Fs;
use Throwable;

/**
 * Picks the few source files worth showing the model for a failed request and
 * cuts a window of lines around each failing line.
 *
 * What is NEVER read: `.env*`, `config/`, `storage/`, `.git/`, `node_modules/`,
 * anything outside the project root and anything that is not a known source
 * extension. Secrets that slip into ordinary source files (`'api_key' => 'x'`,
 * `$password = 'x'`) are masked by key name, and the rest goes through the
 * {@see Redactor}. The developer reviews the exact text before it is sent.
 *
 * @example
 * $excerpts = SourceContext::collect($snapshot, Path::root(), new Redactor(), 15, 3);
 */
final class SourceContext
{
    private const MAX_FILE_BYTES = 524288;

    private const EXTENSIONS = ['php', 'twig', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'html'];

    private const BLOCKED = '#(^|/)(\.env[^/]*|config|storage|\.git|node_modules)(/|$)#i';

    /**
     * @param  array<array-key, mixed> $snapshot A snapshot as produced by SnapshotBuilder
     * @return list<SourceExcerpt>
     */
    public static function collect(array $snapshot, string $root, Redactor $redactor, int $contextLines, int $maxFiles): array
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return [];
        }

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $out      = [];
        $seen     = [];

        foreach (self::locations($snapshot) as [$path, $line]) {
            if (count($out) >= $maxFiles) {
                break;
            }

            $relative = self::relative($path, $realRoot);
            if ($relative === null || isset($seen[$relative])) {
                continue;
            }

            $vendor = str_starts_with($relative, 'vendor/');
            // Vendor code is context only, and only for the frame that actually threw.
            if ($vendor && $out !== []) {
                continue;
            }

            $excerpt = self::excerpt($realRoot, $relative, $line, $contextLines, $redactor, ! $vendor);
            if ($excerpt === null) {
                continue;
            }

            $seen[$relative] = true;
            $out[]           = $excerpt;
        }

        return $out;
    }

    /**
     * Mask secrets that sit in source code rather than in data.
     */
    public static function maskLine(string $line, Redactor $redactor): string
    {
        $line = preg_replace_callback(
            '/((["\'])([A-Za-z0-9_\-. ]+)\2\s*=>\s*)(["\'])((?:\\\\.|(?!\4).)*)\4/',
            static fn (array $m): string => $redactor->isSensitiveKey($m[3]) ? $m[1] . $m[4] . Redactor::MASK . $m[4] : $m[0],
            $line
        ) ?? $line;

        $line = preg_replace_callback(
            '/(\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*)(["\'])((?:\\\\.|(?!\2).)*)\2/',
            static fn (array $m): string => $redactor->isSensitiveKey(ltrim(trim(explode('=', $m[1])[0]), '$')) ? $m[1] . $m[2] . Redactor::MASK . $m[2] : $m[0],
            $line
        ) ?? $line;

        $line = preg_replace_callback(
            '/\b((?:define\(\s*["\']|const\s+)([A-Za-z0-9_]+)(?:["\']\s*,|\s*=)\s*)(["\'])((?:\\\\.|(?!\3).)*)\3/',
            static fn (array $m): string => $redactor->isSensitiveKey($m[2]) ? $m[1] . $m[3] . Redactor::MASK . $m[3] : $m[0],
            $line
        ) ?? $line;

        return $redactor->scrubString($line);
    }

    /**
     * Failing locations in priority order: the exception itself, then each
     * stack frame from the inside out.
     *
     * @param  array<array-key, mixed> $snapshot
     * @return list<array{0: string, 1: int}>
     */
    private static function locations(array $snapshot): array
    {
        $exception = $snapshot['exception'] ?? null;
        if (! is_array($exception)) {
            return [];
        }

        $out  = [];
        $file = $exception['file'] ?? null;
        $line = $exception['line'] ?? null;
        if (is_string($file) && is_int($line)) {
            $out[] = [$file, $line];
        }

        $trace = $exception['trace'] ?? null;
        if (is_array($trace)) {
            foreach ($trace as $frame) {
                if (is_string($frame) && preg_match('/ at (.+):(\d+)$/', $frame, $m) === 1) {
                    $out[] = [$m[1], (int) $m[2]];
                }
            }
        }

        return $out;
    }

    /**
     * A project-relative, forward-slash path, or null if the file is outside
     * the root, blocked or not a source file.
     */
    private static function relative(string $path, string $realRoot): ?string
    {
        $path = str_replace('\\', '/', $path);

        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
        $absolute   = $isAbsolute ? $path : $realRoot . '/' . ltrim($path, './');

        $real = realpath($absolute);
        if ($real === false) {
            return null;
        }

        $real = str_replace('\\', '/', $real);
        if (! str_starts_with($real, $realRoot . '/')) {
            return null;
        }

        $relative = substr($real, strlen($realRoot) + 1);
        $ext      = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (! in_array($ext, self::EXTENSIONS, true) || preg_match(self::BLOCKED, $relative) === 1) {
            return null;
        }

        return $relative;
    }

    private static function excerpt(string $realRoot, string $relative, int $line, int $context, Redactor $redactor, bool $patchable): ?SourceExcerpt
    {
        $absolute = $realRoot . '/' . $relative;
        $size     = @filesize($absolute);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        try {
            $content = Fs::read($absolute);
        } catch (Throwable) {
            return null;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $total = count($lines);
        $line  = max(1, min($line, $total));
        $start = max(1, $line - $context);
        $end   = min($total, $line + $context);

        $window = [];
        for ($n = $start; $n <= $end; $n++) {
            $window[] = self::maskLine(mb_scrub(rtrim($lines[$n - 1], "\r")), $redactor);
        }

        return new SourceExcerpt($relative, $start, $end, $line, implode("\n", $window), $patchable);
    }
}
