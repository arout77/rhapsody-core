<?php
namespace Rhapsody\Core\Services;

use Rhapsody\Core\Cache;

class RateLimiter
{
    protected Cache $cache;
    protected array $config;

    public function __construct(Cache $cache, array $config)
    {
        $this->cache  = $cache;
        $this->config = $config;
    }

    public function attempt(string $key, int $max, int $window, int $blockDuration): array
    {
        $cacheKey  = 'rate_limit:' . $key;
        $expiryKey = $cacheKey . ':block_expiry';

        // 1. Check if there is a block expiry timestamp (stored without TTL)
        $expiry = $this->cache->get($expiryKey);

        if ($expiry !== null && time() < $expiry) {
            // Still blocked
            return [
                'allowed'     => false,
                'retry_after' => $expiry - time(),
                'remaining'   => 0,
            ];
        }

        // If block expired, remove the key (using forget)
        if ($expiry !== null) {
            $this->cache->forget($expiryKey);
        }

        // 2. Get current request count (this key uses TTL to auto-reset)
        $count = $this->cache->get($cacheKey, 0);

        if ($count >= $max) {
            // Exceeded – set block expiry (store timestamp, no TTL)
            $blockUntil = time() + $blockDuration;
                                                              // Store without TTL – we use a long TTL (e.g., 1 day) or just store without expiry.
                                                              // Since we don't want it to expire automatically, we can use a very long TTL.
                                                              // But better: use put with a long TTL (e.g., 86400 seconds = 1 day) or just store value without TTL?
                                                              // The CacheInterface requires $minutes; we can set to 1440 (1 day) to be safe.
            $this->cache->put($expiryKey, $blockUntil, 1440); // 1 day – enough to cover any block

            return [
                'allowed'     => false,
                'retry_after' => $blockDuration,
                'remaining'   => 0,
            ];
        }

        // 3. Increment counter (with TTL to auto-reset after window)
        $newCount = $count + 1;
        // convert seconds to minutes (ceil)
        $minutes = (int) ceil($window / 60);
        if ($minutes < 1) {
            $minutes = 1;
        }
        // at least 1 minute
        $this->cache->put($cacheKey, $newCount, $minutes);

        return [
            'allowed'     => true,
            'retry_after' => 0,
            'remaining'   => $max - $newCount,
        ];
    }
}
