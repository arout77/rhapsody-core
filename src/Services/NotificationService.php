<?php
namespace Rhapsody\Core\Services;

use Rhapsody\Core\Cache\CacheInterface;
use Rhapsody\Core\FrameworkInfo;

class NotificationService
{
    protected CacheInterface $cache;
    protected string $updateUrl  = 'https://iwf-wrestling.com/rhapsody/v1/updates';
    protected int $cacheDuration = 86400; // 24-hour gatekeeper window

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Determine if a framework core update is available.
     * Returns update payload array if true, or null if up to date/offline.
     */
    public function getAvailableUpdate(): ?array
    {
        // 1. Check local cache baseline to avoid hitting the external network on every request
        if ($this->cache->has('rhapsody_latest_version_meta')) {
            $latestData = $this->cache->get('rhapsody_latest_version_meta');
            return $this->compareVersions($latestData);
        }

        // 2. Cache missing or expired; fetch safely from the remote server
        try {
            $latestData = $this->fetchRemoteMetaData();

            if ($latestData) {
                $this->cache->set('rhapsody_latest_version_meta', $latestData, $this->cacheDuration);
                return $this->compareVersions($latestData);
            }
        } catch (\Throwable $e) {
            // Fail silently. Never crash a consumer's application because your update server is down.
            return null;
        }

        return null;
    }

    protected function fetchRemoteMetaData(): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 1.5, // Aggressive low timeout to preserve user load speeds
                'header'  => "User-Agent: RhapsodyKernel/" . FrameworkInfo::getVersion() . "\r\n",
            ],
        ]);

        $payload = @file_get_contents($this->updateUrl, false, $ctx);
        if ($payload === false) {
            return null;
        }

        return json_decode($payload, true);
    }

    protected function compareVersions(array $remoteData): ?array
    {
        $current = FrameworkInfo::getVersion();
        $latest  = $remoteData['version'] ?? '1.0.0';

        // standard semantic version matching natively managed by PHP
        if (version_compare($current, $latest, '<')) {
            return [
                'current'       => $current,
                'latest'        => $latest,
                'changelog_url' => $remoteData['changelog_url'] ?? '',
                'is_critical'   => $remoteData['security_patch'] ?? false,
            ];
        }

        return null;
    }
}
