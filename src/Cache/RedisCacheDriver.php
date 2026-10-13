<?php
namespace Rhapsody\Core\Cache;

use Predis\Client as RedisClient;

class RedisCacheDriver implements CacheInterface
{
    /**
     * @param RedisClient $redis
     */
    public function __construct(protected RedisClient $redis)
    {}
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($key);
        if ($value === null) {
            return $default;
        }
        $decoded = json_decode($value, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    /**
     * @throws \JsonException If the value cannot be JSON-encoded.
     */
    public function put(string $key, mixed $value, int $minutes): void
    {
        $this->redis->setex($key, $minutes * 60, json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $key
     */
    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    /**
     * @param string $key
     */
    public function forget(string $key): void
    {
        $this->redis->del($key);
    }

    public function flush(): bool
    {
        $this->redis->flushdb();
        return true;
    }
}
