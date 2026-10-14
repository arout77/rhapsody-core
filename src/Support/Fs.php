<?php
namespace Rhapsody\Core\Support;

use RuntimeException;

/**
 * Filesystem helpers that throw instead of returning false.
 *
 * PHP's native file functions signal failure with `false`, which forces every
 * caller to handle a value that should never be silently ignored. These wrappers
 * turn each failure into a RuntimeException so callers always get the real type.
 *
 * @example
 * $json = Fs::read(Path::root() . '/composer.json');
 */
final class Fs
{
    /**
     * Read an entire file into a string.
     *
     * @throws RuntimeException If the file cannot be read
     */
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read file: {$path}");
        }

        return $contents;
    }

    /**
     * Read a file into an array of lines.
     *
     * Defaults to stripping newlines and skipping empty lines.
     *
     * @param  int-mask-of<FILE_USE_INCLUDE_PATH|FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES|FILE_NO_DEFAULT_CONTEXT> $flags
     * @throws RuntimeException     If the file cannot be read
     * @return list<string>
     */
    public static function lines(string $path, int $flags = FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES): array
    {
        $lines = @file($path, $flags);

        if ($lines === false) {
            throw new RuntimeException("Unable to read file: {$path}");
        }

        return $lines;
    }

    /**
     * Write a string to a file, returning the number of bytes written.
     *
     * @param  int          $flags Any combination of the FILE_* flags (e.g. FILE_APPEND, LOCK_EX)
     * @throws RuntimeException     If the file cannot be written
     */
    public static function write(string $path, string $contents, int $flags = 0): int
    {
        $bytes = @file_put_contents($path, $contents, $flags);

        if ($bytes === false) {
            throw new RuntimeException("Unable to write file: {$path}");
        }

        return $bytes;
    }

    /**
     * Find pathnames matching a pattern. Returns an empty list when nothing matches.
     *
     * @param  int          $flags Any combination of the GLOB_* flags
     * @throws RuntimeException     If the glob itself fails (not merely "no matches")
     * @return list<string>
     */
    public static function glob(string $pattern, int $flags = 0): array
    {
        $matches = glob($pattern, $flags);

        if ($matches === false) {
            throw new RuntimeException("Unable to glob pattern: {$pattern}");
        }

        return $matches;
    }

    /**
     * Resolve a path to its canonical absolute form.
     *
     * @throws RuntimeException If the path does not exist
     */
    public static function realpath(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new RuntimeException("Path does not exist: {$path}");
        }

        return $resolved;
    }

    /**
     * The current working directory.
     *
     * @throws RuntimeException If it cannot be determined
     */
    public static function cwd(): string
    {
        $cwd = getcwd();

        if ($cwd === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        return $cwd;
    }
}
