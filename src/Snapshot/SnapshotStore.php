<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

use JsonException;
use RuntimeException;
use Rhapsody\Core\Support\Fs;
use Rhapsody\Core\Support\Json;

/**
 * A small ring buffer of snapshot files: the newest N are kept, older ones
 * are deleted as new ones arrive.
 *
 * Files are named by snapshot id, which sorts chronologically, so "newest"
 * is just the last name in sort order. Writes go to a temp file and are
 * renamed into place, so a reader never sees a half-written snapshot.
 *
 * The directory gets a deny-all .htaccess and an empty index.html on first
 * use, in case the web server's document root happens to include storage/.
 *
 * @example
 * $store = new SnapshotStore(Path::storage('debug/snapshots'), keep: 25);
 * $path  = $store->save($snapshot);
 * $last  = $store->latestPath();
 */
final class SnapshotStore
{
    public function __construct(
        private readonly string $directory,
        private readonly int $keep = 25,
    ) {
    }

    /**
     * @throws RuntimeException|JsonException If the snapshot cannot be encoded or written
     */
    public function save(RequestSnapshot $snapshot): string
    {
        $this->ensureDirectory();

        $path = $this->directory . DIRECTORY_SEPARATOR . $snapshot->id . '.json';
        $temp = $path . '.tmp';

        Fs::write($temp, $snapshot->toJson(), LOCK_EX);

        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException("Unable to move snapshot into place: {$path}");
        }

        $this->prune();

        return $path;
    }

    /**
     * Every stored snapshot, newest first.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $files = Fs::glob($this->directory . DIRECTORY_SEPARATOR . '*.json');
        rsort($files, SORT_STRING);

        return $files;
    }

    public function latestPath(): ?string
    {
        return $this->paths()[0] ?? null;
    }

    /**
     * @throws RuntimeException|JsonException If the file is missing or not valid JSON
     * @return array<mixed>
     */
    public function read(string $path): array
    {
        return Json::decodeArray(Fs::read($path));
    }

    private function prune(): void
    {
        foreach (array_slice($this->paths(), max(1, $this->keep)) as $old) {
            @unlink($old);
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Unable to create snapshot directory: {$this->directory}");
        }

        $guards = [
            '.htaccess'  => "Require all denied\n",
            'index.html' => '',
        ];

        foreach ($guards as $name => $contents) {
            $file = $this->directory . DIRECTORY_SEPARATOR . $name;
            if (! file_exists($file)) {
                Fs::write($file, $contents);
            }
        }
    }
}
