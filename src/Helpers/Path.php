<?php
namespace Rhapsody\Core\Helpers;

class Path
{
    /**
     * Get the absolute path to the application root.
     */
    public static function root(string $path = ''): string
    {
        // Use ROOT_DIR if defined (set in index.php)
        if (defined('ROOT_DIR')) {
            $base = ROOT_DIR;
        } else {
            // Fallback: assume this file is in vendor/arout77/rhapsody-core/src/Helpers/
            // Go up 4 levels to reach the project root.
            $base = dirname(__DIR__, 4);
        }

        // Normalize any forward or backward slashes to the OS's native separator
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return rtrim($base . DIRECTORY_SEPARATOR . ltrim($normalizedPath, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }

    /**
     * Get the absolute path to the core application source directory (usually 'app').
     */
    public static function app(string $path = ''): string
    {
        return self::root('app/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the configuration directory.
     */
    public static function config(string $path = ''): string
    {
        return self::root('config/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the public web root directory.
     */
    public static function public(string $path = ''): string
    {
        return self::root('public/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the storage directory.
     */
    public static function storage(string $path = ''): string
    {
        return self::root('storage/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the framework or app cache directory.
     */
    public static function cache(string $path = ''): string
    {
        return self::storage('framework/cache/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the application logs directory.
     */
    public static function logs(string $path = ''): string
    {
        return self::storage('logs/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the resources directory.
     */
    public static function resources(string $path = ''): string
    {
        return self::root('resources/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the views directory.
     */
    public static function views(string $path = ''): string
    {
        return self::resources('views/' . ltrim($path, '/\\'));
    }

    /**
     * Get the absolute path to the database directory (migrations, seeds, etc.).
     */
    public static function database(string $path = ''): string
    {
        return self::root('db/' . ltrim($path, '/\\'));
    }
}
