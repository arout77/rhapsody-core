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
    /**
     * @param string $key
     * @param $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->redis->get($key);
        if ($value === null || $value === false) {
            return $default;
        }
        $decoded = json_decode($value, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    /**
     * @param string $key
     * @param $value
     * @param int $minutes
     */
    public function put(string $key, $value, int $minutes): void
    {
        $this->redis->setex($key, $minutes * 60, json_encode($value));
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
