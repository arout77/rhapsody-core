<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

use Rhapsody\Core\Support\Fs;

/**
 * Maps docs template names to their public URLs by reading the framework's
 * own routes and DocsController, so citations point at the real page even
 * where the URL and template name differ (/docs/caching renders performance.twig).
 *
 * Read from source rather than hard-coded: add a docs route and the index
 * picks it up on the next docs:build, with no second list to keep in sync.
 */
final class DocsUrlMap
{
    /**
     * @param array<string, string> $urls template name => URL path
     */
    public function __construct(private readonly array $urls = [])
    {
    }

    public static function fromSources(string $routesFile, string $controllerFile): self
    {
        $routes     = Fs::read($routesFile);
        $controller = Fs::read($controllerFile);

        // controller method => every /docs URL routed to it
        $methodUrls = [];
        if (preg_match_all(
            '/Router::get\(\s*\'(\/docs[^\']*)\'\s*,\s*\[\s*DocsController::class\s*,\s*\'(\w+)\'\s*\]/',
            $routes,
            $matches,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($matches as $match) {
                $methodUrls[$match[2]][] = $match[1];
            }
        }

        // template => URL, via the method that renders it
        $urls = [];
        $parts = preg_split('/public function (\w+)\s*\(/', $controller, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts !== false) {
            for ($i = 1; $i + 1 < count($parts); $i += 2) {
                $method = $parts[$i];
                $body   = $parts[$i + 1];
                if (! isset($methodUrls[$method])) {
                    continue;
                }
                if (preg_match('/@core\/docs\/([\w-]+)\.twig/', $body, $template) !== 1) {
                    continue;
                }
                // Several URLs can serve one page; the shortest is the canonical-looking one.
                $candidates = $methodUrls[$method];
                usort($candidates, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
                $urls[$template[1]] ??= $candidates[0];
            }
        }

        return new self($urls);
    }

    /**
     * URL for a template; falls back to /docs/<template> for unrouted pages.
     */
    public function urlFor(string $template): string
    {
        return $this->urls[$template] ?? ($template === 'index' ? '/docs' : '/docs/' . $template);
    }

    /**
     * Templates the routes/controller actually map, for diagnostics.
     *
     * @return list<string>
     */
    public function mapped(): array
    {
        return array_keys($this->urls);
    }
}
