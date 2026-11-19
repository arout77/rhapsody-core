<?php
declare(strict_types=1);

namespace Rhapsody\Core\Theming;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Per-area theming: lets one installation serve different URL prefixes with
 * different themes (e.g. "/marketplace" => "cadence") while everything else
 * uses the configured default theme.
 *
 * Configure it in config/config.php:
 *
 *     'theme'       => Env::string('APP_THEME', 'default'),
 *     'theme_areas' => [
 *         '/marketplace' => 'cadence',
 *     ],
 *
 * Kernel::handle() calls apply() for every request, before routing, so the
 * choice is made per request and is also correct inside a persistent
 * (RoadRunner) worker where the Twig environment outlives a single request.
 */
final class AreaThemes
{
    /**
     * The theme the loader is currently configured for. Null means "still
     * the default theme bootstrap.php built the loader with".
     */
    private static ?string $applied = null;

    /**
     * Picks the theme for a request path. The longest matching prefix wins,
     * and a prefix only matches on a path-segment boundary ("/marketplace"
     * matches "/marketplace" and "/marketplace/item/x", not "/marketplaces").
     *
     * @param array<array-key, mixed> $areas prefix => theme name
     */
    public static function resolve(string $path, string $default, array $areas): string
    {
        $path = '/' . trim($path, '/');

        $best      = null;
        $bestLen   = -1;

        foreach ($areas as $prefix => $theme) {
            if (! is_string($prefix) || ! is_string($theme) || $theme === '') {
                continue;
            }

            $prefix = '/' . trim($prefix, '/');

            $matches = $prefix === '/'
                || $path === $prefix
                || str_starts_with($path, $prefix . '/');

            if ($matches && strlen($prefix) > $bestLen) {
                $best    = $theme;
                $bestLen = strlen($prefix);
            }
        }

        return $best ?? $default;
    }

    /**
     * Points the Twig loader at $theme (falling back to the default theme,
     * the app's views/ folder and core's views, same order bootstrap.php
     * uses). A no-op when the loader is already on that theme.
     *
     * @param array<string, mixed> $config
     */
    public static function apply(Environment $twig, string $basePath, string $path, array $config): void
    {
        $default = is_string($config['theme'] ?? null) ? $config['theme'] : 'default';
        $areas   = is_array($config['theme_areas'] ?? null) ? $config['theme_areas'] : [];

        if ($areas === []) {
            return;
        }

        $theme = self::resolve($path, $default, $areas);

        if ($theme === (self::$applied ?? $default)) {
            return;
        }

        $loader = $twig->getLoader();
        if (! $loader instanceof FilesystemLoader) {
            return;
        }

        $themePaths = [];
        foreach ([$theme, 'default'] as $name) {
            $dir = $basePath . '/views/themes/' . $name;
            if (is_dir($dir) && ! in_array($dir, $themePaths, true)) {
                $themePaths[] = $dir;
            }
        }

        // Unknown theme name: stay where we are rather than break the page.
        if (! is_dir($basePath . '/views/themes/' . $theme)) {
            return;
        }

        $corePath = $basePath . '/vendor/arout/rhapsody-core/resources/views/themes/default';
        if (! is_dir($corePath)) {
            $corePath = $basePath . '/vendor/arout/rhapsody-core/views/themes/default';
        }

        $paths = [...$themePaths, $basePath . '/views'];
        if (is_dir($corePath)) {
            $paths[] = $corePath;
            $loader->setPaths($paths, 'core');
        }

        // setPaths() also clears the loader's lookup caches, so templates
        // resolve against the new theme immediately.
        $loader->setPaths($paths);

        self::$applied = $theme;
    }
}
