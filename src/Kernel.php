<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Services\NotificationService;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Routing\Router;

/**
 * The application Kernel: the single seam between "I have a Request" and
 * "here is the Response". Everything that only needs to happen once per
 * process (autoloading, .env, building the container, loading routes) stays
 * in bootstrap.php / index.php's boot section. Everything that happens once
 * PER REQUEST lives here.
 *
 * handle() is deliberately pure: Request in, Response out. No superglobals,
 * no echo, no exit — which is what makes it possible to test "what does the
 * framework do with this request" without spinning up a real HTTP server.
 *
 * HttpException is intentionally left to propagate out of handle() uncaught:
 * ErrorHandler's global exception handler (registered in index.php via
 * ErrorHandler::register()) owns turning it into a rendered error page.
 */
class Kernel
{
    public function __construct(
        private ContainerInterface $container,
        private array $config
    ) {
    }

    /**
     * Route the request and return the resulting Response.
     *
     * @throws HttpException on a 404/500, or whatever Router::dispatch() throws.
     */
    public function handle(Request $request): Response
    {
        $response = Router::dispatch($request, $this->container);

        // A controller/middleware may set a 404/500 status directly on the
        // Response rather than throwing HttpException; normalize that to
        // the same themed error-page rendering — but ONLY for non-JSON
        // responses. A JSON API response (e.g. BaseAiController) that
        // deliberately built a real error body must reach the client as
        // that body, not get discarded and replaced with a contentless
        // HTML error page — that breaks the JSON contract every API caller
        // is relying on, and throws away the actual reason for the failure.
        $headers     = $response->getHeaders();
        $contentType = $headers['Content-Type'] ?? 'text/html';
        $isJson      = str_contains($contentType, 'application/json');

        if (! $isJson) {
            if ($response->getStatusCode() === 404) {
                throw new HttpException(404, 'Page not found');
            }
            if ($response->getStatusCode() === 500) {
                throw new HttpException(500, 'Server error');
            }
        }

        return $response;
    }

    /**
     * Post-response housekeeping that shouldn't change what the response
     * means, only what's delivered: the dev debug toolbar and the
     * framework-update banner. Safe to skip entirely (e.g. in production,
     * or for non-HTML/non-2xx responses).
     */
    public function terminate(Request $request, Response $response): Response
    {
        if (($this->config['app_env'] ?? 'production') !== 'development') {
            return $response;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            return $response;
        }

        $headers     = $response->getHeaders();
        $contentType = $headers['Content-Type'] ?? 'text/html';
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $matchedRoute = Router::getMatchedRoute();

        $debug = Debug::getInstance();
        $debug->end($response, $this->config, $this->container, $matchedRoute);
        $toolbar     = new Toolbar($debug->getData());
        $toolbarHtml = $toolbar->render();

        $content         = $response->getContent();
        $bodyEndPosition = strripos($content, '</body>');
        if ($bodyEndPosition !== false) {
            $content = substr_replace($content, $toolbarHtml, $bodyEndPosition, 0);
        } else {
            $content .= $toolbarHtml;
        }
        $response->setContent($content);

        /** @var NotificationService $notificationService */
        $notificationService = $this->container->resolve(NotificationService::class);
        $response             = $notificationService->injectBanner($response);

        return $response;
    }
}
