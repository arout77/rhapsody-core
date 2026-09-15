<?php

namespace Rhapsody\Core\Modules;

use Composer\InstalledVersions;
use Rhapsody\Core\Cache;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Events\EventDispatcher;
use Rhapsody\Core\Events\ModuleBootFailed;
use Rhapsody\Core\Events\ModuleHealthCheckFailed;
use Rhapsody\Core\FrameworkInfo;
use Rhapsody\Core\Modules\Contracts\ModuleHealthCheckInterface;
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

    /** @var ModuleBootFailed[] every failure recorded so far this request */
    private array $failures = [];

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
            $message = sprintf(
                'requires rhapsody-core %s, installed %s',
                $manifest->coreConstraint,
                FrameworkInfo::getVersion(),
            );
            error_log(sprintf(
                'ModuleRegistry: skipping "%s" v%s — %s',
                $manifest->name,
                $manifest->version,
                $message,
            ));
            $this->recordFailure($manifest, ModuleBootFailed::PHASE_COMPATIBILITY, $message);
            return;
        }

        try {
            $provider = $this->resolveProvider($manifest);
        } catch (\Throwable $e) {
            error_log("ModuleRegistry: skipping \"{$manifest->name}\" — " . $e->getMessage());
            $this->recordFailure($manifest, ModuleBootFailed::PHASE_PROVIDER, $e->getMessage());
            return;
        }

        $context = new ModuleContext($manifest, $this->container, $this->basePath);

        if ($manifest->settingsSchema) {
            $errors = ModuleSettingsValidator::validate($manifest->settingsSchema, $context->settings()->all());
            if ($errors) {
                $message = implode('; ', $errors);
                error_log("ModuleRegistry: \"{$manifest->name}\" failed settings validation: {$message}");
                $this->recordFailure($manifest, ModuleBootFailed::PHASE_SETTINGS_VALIDATION, $message);
                return; // config is broken — don't call boot() against settings we know are bad
            }
        }

        try {
            $provider->boot($context);
            $this->booted[] = $manifest;
            $this->clearAlert($manifest);
        } catch (\Throwable $e) {
            // Same philosophy as EventDispatcher::dispatch(): one broken
            // module shouldn't be able to take the whole site down.
            error_log("ModuleRegistry: \"{$manifest->name}\" threw during boot: " . $e->getMessage());
            $this->recordFailure($manifest, ModuleBootFailed::PHASE_BOOT, $e->getMessage());
        }
    }

    /**
     * Records the failure locally (see failures()) and dispatches
     * ModuleBootFailed so anything listening — e.g. NotifyDevsOfModuleFailure
     * — can alert someone. Dispatch itself is defensive: EventDispatcher
     * already catches per-listener throwables, but resolving it at all
     * requires a working container, and a module failure is exactly the
     * kind of moment something else nearby might also be unhappy.
     */
    private function recordFailure(ModuleManifest $manifest, string $phase, string $message): void
    {
        $event            = new ModuleBootFailed($manifest, $phase, $message);
        $this->failures[] = $event;

        try {
            $this->container->resolve(EventDispatcher::class)->dispatch($event);
        } catch (\Throwable $e) {
            error_log('ModuleRegistry: could not dispatch ModuleBootFailed: ' . $e->getMessage());
        }
    }

    /**
     * Clears the alert dedupe key on a successful boot, so a module that
     * breaks again later (for an unrelated reason) re-alerts immediately
     * instead of staying quiet for the rest of the original 24h window.
     */
    private function clearAlert(ModuleManifest $manifest): void
    {
        try {
            $this->container->resolve(Cache::class)->forget(ModuleBootFailed::cacheKeyFor($manifest->slug()));
        } catch (\Throwable $e) {
            error_log('ModuleRegistry: could not clear alert dedupe key: ' . $e->getMessage());
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

    /** @return ModuleBootFailed[] every failure recorded this request, in the order they occurred */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Runs healthCheck() on every module that (a) successfully booted this
     * request and (b) implements ModuleHealthCheckInterface. Deliberately
     * skips modules that failed to boot — failures() already covers those,
     * and calling healthCheck() on a module whose boot() never ran means
     * whatever it wired up (routes, listeners, clients) never got wired,
     * so a live check on it would test nothing meaningful.
     *
     * Creates a fresh provider instance to call healthCheck() on, same as
     * install()/uninstall() already do independently of boot()'s own
     * instance — this only works because providers are expected to be
     * stateless (see ModuleServiceProviderInterface).
     *
     * @return array<string, array<string, array{ok: bool, message: string}>>
     *         Keyed by module name, then by check name. Modules with no
     *         checks to report (either they don't implement the interface,
     *         or they returned an empty array) are simply absent — not
     *         listed as empty.
     */
    public function checkHealth(): array
    {
        $results = [];

        foreach ($this->booted as $manifest) {
            try {
                $provider = $this->resolveProvider($manifest);
            } catch (\Throwable $e) {
                // Booted fine moments ago; a resolve failure now would be
                // bizarre (e.g. the class was deleted mid-request). Not
                // this method's job to report — failures() is.
                continue;
            }

            if (! $provider instanceof ModuleHealthCheckInterface) {
                continue;
            }

            $context = new ModuleContext($manifest, $this->container, $this->basePath);

            try {
                $checks = $provider->healthCheck($context);
            } catch (\Throwable $e) {
                $checks = [
                    'healthCheck' => ['ok' => false, 'message' => 'healthCheck() itself threw: ' . $e->getMessage()],
                ];
            }

            $normalized = self::normalizeChecks($checks);

            foreach ($normalized as $checkName => $result) {
                if ($result['ok']) {
                    $this->clearHealthAlert($manifest, $checkName);
                } else {
                    $this->recordHealthFailure($manifest, $checkName, $result['message']);
                }
            }

            if ($normalized !== []) {
                $results[$manifest->name] = $normalized;
            }
        }

        return $results;
    }

    /**
     * Dispatches ModuleHealthCheckFailed so anything listening — e.g.
     * NotifyDevsOfModuleHealthFailure — can alert someone. Lives here
     * (rather than in the CLI command) so any future caller of
     * checkHealth() — an admin health page, a future HTTP endpoint —
     * gets the same alerting for free, matching how recordFailure()
     * already works for boot failures.
     */
    private function recordHealthFailure(ModuleManifest $manifest, string $checkName, string $message): void
    {
        try {
            $this->container->resolve(EventDispatcher::class)->dispatch(
                new ModuleHealthCheckFailed($manifest, $checkName, $message),
            );
        } catch (\Throwable $e) {
            error_log('ModuleRegistry: could not dispatch ModuleHealthCheckFailed: ' . $e->getMessage());
        }
    }

    /** Clears a specific check's alert dedupe key once it passes again. */
    private function clearHealthAlert(ModuleManifest $manifest, string $checkName): void
    {
        try {
            $this->container->resolve(Cache::class)->forget(
                ModuleHealthCheckFailed::cacheKeyFor($manifest->slug(), $checkName),
            );
        } catch (\Throwable $e) {
            error_log('ModuleRegistry: could not clear health alert dedupe key: ' . $e->getMessage());
        }
    }

    /**
     * Defensively coerces whatever a module's healthCheck() returned into
     * the documented shape — same trust posture as the rest of this class
     * toward module code (see ModuleManifest, ModulePermissions): a
     * malformed return value degrades to a clear "ok: false" rather than
     * crashing whoever called checkHealth().
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    private static function normalizeChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            return ['healthCheck' => ['ok' => false, 'message' => 'healthCheck() did not return an array']];
        }

        $normalized = [];
        foreach ($checks as $name => $result) {
            $name              = is_string($name) && $name !== '' ? $name : 'check';
            $normalized[$name] = [
                'ok'      => is_array($result) && ($result['ok'] ?? false) === true,
                'message' => is_array($result) && isset($result['message']) ? (string) $result['message'] : '',
            ];
        }

        return $normalized;
    }
}
