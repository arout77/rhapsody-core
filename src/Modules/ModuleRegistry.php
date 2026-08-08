<?php

namespace Rhapsody\Core\Modules;

use Composer\InstalledVersions;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\FrameworkInfo;
use Rhapsody\Core\Modules\Contracts\ModuleServiceProviderInterface;
use Rhapsody\Core\Modules\Exceptions\ManifestValidationException;

/**
 * Discovers, activates, and boots modules.
 *
 * Discovery relies entirely on Composer's package type: any package
 * declaring "type": "rhapsody-module" in its own composer.json is a
 * candidate — "installed" already has a well-defined meaning via
 * composer.lock, so there's no bespoke installer/registry file to keep in
 * sync. This is what the "Composer packages, marketplace-gated" distribution
 * model buys: the marketplace only has to manage licensing/access to a
 * private repository, not the install mechanics themselves.
 *
 * "Discovered" (present via Composer) and "installed" (activated, tracked
 * in ModuleInstallationStore) are deliberately different states — see
 * ModuleInstallationStore for why. Only installed modules boot.
 */
final class ModuleRegistry
{
    /** @var ModuleManifest[] */
    private array $booted = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $basePath,
        private readonly ModuleInstallationStore $installs,
    ) {
    }

    public function bootAll(): void
    {
        foreach ($this->discover() as $manifest) {
            if (! $this->installs->isInstalled($manifest->name)) {
                continue; // discovered but never activated — nothing boots
            }
            $this->bootOne($manifest);
        }
    }

    /** @return ModuleManifest[] every module Composer knows about, installed or not */
    public function discover(): array
    {
        $manifests = [];

        if (! class_exists(InstalledVersions::class)) {
            return $manifests;
        }

        foreach (InstalledVersions::getInstalledPackagesByType('rhapsody-module') as $packageName) {
            $installPath = InstalledVersions::getInstallPath($packageName);
            if ($installPath === null) {
                continue;
            }

            $manifestPath = rtrim($installPath, '/') . '/module.json';

            try {
                $manifests[] = ModuleManifest::fromFile($manifestPath);
            } catch (ManifestValidationException $e) {
                // A broken manifest disables that one module, not the whole app.
                // Unreachable for anything published through the marketplace
                // (manifest-lint runs at submission time), but a local/dev
                // install can still hand-edit module.json.
                error_log("ModuleRegistry: skipping \"{$packageName}\": " . $e->getMessage());
            }
        }

        return $manifests;
    }

    public function find(string $packageName): ?ModuleManifest
    {
        foreach ($this->discover() as $manifest) {
            if ($manifest->name === $packageName) {
                return $manifest;
            }
        }
        return null;
    }

    /**
     * Runs the module's install() hook once and marks it active. Idempotent
     * guard: refuses to re-run install() on an already-installed module —
     * call uninstall() first if you need to reset it.
     */
    public function install(string $packageName): void
    {
        $manifest = $this->requireManifest($packageName);

        if ($this->installs->isInstalled($packageName)) {
            throw new \RuntimeException("\"{$packageName}\" is already installed.");
        }

        if (! $this->isCompatible($manifest)) {
            throw new \RuntimeException(sprintf(
                '"%s" requires rhapsody-core %s, installed is %s.',
                $packageName,
                $manifest->coreConstraint,
                FrameworkInfo::getVersion(),
            ));
        }

        $provider = $this->resolveProvider($manifest);
        $context  = new ModuleContext($manifest, $this->container, $this->basePath);

        $provider->install($context);
        $this->installs->markInstalled($packageName);
    }

    /** Runs the module's uninstall() hook once and deactivates it. */
    public function uninstall(string $packageName): void
    {
        $manifest = $this->requireManifest($packageName);

        if (! $this->installs->isInstalled($packageName)) {
            throw new \RuntimeException("\"{$packageName}\" isn't installed.");
        }

        $provider = $this->resolveProvider($manifest);
        $context  = new ModuleContext($manifest, $this->container, $this->basePath);

        $provider->uninstall($context);
        $this->installs->markUninstalled($packageName);
    }

    private function requireManifest(string $packageName): ModuleManifest
    {
        $manifest = $this->find($packageName);
        if ($manifest === null) {
            throw new \RuntimeException(
                "No installed Composer package named \"{$packageName}\" declares itself as a rhapsody-module. " .
                'Run "composer require" first.'
            );
        }
        return $manifest;
    }

    private function resolveProvider(ModuleManifest $manifest): ModuleServiceProviderInterface
    {
        if (! class_exists($manifest->provider)) {
            throw new \RuntimeException("Provider class {$manifest->provider} not found for \"{$manifest->name}\".");
        }

        $provider = new ($manifest->provider)();
        if (! $provider instanceof ModuleServiceProviderInterface) {
            throw new \RuntimeException(
                "Provider for \"{$manifest->name}\" must implement ModuleServiceProviderInterface."
            );
        }

        return $provider;
    }

    private function bootOne(ModuleManifest $manifest): void
    {
        if (! $this->isCompatible($manifest)) {
            error_log(sprintf(
                'ModuleRegistry: skipping "%s" v%s — requires rhapsody-core %s, installed %s',
                $manifest->name,
                $manifest->version,
                $manifest->coreConstraint,
                FrameworkInfo::getVersion(),
            ));
            return;
        }

        try {
            $provider = $this->resolveProvider($manifest);
        } catch (\Throwable $e) {
            error_log("ModuleRegistry: skipping \"{$manifest->name}\" — " . $e->getMessage());
            return;
        }

        $context = new ModuleContext($manifest, $this->container, $this->basePath);

        try {
            $provider->boot($context);
            $this->booted[] = $manifest;
        } catch (\Throwable $e) {
            // Same philosophy as EventDispatcher::dispatch(): one broken
            // module shouldn't be able to take the whole site down.
            error_log("ModuleRegistry: \"{$manifest->name}\" threw during boot: " . $e->getMessage());
        }
    }

    private function isCompatible(ModuleManifest $manifest): bool
    {
        if (! class_exists(\Composer\Semver\Semver::class)) {
            return true; // composer/semver not available — don't block boot on a missing dev tool
        }

        // Skip the check for in-development core (see FrameworkInfo::getVersion()
        // dev-main fallback) rather than reject every module during local dev.
        $coreVersion = FrameworkInfo::getVersion();
        if (str_contains($coreVersion, 'dev')) {
            return true;
        }

        return \Composer\Semver\Semver::satisfies($coreVersion, $manifest->coreConstraint);
    }

    /** @return ModuleManifest[] modules that successfully booted this request */
    public function booted(): array
    {
        return $this->booted;
    }
}
