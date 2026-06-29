<?php
namespace Rhapsody\Core\Storage;

/**
 * Helper to generate JavaScript snippets for managing localStorage.
 * All methods return a <script> tag that can be output in a view.
 */
final class LocalStorage
{
    /**
     * Generate a <script> tag that sets a localStorage item.
     */
    public static function set(string $key, mixed $value, bool $jsonEncode = true): string
    {
        $jsValue = $jsonEncode ? json_encode($value) : "'" . addslashes((string) $value) . "'";
        return self::wrapScript("localStorage.setItem('{$key}', {$jsValue});");
    }

    /**
     * Generate a <script> tag that gets a localStorage item and optionally calls a callback.
     */
    public static function get(string $key, ?string $callback = null): string
    {
        $js = "const value = localStorage.getItem('{$key}');";
        if ($callback) {
            $js .= "{$callback}(value);";
        }
        return self::wrapScript($js);
    }

    /**
     * Generate a <script> tag that removes a localStorage item.
     */
    public static function remove(string $key): string
    {
        return self::wrapScript("localStorage.removeItem('{$key}');");
    }

    /**
     * Generate a <script> tag that clears all localStorage.
     */
    public static function clear(): string
    {
        return self::wrapScript('localStorage.clear();');
    }

    /**
     * Wrap JavaScript in a <script> tag.
     */
    private static function wrapScript(string $js): string
    {
        return "<script>\n{$js}\n</script>";
    }
}
