<?php
namespace Rhapsody\Core\Cache;

use Rhapsody\Core\Helpers\Path;

class FileCacheDriver implements CacheInterface
{
    protected string $cachePath;

    public function __construct()
    {
        // Dynamically resolve the absolute path to the downstream app's storage folder
        $this->cachePath = Path::storage('cache/app') . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->cachePath . md5($key);
        if (! file_exists($path)) {
            return $default;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $default;
        }

        $data = unserialize($content);
        if (! is_array($data) || ! isset($data['expires'])) {
            return $default;
        }

        if (time() > (int) $data['expires']) {
            unlink($path);
            return $default;
        }

        return $data['value'] ?? $default;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $minutes
     */
    public function put(string $key, mixed $value, int $minutes): void
    {
        if (! is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }

        $data = [
            'value'   => $value,
            'expires' => time() + ($minutes * 60),
        ];

        file_put_contents($this->cachePath . md5($key), serialize($data));
    }

    /**
     * Checks for key existence and expiry directly.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $path = $this->cachePath . md5($key);
        if (! file_exists($path)) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $data = unserialize($content);
        if (! is_array($data) || ! isset($data['expires'])) {
            return false;
        }

        if (time() > (int) $data['expires']) {
            unlink($path);
            return false;
        }

        return true;
    }

    /**
     * @param string $key
     */
    public function forget(string $key): void
    {
        $path = $this->cachePath . md5($key);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Delete all cached entries by clearing all files in the cache directory.
     */
    public function flush(): bool
    {
        if (! is_dir($this->cachePath)) {
            return false;
        }

        $files = glob($this->cachePath . '*');
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }
}
