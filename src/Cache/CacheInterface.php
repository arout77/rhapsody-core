<?php
namespace Rhapsody\Core\Cache;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $minutes): void;

    public function has(string $key): bool;
    public function forget(string $key): void;
    public function flush(): bool;
}
