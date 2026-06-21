<?php
namespace Rhapsody\Core\Helpers;

class Path
{
    /**
     * Get the absolute path to the application root.
     */
    public static function root(string $path = ''): string
    {
        // Fallback assumes this file is in vendor/arout77/rhapsody-core/src/Helpers/
        $base = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 4);

        // Normalize any forward or backward slashes to the OS's native separator
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return rtrim($base . DIRECTORY_SEPARATOR . ltrim($normalizedPath, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }

    /**
     * Get the absolute path to the storage directory.
     */
    public static function storage(string $path = ''): string
    {
        return self::root('storage/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the views directory.
     */
    public static function views(string $path = ''): string
    {
        return self::root('resources/views/' . ltrim($path, '/\\'));
    }
}
