<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * Simple scoped key-value settings store, backed by a JSON file per module
 * (storage/modules/{slug}/settings.json). Reads are always allowed so a
 * module's own UI/routes can display current config without needing
 * settings.manage; only writes require the permission, since a write is
 * what a reviewer actually needs to reason about.
 *
 * set() holds an exclusive lock for the full read-modify-write cycle (not
 * just the final write) — otherwise two concurrent set() calls can both
 * read the same starting state, and one silently overwrites the other's
 * change. all() takes a shared lock so it can't observe a torn/partial
 * write from a set() in progress.
 *
 * Good enough for a v1 marketplace; swap the backing store for a real
 * `module_settings` DB table later without changing this facade's API.
 */
final class SettingsFacade
{
    public function __construct(
        private readonly string $path,
        private readonly ModulePermissions $permissions,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertAllowed();
        $this->withLock(function (array $all) use ($key, $value) {
            $all[$key] = $value;
            return $all;
        });
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return json_decode((string) $contents, true) ?: [];
    }

    /**
     * Runs $mutator against the current settings under an exclusive lock
     * held for the full read-modify-write cycle, so two concurrent set()
     * calls can't both read the same starting state and have one silently
     * overwrite the other's change.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $mutator
     */
    private function withLock(callable $mutator): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Could not open settings file for writing: {$this->path}");
        }

        try {
            flock($handle, LOCK_EX);

            $contents = stream_get_contents($handle);
            $current  = $contents !== '' ? (json_decode($contents, true) ?: []) : [];

            $updated = $mutator($current);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($updated, JSON_PRETTY_PRINT));
            fflush($handle);

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function assertAllowed(): void
    {
        if (! $this->permissions->can('settings.manage')) {
            throw new ModulePermissionException('Module tried to write settings without declaring "settings.manage"');
        }
    }
}
