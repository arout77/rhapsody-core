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
        $jsKey   = json_encode($key);
        $jsValue = $jsonEncode
            ? json_encode($value)
            // Preserve the original single-quoted output style for the
            // non-JSON path, but escape '/' the same way json_encode does
            // by default — addslashes() alone does NOT escape '</script>',
            // which would otherwise let $value close the wrapping <script>
            // tag early and inject arbitrary markup/script.
            : "'" . str_replace('/', '\/', addslashes((string) $value)) . "'";

        return self::wrapScript("localStorage.setItem({$jsKey}, {$jsValue});");
    }

    /**
     * Generate a <script> tag that gets a localStorage item and optionally calls a callback.
     *
     * @param string $key
     * @param string|null $callback A JS identifier (or dotted path, e.g.
     *   'myApp.handlers.onValue') to call with the retrieved value. Must be
     *   a plain identifier/path — not an arbitrary expression — since it's
     *   emitted as a literal function call, not a data value.
     * @throws \InvalidArgumentException if $callback isn't a safe identifier path.
     */
    public static function get(string $key, ?string $callback = null): string
    {
        $jsKey = json_encode($key);
        $js    = "const value = localStorage.getItem({$jsKey});";

        if ($callback !== null) {
            if (! preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $callback)) {
                throw new \InvalidArgumentException(
                    "Invalid LocalStorage callback \"{$callback}\": must be a plain JS identifier " .
                    "or dotted path (e.g. 'onValue' or 'myApp.handlers.onValue')."
                );
            }
            $js .= "{$callback}(value);";
        }

        return self::wrapScript($js);
    }

    /**
     * Generate a <script> tag that removes a localStorage item.
     */
    public static function remove(string $key): string
    {
        $jsKey = json_encode($key);
        return self::wrapScript("localStorage.removeItem({$jsKey});");
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
