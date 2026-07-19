<?php
namespace Rhapsody\Core;

use Composer\InstalledVersions;

/**
 * Framework identity/version metadata.
 *
 * This used to live on Kernel, but "what version am I" and "handle this
 * request" are unrelated responsibilities — Kernel now owns the request
 * lifecycle (see Kernel::handle()/terminate()).
 */
class FrameworkInfo
{
    protected const PACKAGE_NAME = 'arout/rhapsody-core';

    public static function getVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return '1.0.0-dev';
        }

        $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);

        // Installing from a VCS branch (e.g. `dev-main`, common during active
        // framework development) makes Composer report "dev-main" rather than
        // a real version — Composer intentionally ignores a package's
        // self-declared "version" field for branch installs, trusting only
        // tags. That leaves "dev-main" with nothing meaningful to compare
        // against a Packagist release, so fall back to reading the version
        // the framework's own release process declared in its composer.json.
        if ($version === null || self::isDevVersion($version)) {
            $declared = self::readDeclaredVersion($version);
            if ($declared !== null) {
                return $declared;
            }
        }

        return $version ?? '1.0.0-dev';
    }

    protected static function isDevVersion(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }

    /**
     * Resolves a dev/branch install (e.g. "dev-main") to something meaningful
     * to display, using the installed package's own composer.json:
     *
     *   1. `extra.branch-alias` for the exact branch (e.g. "dev-main" =>
     *      "1.11.x-dev") — the standard Composer mechanism for a package to
     *      declare "this branch represents upcoming version X". Composer's
     *      own dependency resolver understands this too, so it's not just
     *      for display: a consumer requiring "^1.11" can resolve against
     *      this branch once it's aliased.
     *   2. A plain top-level "version" field, if no alias is declared for
     *      this branch. Less ideal (Composer's own docs recommend against
     *      hand-maintaining this, since it can drift from reality — see the
     *      "1.10.6" vs "1.11.x-dev" case this fallback chain exists for),
     *      but better than nothing.
     */
    protected static function readDeclaredVersion(?string $installedBranch = null): ?string
    {
        $installPath = InstalledVersions::getInstallPath(self::PACKAGE_NAME);
        if (! $installPath) {
            return null;
        }

        $composerJsonPath = rtrim($installPath, '/\\') . '/composer.json';
        if (! is_readable($composerJsonPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJsonPath), true);

        if ($installedBranch !== null) {
            $alias = $data['extra']['branch-alias'][$installedBranch] ?? null;
            if ($alias && is_string($alias)) {
                return $alias;
            }
        }

        $version = $data['version'] ?? null;
        if (! $version || ! is_string($version)) {
            return null;
        }

        // Normalize to a consistent "vX.Y.Z" display format.
        return preg_match('/^v/i', $version) ? $version : 'v' . $version;
    }
}
