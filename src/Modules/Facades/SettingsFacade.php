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
        $all       = $this->all();
        $all[$key] = $value;
        $this->write($all);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        return json_decode((string) file_get_contents($this->path), true) ?: [];
    }

    private function write(array $all): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($this->path, json_encode($all, JSON_PRETTY_PRINT));
    }

    private function assertAllowed(): void
    {
        if (! $this->permissions->can('settings.manage')) {
            throw new ModulePermissionException('Module tried to write settings without declaring "settings.manage"');
        }
    }
}
