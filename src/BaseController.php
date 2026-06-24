<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Cache;
use Rhapsody\Core\Database;
use Rhapsody\Core\Helpers\Recaptcha;
use Rhapsody\Core\SEO\SchemaOrg;
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
}
