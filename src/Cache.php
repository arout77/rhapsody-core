<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Cache\CacheInterface;

class Cache
{
    private static ?self $instance = null;
    private static int $hits       = 0;
    private static int $misses     = 0;

    /**
     * @param CacheInterface $driver
     */
    public function __construct(protected CacheInterface $driver)
    {}

    /**
     * Stores the resolved Cache instance for static access.
     * Called once during bootstrapping.
     */
    public static function setInstance(self $cache): void
    {
        self::$instance = $cache;
    }

    /**
     * Returns the shared Cache instance.
     * Mirrors the Database::getInstance() pattern.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Cache has not been initialised. Call Cache::setInstance() during bootstrapping.');
        }
        return self::$instance;
    }

    /**
     * Reset hit/miss counters (called at the start of each request).
     */
    public static function resetStats(): void
    {
        self::$hits   = 0;
        self::$misses = 0;
    }

    /**
     * @param string $key
     * @param $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->driver->get($key, $default);

        // Track the hit or miss
        if ($value !== $default) {
            self::$hits++;
        } else {
            self::$misses++;
        }

        return $value;
    }

    public static function getStats(): array
    {
        return [
            'hits'   => self::$hits,
            'misses' => self::$misses,
            'ratio'  => (self::$hits + self::$misses) > 0
                ? round(self::$hits / (self::$hits + self::$misses) * 100, 2)
                : 0,
        ];
    }

    /**
     * @param string $key
     * @param $value
     * @param int $minutes
     */
    public function put(string $key, $value, int $minutes): void
    {
        $this->driver->put($key, $value, $minutes);
    }

    /**
     * Alias for put()
     */
    public function set(string $key, $value, int $minutes): void
    {
        $this->put($key, $value, $minutes);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    /**
     * @param string $key
     */
    public function forget(string $key): void
    {
        $this->driver->forget($key);
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return $this->driver->flush();
    }

    /**
     * @param string $key
     * @param int $minutes
     * @param callable $callback
     * @return mixed
     */
    public function remember(string $key, int $minutes, callable $callback)
    {
        if ($this->has($key)) {
            // Hit – increment hits and return value
            self::$hits++;
            return $this->driver->get($key);
        }

        // Miss – increment misses, compute value, store it, and return
        self::$misses++;
        $value = $callback();
        $this->put($key, $value, $minutes);
        return $value;
    }
}
