<?php

namespace Rhapsody\Core\Modules;

/**
 * Tracks which discovered modules have been explicitly activated.
 *
 * Being present in vendor/ (discoverable via Composer) and being
 * "installed" are different things — a module only boots (registers
 * routes/listeners/etc, every request) once an operator has explicitly run
 * `module:install`. That mirrors a real marketplace flow: `composer
 * require` gets you the code, activation is a separate, deliberate step.
 * install()/uninstall() lifecycle hooks only ever run once, driven by this
 * store — never on every request boot.
 */
final class ModuleInstallationStore
{
    private readonly string $path;

    public function __construct(string $basePath)
    {
        $this->path = $basePath . '/storage/modules/installed.json';
    }

    public function isInstalled(string $packageName): bool
    {
        return in_array($packageName, $this->all(), true);
    }

    public function markInstalled(string $packageName): void
    {
        $all = $this->all();
        if (! in_array($packageName, $all, true)) {
            $all[] = $packageName;
            $this->write($all);
        }
    }

    public function markUninstalled(string $packageName): void
    {
        $this->write(array_values(array_diff($this->all(), [$packageName])));
    }

    /** @return string[] */
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
        file_put_contents($this->path, json_encode(array_values($all), JSON_PRETTY_PRINT));
    }
}
