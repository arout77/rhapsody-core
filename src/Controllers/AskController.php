<?php

declare(strict_types=1);

namespace Rhapsody\Core\Controllers;

use Rhapsody\Core\Ai\Docs\AskServiceFactory;
use Rhapsody\Core\BaseController;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Twig\Environment;

/**
 * The web version of `rhapsody ask`: a page where a developer asks a question
 * and gets the matching documentation sections (and, in development with an
 * API key, a short cited answer).
 *
 * Mount it on any path; the page posts to "<its own path>/query":
 *
 *     Router::get('/ask', [AskController::class, 'index']);
 *     Router::post('/ask/query', [AskController::class, 'query']);
 *
 * Development only: outside `app_env = development` both actions answer
 * with a 404, whatever the routes or middleware say, so the page can be left
 * in the route file without ever being reachable in production. It sends only
 * the question and public documentation text to the model.
 */
class AskController extends BaseController
{
    private const MAX_QUESTION_CHARS = 500;

    public function __construct(Environment $twig, private readonly ContainerInterface $container)
    {
        parent::__construct($twig);
    }

    public function index(Request $request): Response
    {
        $this->requireDevelopment();

        return $this->view('@core/ask/index.twig', [], [
            'title'         => 'Ask the docs – Rhapsody',
            'description'   => 'Ask a question about the framework and get the matching documentation sections.',
            'canonical_url' => $request->getCanonicalUrl(),
        ]);
    }

    public function query(Request $request): Response
    {
        $this->requireDevelopment();

        $question = trim($request->string('question'));

        if ($question === '') {
            return $this->json(['error' => 'Type a question first.'], 400);
        }

        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            return $this->json(['error' => 'Please keep the question under ' . self::MAX_QUESTION_CHARS . ' characters.'], 400);
        }

        try {
            $result = AskServiceFactory::fromContainer($this->container)->ask($question);
        } catch (\Throwable $e) {
            error_log('Ask the docs failed: ' . $e->getMessage());

            return $this->json(['error' => 'The docs search is unavailable right now.'], 500);
        }

        return $this->json($result->toArray());
    }

    /**
     * @throws HttpException 404 unless app_env is "development".
     */
    private function requireDevelopment(): void
    {
        if (! AskServiceFactory::aiAllowed($this->container)) {
            throw new HttpException(404, 'Page not found');
        }
    }
}
