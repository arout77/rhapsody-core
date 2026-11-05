<?php

declare(strict_types=1);

namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\Ai\Assist\AssistException;
use Rhapsody\Core\Ai\Assist\AssistPrompt;
use Rhapsody\Core\Ai\Assist\AssistServiceFactory;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Session;
use Throwable;
use Twig\Environment;

/**
 * JSON endpoints behind the toolbar "Ask AI" panel and the error page
 * "Explain and fix" button:
 *
 *   POST /_rhapsody/assist/preview   what would be sent (nothing leaves the server)
 *   POST /_rhapsody/assist/explain   send it and return the answer and proposed fixes
 *   POST /_rhapsody/assist/apply     write ONE stored proposal to disk
 *
 * Development only: outside `app_env = development` every action answers 404,
 * whatever the routes say. Because "apply" changes files, every call must also
 * carry the session CSRF token, the `X-Rhapsody-Assist: 1` header (which a
 * plain cross-site form cannot set) and, when the browser sends an Origin,
 * that Origin must be this host. The browser never sends request data or file
 * contents, only a snapshot id, a question and section names.
 */
class AssistController extends BaseController
{
    public function __construct(Environment $twig, private readonly ContainerInterface $container)
    {
        parent::__construct($twig);
    }

    public function preview(Request $request): Response
    {
        return $this->handle($request, function (array $body): array {
            $service = AssistServiceFactory::fromContainer($this->container);
            $mode    = self::text($body, 'mode');

            return $service
                ->prepare(self::text($body, 'snapshot_id'), $mode, self::text($body, 'question'), AssistPrompt::cleanSelection($body['sections'] ?? null))
                ->toPreview($service->providerLabel());
        });
    }

    public function explain(Request $request): Response
    {
        return $this->handle($request, function (array $body): array {
            return AssistServiceFactory::fromContainer($this->container)
                ->run(self::text($body, 'snapshot_id'), self::text($body, 'mode'), self::text($body, 'question'), AssistPrompt::cleanSelection($body['sections'] ?? null))
                ->toArray();
        });
    }

    public function apply(Request $request): Response
    {
        return $this->handle($request, function (array $body): array {
            return ['ok' => true] + AssistServiceFactory::fromContainer($this->container)->apply(self::text($body, 'proposal_id'));
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $action
     */
    private function handle(Request $request, callable $action): Response
    {
        if (! AssistServiceFactory::enabled($this->container)) {
            throw new HttpException(404, 'Page not found');
        }

        $body = $request->getBody();

        $refusal = $this->refuse($request, $body);
        if ($refusal !== null) {
            return $this->json(['error' => $refusal], 403);
        }

        try {
            return $this->json($action($body));
        } catch (AssistException $e) {
            return $this->json(['error' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            error_log('Rhapsody assist failed: ' . $e->getMessage());

            return $this->json(['error' => 'Something went wrong while preparing the request. See the PHP error log.'], 500);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refuse(Request $request, array $body): ?string
    {
        if ($request->header('X-Rhapsody-Assist') !== '1') {
            return 'Missing X-Rhapsody-Assist header.';
        }

        if (! self::sameOrigin($request)) {
            return 'Cross-origin requests are not allowed.';
        }

        if (! Session::verifyCsrfToken(self::text($body, '_token'))) {
            return 'Your session token is missing or expired. Reload the page and try again.';
        }

        return null;
    }

    private static function sameOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        if (! is_string($origin) || $origin === '') {
            return true;
        }

        $host = $request->header('Host');
        $parts = parse_url($origin);
        if (! is_string($host) || ! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $normalize = static fn (string $value): string => preg_replace('/:(80|443)$/', '', strtolower($value)) ?? strtolower($value);

        $originHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $normalize($originHost) === $normalize($host);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
