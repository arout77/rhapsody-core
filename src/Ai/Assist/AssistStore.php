<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use Rhapsody\Core\Support\Fs;
use Rhapsody\Core\Support\Json;
use Throwable;

/**
 * Remembers the fixes the model proposed, on the server, so the browser only
 * ever sends back an id. A forged "apply" request can therefore never carry
 * its own file name or replacement text.
 *
 * Small ring buffer of JSON files in storage/debug/assist, guarded against
 * web access like the snapshot folder. Original files saved before a change
 * is applied live in the `undo/` sub-folder and are never pruned.
 */
final class AssistStore
{
    private const ID_PATTERN = '/^[a-f0-9]{16}$/';

    public function __construct(
        private readonly string $directory,
        private readonly int $keep = 50,
    ) {
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * @param array<string, mixed> $record
     * @throws AssistException If the record cannot be stored
     */
    public function save(string $id, array $record): void
    {
        $this->assertId($id);

        try {
            $this->ensureDirectory();
            $path = $this->path($id);
            $temp = $path . '.tmp';
            Fs::write($temp, Json::encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
            if (! @rename($temp, $path)) {
                @unlink($temp);
                throw new AssistException('Could not store the proposed change.', 500);
            }
            $this->prune();
        } catch (AssistException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AssistException('Could not store the proposed change.', 500, $e);
        }
    }

    /**
     * @return array<string, mixed>
     * @throws AssistException If the id is unknown
     */
    public function load(string $id): array
    {
        $this->assertId($id);

        try {
            $record = Json::decodeArray(Fs::read($this->path($id)));
        } catch (Throwable) {
            throw new AssistException('That proposed change no longer exists. Ask again.', 404);
        }

        $out = [];
        foreach ($record as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public function undoDirectory(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'undo';
    }

    /**
     * @param array<string, mixed> $record
     * @throws AssistException
     */
    public function markApplied(string $id, array $record): void
    {
        $record['applied']    = true;
        $record['applied_at'] = gmdate('c');
        $this->save($id, $record);
    }

    private function assertId(string $id): void
    {
        if (! self::isValidId($id)) {
            throw new AssistException('Unknown proposed change.', 404);
        }
    }

    private function path(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function prune(): void
    {
        $files = Fs::glob($this->directory . DIRECTORY_SEPARATOR . '*.json');
        usort($files, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));

        foreach (array_slice($files, max(1, $this->keep)) as $old) {
            @unlink($old);
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new AssistException('Could not create the assist folder.', 500);
        }

        foreach (['.htaccess' => "Require all denied\n", 'index.html' => ''] as $name => $contents) {
            $file = $this->directory . DIRECTORY_SEPARATOR . $name;
            if (! file_exists($file)) {
                Fs::write($file, $contents);
            }
        }
    }
}
