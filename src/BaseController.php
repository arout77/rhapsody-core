<?php
namespace Rhapsody\Core;

use PDO;
use Rhapsody\Core\Cache;
use Rhapsody\Core\Database;
use Rhapsody\Core\SEO\SchemaOrg;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

abstract class BaseController
{
    protected Environment $twig;
    protected Database $db;
    protected Cache $cache;
    protected SchemaOrg $schema;

    /**
     * @param Environment $twig
     */
    public function __construct(Environment $twig)
    {
        $this->twig   = $twig;
        $this->cache  = Cache::getInstance();
        $this->schema = new SchemaOrg();

        $this->twig->addGlobal('session', $_SESSION);

        // Safely pull flash data without prematurely erasing it
        $this->twig->addGlobal('flash_error', $_SESSION['error'] ?? null);
        $this->twig->addGlobal('flash_success', $_SESSION['success'] ?? null);

        // Fallback option using the container instance to resolve the pre-configured database singleton
        // (Assuming a global helper or accessible container reference exists)
        global $container;

        if (isset($container) && $container->has(Database::class)) {
            // Retrieve the shared singleton instance that already possesses your config details
            $this->db = $container->resolve(Database::class);
        } else {
            // Fallback safety if the container isn't initialized yet (e.g. in standalone testing)
            throw new \Exception("Database service has not been properly initialized inside the Service Container.");
        }
        $appVersion = $this->cache->get('update_available'); // Or fetch via configuration setup
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
     * @param array  $args Associative array of data to pass to the view.
     * @param array  $meta SEO metadata for the page (e.g., ['title' => 'My Title']).
     * @return Response
     */
    protected function view(string $view, array $args = [], array $meta = []): Response
    {
        // 1. Merge standard layout configuration meta fields
        $defaults = [
            'title'       => 'Rhapsody - Compose your masterpiece',
            'description' => 'Rhapsody is a modern PHP framework for developers who find full-stack frameworks like Laravel too heavy for their needs, but find micro-frameworks like Slim too bare-bones. It gives you the modern tooling you love—like a powerful CLI, dependency injection, and an ORM—in a simple, performant, and elegant package. It\'s the perfect choice for building fast, maintainable web applications and APIs without the overhead.',
        ];
        $args['meta'] = array_merge($defaults, $meta);

        // 2. Automatically inject the rendered JSON-LD schemas into Twig arguments
        $args['schema_markup'] = $this->schema->render();

        $output = $this->twig->render($view, $args);

        $response = new Response();
        $response->setContent($output);
        return $response;
    }

    /**
     * Creates and returns a JSON response.
     *
     * @param array $data The data to be encoded as JSON.
     * @param int $statusCode The HTTP status code for the response (defaults to 200 OK).
     * @return Response
     */
    protected function json(array $data, int $statusCode = 200): Response
    {
        $response = new Response();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'application/json');
        $response->setContent(json_encode($data, JSON_PRETTY_PRINT)); // JSON_PRETTY_PRINT makes it readable
        return $response;
    }

}
