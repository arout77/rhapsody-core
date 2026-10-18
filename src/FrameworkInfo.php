<?php
namespace Rhapsody\Core;

use Composer\InstalledVersions;

/**
 * Framework identity/version metadata.
 *
 * This used to live on Kernel, but "what version am I" and "handle this
 * request" are unrelated responsibilities — Kernel now owns the request
 * lifecycle (see Kernel::handle()/terminate()).
 *
 * Two views of the version are exposed, because they answer different
 * questions:
 *
 *   - getVersion():     the released version, for display (dev toolbar,
 *                       $config['app_version']). e.g. "1.0.0"
 *   - getDocsVersion(): the version line the code is actually on, for the
 *                       versioned documentation. e.g. "1.1.x-dev" on a
 *                       branch install, or "1.0.0" on a tagged install.
 *
 * Both are returned without a leading "v".
 */
class FrameworkInfo
{
    protected const string PACKAGE_NAME = 'arout/rhapsody-core';

    protected const string FALLBACK_VERSION = '1.0.0-dev';

    /**
     * Decoded composer.json of the installed package, memoized so a dev
     * install never reads/decodes it more than once per request.
     *
     * @var array<array-key, mixed>|null
     */
    protected static ?array $composerData = null;

    /**
     * The released version, e.g. "1.0.0".
     *
     * Resolution order:
     *   1. A tagged install: Composer reports the tag, so use it.
     *   2. A branch install (e.g. `dev-main`): Composer only reports the
     *      branch, so use the `version` field declared in the framework's own
     *      composer.json (kept in step with the last tag at release time).
     *   3. The branch alias for this branch (e.g. "1.1.x-dev").
     *   4. A hard fallback.
     */
    public static function getVersion(): string
    {
        $installed = self::getInstalledVersion();

        if ($installed !== null && ! self::isDevVersion($installed)) {
            return self::normalize($installed);
        }

        $declared = self::readDeclaredVersion();
        if ($declared !== null) {
            return self::normalize($declared);
        }

        if ($installed !== null) {
            $alias = self::readBranchAlias($installed);
            if ($alias !== null) {
                return self::normalize($alias);
            }
        }

        return self::FALLBACK_VERSION;
    }

    /**
     * The version line this code is on, for documentation purposes.
     *
     * On a branch install this is the branch alias ("1.1.x-dev"), which tells
     * the docs the build is unreleased and which release it is heading
     * towards. On a tagged install it is simply the released version.
     */
    public static function getDocsVersion(): string
    {
        $installed = self::getInstalledVersion();

        if ($installed !== null && self::isDevVersion($installed)) {
            // Already a meaningful version line, e.g. required as "1.1.x-dev".
            if (! str_starts_with($installed, 'dev-')) {
                return self::normalize($installed);
            }

            $alias = self::readBranchAlias($installed);
            if ($alias !== null) {
                return self::normalize($alias);
            }
        }

        return self::getVersion();
    }

    protected static function getInstalledVersion(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        } catch (\OutOfBoundsException) {
            return null;
        }
    }

    protected static function isDevVersion(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }

    /**
     * Strips a leading "v" so every version is displayed consistently.
     */
    protected static function normalize(string $version): string
    {
        return ltrim($version, 'vV');
    }

    /**
     * The installed package's own composer.json, decoded once.
     *
     * @return array<array-key, mixed>
     */
    protected static function readComposerData(): array
    {
        if (self::$composerData !== null) {
            return self::$composerData;
        }

        self::$composerData = [];

        if (! class_exists(InstalledVersions::class)) {
            return self::$composerData;
        }

        try {
            $installPath = InstalledVersions::getInstallPath(self::PACKAGE_NAME);
        } catch (\OutOfBoundsException) {
            return self::$composerData;
        }

        if (! $installPath) {
            return self::$composerData;
        }

        $composerJsonPath = rtrim($installPath, '/\\') . '/composer.json';
        if (! is_readable($composerJsonPath)) {
            return self::$composerData;
        }

        // Deliberately non-throwing: a version lookup must never take down
        // bootstrap, so an unreadable file just means "no declared version".
        $contents = file_get_contents($composerJsonPath);
        if ($contents === false) {
            return self::$composerData;
        }

        $data = json_decode($contents, true);
        if (is_array($data)) {
            self::$composerData = $data;
        }

        return self::$composerData;
    }

    /**
     * The plain top-level "version" field of the framework's composer.json.
     *
     * Composer's own docs recommend against hand-maintaining this field (it
     * can drift from the real tag), so it must be bumped as part of the
     * release process. It is only consulted for branch installs.
     */
    protected static function readDeclaredVersion(): ?string
    {
        $version = self::readComposerData()['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * `extra.branch-alias` for the installed branch (e.g. "dev-main" =>
     * "1.1.x-dev") — the standard Composer mechanism for a package to
     * declare "this branch represents upcoming version X".
     */
    protected static function readBranchAlias(string $installedBranch): ?string
    {
        $extra   = self::readComposerData()['extra'] ?? null;
        $aliases = is_array($extra) ? ($extra['branch-alias'] ?? null) : null;
        $alias   = is_array($aliases) ? ($aliases[$installedBranch] ?? null) : null;

        return is_string($alias) && $alias !== '' ? $alias : null;
    }
}
