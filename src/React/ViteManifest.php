<?php
namespace Rhapsody\Core\React;

use Rhapsody\Core\Helpers\Path;

/**
 * ViteManifest
 *
 * Bridges the PHP backend to Vite's asset pipeline.
 *
 * In development (VITE_DEV_SERVER=true) all assets are served by the Vite
 * dev server with full HMR support.  In production, asset URLs are resolved
 * from the manifest.json Vite writes into public/build/.
 */
class ViteManifest
{
    /** @var array<string, mixed>|null Cached manifest data */
    private static ?array $manifest = null;

    /**
     * Emit the correct <script> / <link> tags for an entry-point.
     *
     * Usage in BaseController::react():
     *   ViteManifest::tags('resources/js/app.jsx')
     *
     * @param string $entry Relative path used as the Vite entry (e.g. "resources/js/app.jsx")
     * @return string Raw HTML — safe to emit with |raw in Twig or echo directly.
     */
    public static function tags(string $entry): string
    {
        if (self::isDevMode()) {
            return self::devTags($entry);
        }

        return self::productionTags($entry);
    }

    /**
     * Resolve the public URL of a single asset in production.
     *
     * @param string $entry The entry key as it appears in manifest.json.
     * @return string Absolute public path (e.g. "/build/assets/app-DsRmGxyz.js")
     * @throws \RuntimeException if the manifest or the entry cannot be found.
     */
    public static function asset(string $entry): string
    {
        $manifest = self::load();

        if (! isset($manifest[$entry])) {
            throw new \RuntimeException(
                "[Rhapsody/React] Vite manifest entry '{$entry}' not found. " .
                "Run 'npm run build' to generate it."
            );
        }

        return self::getBasePath() . '/public/build/' . $manifest[$entry]['file'];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function devTags(string $entry): string
    {
        $host = (string) (self::env('VITE_HOST') ?: 'localhost');
        $port = (int) (self::env('VITE_PORT') ?: 5173);
        $base = 'http://' . $host . ':' . $port;

        // @vitejs/plugin-react normally injects this preamble via transformIndexHtml,
        // but that hook only runs on Vite-served HTML. Since PHP generates our HTML,
        // we inject it manually — exactly how Laravel's Vite integration handles this.
        $preamble = <<<HTML
    <script type="module">
        import RefreshRuntime from "{$base}/@react-refresh"
        RefreshRuntime.injectIntoGlobalHook(window)
        window.\$RefreshReg$ = () => {}
        window.\$RefreshSig$ = () => (type) => type
        window.__vite_plugin_react_preamble_installed__ = true
    </script>
    HTML;

        $client = '<script type="module" src="' . $base . '/@vite/client"></script>';
        $app    = '<script type="module" src="' . $base . '/' . ltrim($entry, '/') . '"></script>';

        return $preamble . "\n    " . $client . "\n    " . $app;
    }

    private static function productionTags(string $entry): string
    {
        $manifest = self::load();

        if (! isset($manifest[$entry])) {
            throw new \RuntimeException(
                "[Rhapsody/React] Vite manifest entry '{$entry}' not found. " .
                "Run 'npm run build' or check your entry path."
            );
        }

        $chunk    = $manifest[$entry];
        $tags     = '';
        $basePath = Path::root();

        // CSS files generated for this entry
        foreach ($chunk['css'] ?? [] as $css) {
            $url   = htmlspecialchars($basePath . '/public/build/' . $css, ENT_QUOTES, 'UTF-8');
            $tags .= '<link rel="stylesheet" href="' . $url . '">' . "\n    ";
        }

        // Import-mapped preload hints for better LCP
        foreach ($chunk['imports'] ?? [] as $importKey) {
            if (isset($manifest[$importKey]['file'])) {
                $url   = htmlspecialchars($_ENV['APP_URL'] . $_ENV['APP_BASE_URL'] . '/public/build/' . $manifest[$importKey]['file'], ENT_QUOTES, 'UTF-8');
                $tags .= '<link rel="modulepreload" href="' . $url . '">' . "\n    ";
            }
        }

        // The main entry script
        $src  = htmlspecialchars($_ENV['APP_URL'] . $_ENV['APP_BASE_URL'] . '/public/build/' . $chunk['file'], ENT_QUOTES, 'UTF-8');
        $tags .= '<script type="module" src="' . $src . '"></script>';

        return $tags;
    }

    /**
     * Load (and cache) the Vite manifest.json from the build output directory.
     *
     * Vite 5+ writes the manifest to public/build/.vite/manifest.json.
     * Older Vite 4 builds write to public/build/manifest.json.
     * We check both to stay compatible.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private static function load(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $root = self::env('APP_BASE_PATH') ?: ($_ENV['APP_BASE_PATH'] ?? getcwd());

        // Vite 5 path (preferred)
        $v5Path = $root . '/public/build/.vite/manifest.json';
        // Vite 4 fallback
        $v4Path = $root . '/public/build/manifest.json';

        $path = file_exists($v5Path) ? $v5Path : (file_exists($v4Path) ? $v4Path : null);

        if ($path === null) {
            throw new \RuntimeException(
                "[Rhapsody/React] Vite manifest not found.\n" .
                "  Checked: {$v5Path}\n  Checked: {$v4Path}\n" .
                "  Run 'npm run build', or set VITE_DEV_SERVER=true during development."
            );
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new \RuntimeException("[Rhapsody/React] Failed to parse Vite manifest at {$path}.");
        }

        self::$manifest = $data;
        return self::$manifest;
    }

    private static function isDevMode(): bool
    {
        $val = self::env('VITE_DEV_SERVER') ?: 'false';
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the base path from environment (APP_BASE_URL) for subdirectory deployments.
     */
    private static function getBasePath(): string
    {
        $base = Path::root();
        // Ensure it starts with / and ends without /
        if (! empty($base) && $base !== '/') {
            return rtrim($base, '/');
        }
        return '';
    }

    /**
     * Read a value from $_ENV, then getenv() as fallback.
     */
    private static function env(string $key): string | false
    {
        return $_ENV[$key] ?? getenv($key);
    }
}
