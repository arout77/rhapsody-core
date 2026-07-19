<?php
namespace Rhapsody\Core\Services;

use Rhapsody\Core\Cache;
use Rhapsody\Core\FrameworkInfo;
use Rhapsody\Core\Response;

/**
 * Checks Packagist for a newer release of arout/rhapsody-core and, if one
 * is available, injects a dismissible-looking banner into HTML responses
 * (dev environments only — see Kernel::terminate()).
 *
 * Rhapsody is distributed via Packagist now (previously this checked a
 * GitHub releases API directly, before the package existed on Packagist),
 * so "is there an update" is answered the same way Composer itself would
 * answer it: by asking Packagist's package metadata endpoint what versions
 * exist for arout/rhapsody-core, and comparing that against the version
 * Composer actually installed (via FrameworkInfo::getVersion(), which reads
 * Composer\InstalledVersions — no hardcoded version string to keep in sync).
 *
 * This intentionally does NOT shell out to the `composer` binary: that's
 * slow (can take seconds), assumes composer is on PATH inside the web
 * server process, and running shell commands off of a web request is worth
 * avoiding when a plain HTTP call to Packagist's API does the same job in
 * milliseconds and can be cached.
 */
class NotificationService
{
    protected Cache $cache;
    protected string $packageName    = 'arout/rhapsody-core';
    protected string $metadataUrl;
    protected int $cacheDuration     = 720; // minutes (12 hours) — how long we trust a cached result
    protected float $requestTimeout  = 1.5; // seconds — never let a slow/offline Packagist delay a page load

    public function __construct(Cache $cache)
    {
        $this->cache       = $cache;
        $this->metadataUrl = "https://repo.packagist.org/p2/{$this->packageName}.json";
    }

    /**
     * Determine if a newer stable release is available on Packagist.
     * Returns update info if so, or null if up to date / offline / unknown.
     */
    public function getAvailableUpdate(): ?array
    {
        $cacheKey = 'rhapsody_latest_version_meta';

        if ($this->cache->has($cacheKey)) {
            $latest = $this->cache->get($cacheKey);
        } else {
            $latest = $this->fetchLatestVersionFromPackagist();
            // Cache the result either way (including null/"nothing found") so a
            // misconfigured or unreachable Packagist doesn't get hit every request.
            $this->cache->set($cacheKey, $latest, $this->cacheDuration);
        }

        if ($latest === null) {
            return null;
        }

        return $this->compareVersions($latest);
    }

    /**
     * Injects an update banner into an HTML response if a newer release exists.
     */
    public function injectBanner(Response $response): Response
    {
        try {
            $update = $this->getAvailableUpdate();
        } catch (\Throwable $e) {
            // Never let a broken update check break a real page load.
            return $response;
        }

        if (! $update) {
            return $response;
        }

        $content = $response->getContent();
        if (! str_contains($content, '</body>')) {
            return $response;
        }

        $bannerHtml       = $this->createBannerHtml($update);
        $modifiedContent  = str_ireplace('</body>', $bannerHtml . '</body>', $content);
        $response->setContent($modifiedContent);

        return $response;
    }

    /**
     * Queries Packagist's package metadata (the "p2" API — the same data
     * Composer itself consults) for the newest stable version of this package.
     */
    protected function fetchLatestVersionFromPackagist(): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $this->requestTimeout,
                'header'  => "User-Agent: Rhapsody-Framework/" . FrameworkInfo::getVersion() . "\r\n",
            ],
        ]);

        $payload = @file_get_contents($this->metadataUrl, false, $ctx);
        if ($payload === false) {
            return null; // Offline, rate-limited, or Packagist is down — fail silently.
        }

        $data = json_decode($payload, true);
        $versions = $data['packages'][$this->packageName] ?? null;
        if (empty($versions)) {
            return null;
        }

        $latest = null;
        foreach ($versions as $release) {
            $version = $release['version'] ?? null;
            if (! $version || $this->isUnstableOrBranch($version)) {
                continue;
            }
            if ($latest === null || version_compare($this->stripVersionPrefix($version), $this->stripVersionPrefix($latest), '>')) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * Filters out branch aliases (dev-main, dev-master, 1.x-dev, ...) and
     * pre-release tags (alpha/beta/RC) — we only want to notify about
     * genuinely newer stable releases.
     */
    protected function isUnstableOrBranch(string $version): bool
    {
        return str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev')
            || (bool) preg_match('/-(alpha|beta|rc)/i', $version);
    }

    protected function compareVersions(string $latest): ?array
    {
        $current = $this->getCurrentVersion();

        // A dev/branch install (e.g. "dev-main") has nothing meaningful to
        // compare against a tagged release — skip rather than false-alarm.
        if ($this->isUnstableOrBranch($current)) {
            return null;
        }

        // PHP's version_compare() gives wrong results when one side has a
        // leading "v" and the other doesn't (e.g. "1.10.6" vs "v1.11.0"
        // incorrectly compares as NOT less-than) — normalize both sides
        // before comparing, but keep the original strings for display.
        if (version_compare($this->stripVersionPrefix($current), $this->stripVersionPrefix($latest), '<')) {
            return [
                'current' => $current,
                'latest'  => $latest,
                'url'     => "https://packagist.org/packages/{$this->packageName}#{$latest}",
            ];
        }

        return null;
    }

    protected function stripVersionPrefix(string $version): string
    {
        return ltrim($version, 'vV');
    }

    /**
     * Wraps FrameworkInfo::getVersion() (Composer\InstalledVersions under the
     * hood) so it can be overridden in tests without mocking a static call.
     */
    protected function getCurrentVersion(): string
    {
        return FrameworkInfo::getVersion();
    }

    private function createBannerHtml(array $update): string
    {
        $latest  = htmlspecialchars($update['latest'], ENT_QUOTES);
        $current = htmlspecialchars($update['current'], ENT_QUOTES);
        $url     = htmlspecialchars($update['url'], ENT_QUOTES);

        return <<<HTML
            <div id="rhapsody-update-banner" style="position: fixed; bottom: 0; left: 0; width: 100%; background-color: #1F2937; color: #F9FAFB; padding: 12px; z-index: 99999; display: flex; justify-content: center; align-items: center; font-family: sans-serif; font-size: 14px; border-top: 1px solid #4B5563;">
                <span style="font-weight: bold; font-size: 10px; padding: 2px 8px; border-radius: 9999px; margin-right: 12px; background-color: #BE185D; color: #fff;">UPDATE</span>
                A new version of Rhapsody is available: <strong>&nbsp;{$latest}</strong>&nbsp;(you're on {$current})
                <a href="{$url}" target="_blank" rel="noopener" style="color: #60A5FA; font-weight: bold; text-decoration: underline; margin-left: 16px;">View on Packagist</a>
            </div>
        HTML;
    }
}
