<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * Filesystem access scoped hard to storage/modules/{slug}/. No amount of
 * "../" in a caller-supplied relative path can escape this directory —
 * resolve() rejects anything whose real path lands outside $root.
 */
final class StorageFacade
{
    public function __construct(
        private readonly string $root,
        private readonly ModulePermissions $permissions,
    ) {
        if (! is_dir($this->root)) {
            @mkdir($this->root, 0755, true);
        }
    }

    public function put(string $relativePath, string $contents): void
    {
        $this->assertAllowed();
        file_put_contents($this->resolve($relativePath), $contents);
    }

    public function get(string $relativePath): string|false
    {
        $this->assertAllowed();
        $path = $this->resolve($relativePath);
        return is_file($path) ? file_get_contents($path) : false;
    }

    public function exists(string $relativePath): bool
    {
        $this->assertAllowed();
        return is_file($this->root . '/' . ltrim($relativePath, '/'));
    }

    public function delete(string $relativePath): void
    {
        $this->assertAllowed();
        @unlink($this->resolve($relativePath));
    }

    private function assertAllowed(): void
    {
        if (! $this->permissions->can('storage.access')) {
            throw new ModulePermissionException('Module tried to access storage without declaring "storage.access"');
        }
    }

    private function resolve(string $relativePath): string
    {
        $target = $this->root . '/' . ltrim($relativePath, '/');
        $dir    = dirname($target);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $realDir  = realpath($dir);
        $realRoot = realpath($this->root);

        if ($realDir === false || $realRoot === false || ! str_starts_with($realDir, $realRoot)) {
            throw new ModulePermissionException("Storage path escapes module sandbox: {$relativePath}");
        }

        return $target;
    }
}
