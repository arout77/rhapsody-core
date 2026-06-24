<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Cache;
use Rhapsody\Core\Database;
use Rhapsody\Core\Helpers\Recaptcha;
use Rhapsody\Core\React\ReactIslandExtension;
use Rhapsody\Core\React\ViteManifest;
use Rhapsody\Core\SEO\SchemaOrg;
use Rhapsody\Core\Session;
use Twig\Environment;

abstract class BaseController
{
    protected Environment $twig;
    protected Database $db;
    protected Cache $cache;
    protected SchemaOrg $schema;

    /**
     * @param Environment $twig
     * @throws \Exception
     */
    public function __construct(Environment $twig)
    {
        $this->twig   = $twig;
        $this->cache  = Cache::getInstance();
        $this->schema = new SchemaOrg();

        // Register the React island Twig functions (react_component, vite_assets, csrf_token).
        // This makes them available in every Twig template rendered by any controller.
        $this->twig->addExtension(new ReactIslandExtension());

        // Safely bridge session states into the view engine context
        $this->twig->addGlobal('session', $_SESSION ?? []);
        $this->twig->addGlobal('flash_error', $_SESSION['error'] ?? null);
        $this->twig->addGlobal('flash_success', $_SESSION['success'] ?? null);

        // Fallback option using the container instance to resolve the pre-configured database singleton
        global $container;
        /** @var \Rhapsody\Core\Container|null $container */

        if (isset($container) && $container->has(Database::class)) {
            // @phpstan-ignore-next-line
            $this->db = $container->resolve(Database::class);
        } else {
            throw new \Exception("Database service has not been properly initialized inside the Service Container.");
        }

        $appVersion = $this->cache->get('update_available') ?? '1.0.0';
        $appUrl     = $_ENV['APP_URL'] ?? 'http://localhost';

        $this->schema->add('SoftwareApplication', [
            'name'                => 'Rhapsody Framework',
            'description'         => 'Rhapsody is a lightweight, modern PHP framework for building elegant and maintainable web applications.',
            'applicationCategory' => 'DeveloperApplication',
            'operatingSystem'     => 'Web',
            'softwareVersion'     => $appVersion,
            'url'                 => $appUrl,
            'author'              => [
                '@type' => 'Person',
                'name'  => 'Andrew Rout',
            ],
            'programmingLanguage' => [
                '@type' => 'ComputerLanguage',
                'name'  => 'PHP',
            ],
            'image'               => $appUrl . '/public/img/logo.png',
            'offers'              => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
            ],
        ]);
    }

    /**
     * Renders a view file using Twig.
     *
     * @param string $view The view file to render.
     * @param array<string, mixed> $args Associative array of data to pass to the view.
     * @param array<string, mixed> $meta SEO metadata for the page (e.g., ['title' => 'My Title']).
     * @return Response
     */
    protected function view(string $view, array $args = [], array $meta = []): Response
    {
        $defaults = [
            'title'       => 'Rhapsody - Compose your masterpiece',
            'description' => 'Rhapsody is a modern PHP framework for developers who find full-stack frameworks like Laravel too heavy for their needs, but find micro-frameworks like Slim too bare-bones.',
        ];
        $args['meta'] = array_merge($defaults, $meta);

        // Inject engine variables cleanly prior to compilation context execution
        $args['schema_markup'] = $this->schema->render();
        $args['captcha_form']  = Recaptcha::render();

        $output = $this->twig->render($view, $args);

        $response = new Response();
        $response->setContent($output);
        return $response;
    }

    /**
     * Creates and returns a JSON response.
     *
     * @param array<mixed> $data The data to be encoded as JSON.
     * @param int $statusCode The HTTP status code for the response (defaults to 200 OK).
     * @return Response
     */
    protected function json(array $data, int $statusCode = 200): Response
    {
        $response = new Response();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'application/json');
        $response->setContent(json_encode($data, JSON_PRETTY_PRINT));
        return $response;
    }

    /**
     * Mounts a React component and returns it as a full HTML response.
     *
     * The props array is serialised as JSON and injected into a
     * <script type="application/json"> block — making it XSS-safe and
     * readable by the Rhapsody JS bridge without exposing raw PHP output.
     *
     * The current session CSRF token is embedded as a <meta> tag so React
     * can read it with:
     *   document.querySelector('meta[name="csrf-token"]').content
     *
     * Vite assets are resolved automatically:
     *  - VITE_DEV_SERVER=true  →  proxied through the Vite dev server (HMR)
     *  - VITE_DEV_SERVER=false →  fingerprinted files from public/build/
     *
     * @param string              $component  The component name (e.g. 'Dashboard').
     *                                         Must match the filename in resources/js/components/.
     * @param array<string, mixed> $props      Data passed to the component as props.
     * @param array<string, mixed> $meta       HTML <head> metadata.
     *                                         Supported keys: title, description, lang.
     * @return Response
     *
     * @example
     *   // In a controller action:
     *   return $this->react('Dashboard', [
     *       'user'  => $user->toArray(),
     *       'stats' => $this->getStats(),
     *   ], ['title' => 'Dashboard']);
     */
    protected function react(string $component, array $props = [], array $meta = []): Response
    {
        $title       = htmlspecialchars($meta['title']       ?? ($_ENV['APP_NAME'] ?? 'Rhapsody App'), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($meta['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $lang        = htmlspecialchars($meta['lang']        ?? 'en', ENT_QUOTES, 'UTF-8');

        // JSON_HEX_TAG prevents </script> injection inside the JSON block.
        $propsJson = json_encode($props, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Expose the session CSRF token so React can send it on non-API POST requests.
        $csrfToken = htmlspecialchars(Session::csrfToken(), ENT_QUOTES, 'UTF-8');

        // Vite injects the right <script> / <link> tags based on the env.
        $viteAssets = ViteManifest::tags('resources/js/app.jsx');

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="csrf-token" content="{$csrfToken}">
            <meta name="description" content="{$description}">
            <title>{$title}</title>
            {$viteAssets}
        </head>
        <body>
            <!--
                Rhapsody React Bridge
                The JS entry point reads data-component and mounts the matching
                component from resources/js/components/ with the supplied props.
            -->
            <div id="rhapsody-root" data-component="{$component}"></div>

            <!--
                Props are stored in a non-executable JSON block to prevent XSS.
                Access them in JS via:
                    JSON.parse(document.getElementById('rhapsody-props').textContent)
            -->
            <script type="application/json" id="rhapsody-props">{$propsJson}</script>
        </body>
        </html>
        HTML;

        $response = new Response();
        $response->setContent($html);
        return $response;
    }
}
