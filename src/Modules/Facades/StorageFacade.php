<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * Filesystem access scoped hard to storage/modules/{slug}/. No amount of
 * "../" in a caller-supplied relative path can escape this directory —
 * every method rejects a path containing a ".." or "." segment BEFORE
 * touching the filesystem at all (not just before the final read/write),
 * so a traversal attempt can't even trigger a directory to be created
 * outside the sandbox as a side effect. realpath() verification after
 * directory creation remains as defense-in-depth against a pre-existing
 * symlink inside the sandbox pointing outside it.
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
        $safe = $this->assertSafeRelativePath($relativePath);
        return is_file($this->root . '/' . $safe);
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

    /**
     * Rejects any ".." or "." path segment outright — a purely lexical
     * check that needs no filesystem access, so it can run (and reject)
     * before any mkdir()/file operation happens, not just before the
     * final read/write. Without a ".." segment, $this->root . '/' . $safe
     * can only ever land at-or-under $this->root, however the rest of the
     * path is constructed.
     */
    private function assertSafeRelativePath(string $relativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        $segments   = explode('/', $normalized);

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new ModulePermissionException("Storage path escapes module sandbox: {$relativePath}");
        }

        return $normalized;
    }

    private function resolve(string $relativePath): string
    {
        $safe   = $this->assertSafeRelativePath($relativePath);
        $target = $this->root . '/' . $safe;
        $dir    = dirname($target);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Defense-in-depth: catches the narrower case of a pre-existing
        // symlink inside the sandbox pointing outside it, which a purely
        // lexical check can't detect on its own.
        $realDir  = realpath($dir);
        $realRoot = realpath($this->root);

        if ($realDir === false || $realRoot === false || ! str_starts_with($realDir, $realRoot)) {
            throw new ModulePermissionException("Storage path escapes module sandbox: {$relativePath}");
        }

        return $target;
    }
}
