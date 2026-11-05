<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use ParseError;
use Rhapsody\Core\Snapshot\Redactor;
use Rhapsody\Core\Support\Fs;
use Rhapsody\Core\Support\Json;
use Throwable;

/**
 * Validates, previews and applies one "replace this exact text with that"
 * change to a project file.
 *
 * It is deliberately strict, because the text comes from a language model:
 *  - the file must be inside the project root and a known source type, and
 *    never under vendor/, config/, storage/, .git/ or node_modules/ or be a .env file;
 *  - the text to replace must occur EXACTLY once, so there is nothing to guess;
 *  - neither side may contain a masked ([REDACTED]) value;
 *  - a PHP file that parsed before must still parse afterwards;
 *  - line endings (CRLF on Windows) are preserved, and the rest of the file
 *    is rewritten byte for byte.
 *
 * Applying keeps a copy of the original file, so every change can be undone.
 *
 * @example
 * $patch = new PatchTool(Path::root());
 * echo $patch->preview('app/Controllers/HomeController.php', $old, $new); // unified diff
 * $patch->apply('app/Controllers/HomeController.php', $old, $new, $undoDir, $id);
 */
final class PatchTool
{
    private const EXTENSIONS = ['php', 'twig', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'html'];

    private const BLOCKED = '#(^|/)(vendor|\.env[^/]*|config|storage|\.git|node_modules)(/|$)#i';

    private const MAX_FILE_BYTES = 524288;

    private const DIFF_CONTEXT = 3;

    public function __construct(private readonly string $root)
    {
    }

    /**
     * The change as a unified diff, without touching the file.
     *
     * @throws AssistException If the change cannot be applied safely
     */
    public function preview(string $file, string $search, string $replace): string
    {
        return $this->compute($file, $search, $replace)['diff'];
    }

    /**
     * Write the change, keeping the original. Returns the path of the saved original.
     *
     * @throws AssistException If the change cannot be applied safely or the file cannot be written
     */
    public function apply(string $file, string $search, string $replace, string $undoDirectory, string $id): string
    {
        $change = $this->compute($file, $search, $replace);

        try {
            if (! is_dir($undoDirectory) && ! @mkdir($undoDirectory, 0775, true) && ! is_dir($undoDirectory)) {
                throw new AssistException('Could not create the backup folder, so nothing was changed.', 500);
            }

            $backup = $undoDirectory . DIRECTORY_SEPARATOR . $id . '.orig';
            Fs::write($backup, $change['before']);
            Fs::write(
                $undoDirectory . DIRECTORY_SEPARATOR . $id . '.json',
                Json::encode(['id' => $id, 'file' => $change['relative'], 'applied_at' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $temp = $change['path'] . '.rhapsody-tmp';
            Fs::write($temp, $change['after'], LOCK_EX);

            if (! @rename($temp, $change['path'])) {
                @unlink($temp);
                throw new AssistException('Could not write the file, so nothing was changed.', 500);
            }
        } catch (AssistException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AssistException('Could not write the file, so nothing was changed.', 500, $e);
        }

        return $backup;
    }

    /**
     * @return array{path: string, relative: string, before: string, after: string, diff: string}
     */
    private function compute(string $file, string $search, string $replace): array
    {
        if (str_contains($search, Redactor::MASK) || str_contains($replace, Redactor::MASK)) {
            throw new AssistException('The proposed change contains a masked value, so it was discarded.', 422);
        }

        [$path, $relative] = $this->resolve($file);

        $searchLf  = str_replace("\r\n", "\n", $search);
        $replaceLf = str_replace("\r\n", "\n", $replace);

        if (trim($searchLf) === '' || $searchLf === $replaceLf) {
            throw new AssistException("The proposed change to {$relative} is empty.", 422);
        }

        try {
            $before = Fs::read($path);
        } catch (Throwable $e) {
            throw new AssistException("Could not read {$relative}.", 500, $e);
        }

        $eol      = str_contains($before, "\r\n") ? "\r\n" : "\n";
        $needle   = $eol === "\r\n" ? str_replace("\n", "\r\n", $searchLf) : $searchLf;
        $new      = $eol === "\r\n" ? str_replace("\n", "\r\n", $replaceLf) : $replaceLf;
        $matches  = substr_count($before, $needle);

        if ($matches === 0) {
            throw new AssistException("The text to replace was not found in {$relative}; the file may have changed.", 409);
        }

        if ($matches > 1) {
            throw new AssistException("The text to replace appears {$matches} times in {$relative}, so the change is ambiguous.", 422);
        }

        $position = (int) strpos($before, $needle);
        $after    = substr_replace($before, $new, $position, strlen($needle));

        if (str_ends_with(strtolower($relative), '.php') && self::parses($before) && ! self::parses($after)) {
            throw new AssistException("The proposed change to {$relative} would introduce a PHP syntax error, so it was discarded.", 422);
        }

        $line = substr_count($before, "\n", 0, $position) + 1;
        $diff = self::diff($relative, str_replace("\r\n", "\n", $before), $line, $searchLf, $replaceLf);

        return ['path' => $path, 'relative' => $relative, 'before' => $before, 'after' => $after, 'diff' => $diff];
    }

    /**
     * @return array{0: string, 1: string} absolute path, project-relative path
     */
    private function resolve(string $file): array
    {
        $relative = preg_replace('#^(\./)+#', '', str_replace('\\', '/', trim($file))) ?? '';
        if ($relative === '' || str_contains($relative, '../') || str_starts_with($relative, '/')) {
            throw new AssistException('The proposed change names a file outside the project.', 422);
        }

        $realRoot = realpath($this->root);
        $real     = $realRoot === false ? false : realpath($realRoot . '/' . $relative);
        if ($realRoot === false || $real === false) {
            throw new AssistException("The proposed change names a file that does not exist: {$relative}.", 422);
        }

        $rootNorm = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $realNorm = str_replace('\\', '/', $real);
        if (! str_starts_with($realNorm, $rootNorm)) {
            throw new AssistException('The proposed change names a file outside the project.', 422);
        }

        $relative = substr($realNorm, strlen($rootNorm));
        $ext      = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (! in_array($ext, self::EXTENSIONS, true) || preg_match(self::BLOCKED, $relative) === 1) {
            throw new AssistException("Rhapsody does not edit {$relative} automatically.", 403);
        }

        $size = @filesize($real);
        if ($size === false || $size > self::MAX_FILE_BYTES || ! is_file($real) || ! is_writable($real)) {
            throw new AssistException("{$relative} cannot be edited (missing, too large or read-only).", 403);
        }

        return [$real, $relative];
    }

    private static function parses(string $code): bool
    {
        try {
            return token_get_all($code, TOKEN_PARSE) !== [];
        } catch (ParseError) {
            return false;
        }
    }

    /**
     * A minimal unified diff for one replaced block: common leading and
     * trailing lines are folded into context.
     */
    private static function diff(string $relative, string $fileLf, int $startLine, string $searchLf, string $replaceLf): string
    {
        $old = explode("\n", $searchLf);
        $new = explode("\n", $replaceLf);

        $head = 0;
        $max  = min(count($old), count($new));
        while ($head < $max && $old[$head] === $new[$head]) {
            $head++;
        }

        $tail = 0;
        while ($tail < $max - $head && $old[count($old) - 1 - $tail] === $new[count($new) - 1 - $tail]) {
            $tail++;
        }

        $removed = array_slice($old, $head, count($old) - $head - $tail);
        $added   = array_slice($new, $head, count($new) - $head - $tail);

        $fileLines   = explode("\n", $fileLf);
        $firstChange = $startLine + $head;
        $afterStart  = $startLine + count($old) - $tail;
        $ctxStart    = max(1, $firstChange - self::DIFF_CONTEXT);

        $before = array_slice($fileLines, $ctxStart - 1, $firstChange - $ctxStart);
        $after  = array_slice($fileLines, $afterStart - 1, self::DIFF_CONTEXT);

        $lines = [];
        foreach ($before as $text) {
            $lines[] = ' ' . $text;
        }
        foreach ($removed as $text) {
            $lines[] = '-' . $text;
        }
        foreach ($added as $text) {
            $lines[] = '+' . $text;
        }
        foreach ($after as $text) {
            $lines[] = ' ' . $text;
        }

        $oldLen = count($before) + count($removed) + count($after);
        $newLen = count($before) + count($added) + count($after);

        return "--- a/{$relative}\n+++ b/{$relative}\n@@ -{$ctxStart},{$oldLen} +{$ctxStart},{$newLen} @@\n" . implode("\n", $lines);
    }
}
