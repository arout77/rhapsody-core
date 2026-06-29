<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Logger;

class ErrorHandler
{
    private static bool $registered = false;
    private static array $config    = [];

    public static function register(array $config): void
    {
        if (self::$registered) {
            return;
        }

        self::$config = $config;

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    public static function handleError($level, $message, $file, $line)
    {
        if (str_contains($message, 'http_response_code()')) {
            return false;
        }

        if (error_reporting() & $level) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }
    }

    public static function handleException(\Throwable $e): void
    {
        self::logError($e);

        $isHttp404 = ($e instanceof HttpException && $e->getStatusCode() === 404);

        if (self::isDevelopment() && ! $isHttp404) {
            self::renderWhoops($e);
        } else {
            self::renderProductionError($e);
        }

        exit(1);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );
            self::handleException($exception);
        }
    }

    private static function logError(\Throwable $e): void
    {
        $logPath = self::$config['logging']['error_log_path'] ?? __DIR__ . '/../storage/logs/errors.log';
        $logger  = new Logger($logPath);
        $logger->log(
            sprintf(
                "[%s] %s in %s:%d\nStack trace:\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ),
            'ERROR'
        );
    }

    private static function renderWhoops(\Throwable $e): void
    {
        if (! headers_sent()) {
            $whoops = new \Whoops\Run();
            $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler());
            $whoops->handleException($e);
        } else {
            self::renderProductionError($e);
        }
    }

    private static function renderProductionError(\Throwable $e): void
    {
        $statusCode = ($e instanceof HttpException) ? $e->getStatusCode() : 500;

        if (! headers_sent()) {
            http_response_code($statusCode);
        }

        // 1. Try using the container's Twig instance (already configured)
        $twig = null;
        if (isset($GLOBALS['container']) && $GLOBALS['container']->has(\Twig\Environment::class)) {
            try {
                $twig = $GLOBALS['container']->resolve(\Twig\Environment::class);
            } catch (\Exception $err) {
                error_log('Cannot resolve Twig from container: ' . $err->getMessage());
            }
        }

        if ($twig) {
            try {
                $theme        = self::$config['theme'] ?? 'default';
                $templateName = $theme . '/errors/' . $statusCode . '.twig';
                if ($twig->getLoader()->exists($templateName)) {
                    echo $twig->render($templateName, [
                        'message' => $statusCode === 404 ? 'Page not found.' : 'Server error.',
                        'code'    => $statusCode,
                    ]);
                    return;
                } else {
                    $defaultTemplate = 'default/errors/' . $statusCode . '.twig';
                    if ($twig->getLoader()->exists($defaultTemplate)) {
                        echo $twig->render($defaultTemplate, [
                            'message' => $statusCode === 404 ? 'Page not found.' : 'Server error.',
                            'code'    => $statusCode,
                        ]);
                        return;
                    }
                }
            } catch (\Exception $e) {
                error_log('Twig error (container): ' . $e->getMessage());
            }
        }

        // 2. Custom loader with fallback (including essential Twig functions)
        self::renderWithCustomTwig($statusCode);
    }

    private static function renderWithCustomTwig(int $statusCode): void
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : Path::root();

        // Candidate base directories for themes (project first, then core)
        $themeBaseCandidates = [
            $root . '/views/themes',
            $root . '/vendor/arout/rhapsody-core/resources/views/themes', // core fallback
        ];

        $theme       = self::$config['theme'] ?? 'default';
        $loaderPaths = [];
        $parentPaths = [];

        foreach ($themeBaseCandidates as $base) {
            if (! is_dir($base)) {
                continue;
            }

            // Try to add the specific theme directory
            $specificThemePath = $base . '/' . $theme;
            if (is_dir($specificThemePath)) {
                $loaderPaths[] = $specificThemePath;
                $parentPaths[] = dirname($base);
            }

            // Try to add default theme (if different from current theme)
            if ($theme !== 'default') {
                $defaultPath = $base . '/default';
                if (is_dir($defaultPath)) {
                    $loaderPaths[] = $defaultPath;
                    $parentPaths[] = dirname($base);
                }
            }

            $parentPaths[] = $base;
        }

        // Combine and unique
        $loaderPaths = array_unique(array_merge($loaderPaths, $parentPaths));
        $loaderPaths = array_filter($loaderPaths, 'is_dir');

        if (empty($loaderPaths)) {
            self::renderPlainError($statusCode);
            return;
        }

        try {
            $loader = new \Twig\Loader\FilesystemLoader($loaderPaths);
            $twig   = new \Twig\Environment($loader);
            $twig->addGlobal('base_url', $_ENV['APP_BASE_URL'] ?? '');
            $twig->addGlobal('app_env', $_ENV['APP_ENV'] ?? 'production');
            $twig->addGlobal('config', self::$config);

            // --- REGISTER ESSENTIAL TWIG FUNCTIONS ---
            // 1. vite_assets (used in layouts/main.twig)
            $twig->addFunction(new \Twig\TwigFunction('vite_assets', function ($entry) {
                return \Rhapsody\Core\React\ViteManifest::tags($entry);
            }, ['is_safe' => ['html']]));

            // 2. route (for named routes)
            $twig->addFunction(new \Twig\TwigFunction('route', function ($name, $params = []) {
                return \Rhapsody\Core\Routing\Router::generateUrl($name, $params);
            }));

            // 3. csrf_token (if used)
            $twig->addFunction(new \Twig\TwigFunction('csrf_token', function () {
                return \Rhapsody\Core\Session::csrfToken();
            }));

            // 4. csrf_field (if used)
            $twig->addFunction(new \Twig\TwigFunction('csrf_field', function () {
                $token = \Rhapsody\Core\Session::csrfToken();
                return new \Twig\Markup('<input type="hidden" name="_token" value="' . $token . '">', 'UTF-8');
            }));

            // 5. react_component (if used in error pages)
            $twig->addFunction(new \Twig\TwigFunction('react_component', function ($component, $props = []) {
                $propsJson     = json_encode($props, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $safeComponent = htmlspecialchars($component, ENT_QUOTES, 'UTF-8');
                return new \Twig\Markup(
                    '<div class="rhapsody-island" data-component="' . $safeComponent . '">
                        <script type="application/json" class="rhapsody-island-props">' . $propsJson . '</script>
                    </div>',
                    'UTF-8'
                );
            }, ['is_safe' => ['html']]));

            // Try theme-specific template
            $templateName = $theme . '/errors/' . $statusCode . '.twig';
            if ($loader->exists($templateName)) {
                echo $twig->render($templateName, [
                    'message' => $statusCode === 404 ? 'Page not found.' : 'Server error.',
                    'code'    => $statusCode,
                ]);
                return;
            }

            // Try default theme
            $defaultTemplate = 'default/errors/' . $statusCode . '.twig';
            if ($loader->exists($defaultTemplate)) {
                echo $twig->render($defaultTemplate, [
                    'message' => $statusCode === 404 ? 'Page not found.' : 'Server error.',
                    'code'    => $statusCode,
                ]);
                return;
            }

            // Try without theme prefix
            if ($loader->exists('errors/' . $statusCode . '.twig')) {
                echo $twig->render('errors/' . $statusCode . '.twig', [
                    'message' => $statusCode === 404 ? 'Page not found.' : 'Server error.',
                    'code'    => $statusCode,
                ]);
                return;
            }

        } catch (\Exception $e) {
            error_log('Custom Twig error: ' . $e->getMessage());
        }

        self::renderPlainError($statusCode);
    }

    private static function renderPlainError(int $statusCode): void
    {
        $title       = $statusCode === 404 ? '404 Not Found' : '500 Internal Server Error';
        $bodyMessage = $statusCode === 404
            ? 'The page you requested could not be found.'
            : 'Something went wrong. Please try again later.';
        $baseUrl = $_ENV['APP_BASE_URL'] ?? '';

        echo "<!DOCTYPE html><html><head><title>{$title}</title><style>body{font-family:sans-serif;text-align:center;padding:50px;}</style></head>";
        echo "<body><h1>{$title}</h1><p>{$bodyMessage}</p>";
        echo "<a href='{$baseUrl}/'>Go Home</a></body></html>";
    }

    private static function isDevelopment(): bool
    {
        return (self::$config['app_env'] ?? 'production') === 'development';
    }
}
