<?php
namespace Rhapsody\Core\View;

use Rhapsody\Core\Helpers\Recaptcha;
use Rhapsody\Core\Response;
use Rhapsody\Core\SEO\SchemaOrg;
use Twig\Environment;

/**
 * Shared view-rendering pipeline: meta-merge -> schema render -> captcha
 * inject -> Twig render -> Response wrap.
 *
 * Extracted from BaseController::view() so the same pipeline can be reused
 * by module-facing rendering (TwigFacade, Phase 3+) without duplicating it.
 * BaseController::view() is now a thin caller of render() below; its
 * behavior for existing app pages is unchanged by this extraction.
 */
class ViewRenderer
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * Renders a view file using Twig, with optional SEO schema markup and
     * an optional reCAPTCHA widget injected into the template context.
     *
     * @param string $view The view file to render.
     * @param array<string, mixed> $args Associative array of data to pass to the view.
     * @param array<string, mixed> $meta Per-call SEO metadata overrides (e.g. ['title' => 'My Title']).
     * @param array<string, mixed> $metaDefaults Fallback SEO metadata merged underneath $meta.
     * @param SchemaOrg|null $schema Schema.org builder to render into `schema_markup`, or null to skip it.
     * @param bool $injectCaptcha Whether to render the reCAPTCHA widget into `captcha_form`.
     * @return Response
     */
    public function render(
        string $view,
        array $args = [],
        array $meta = [],
        array $metaDefaults = [],
        ?SchemaOrg $schema = null,
        bool $injectCaptcha = true
    ): Response {
        $args['meta'] = array_merge($metaDefaults, $meta);

        // Inject engine variables cleanly prior to compilation context execution
        $args['schema_markup'] = $schema !== null ? $schema->render() : '';
        $args['captcha_form']  = $injectCaptcha ? Recaptcha::render() : '';

        $output = $this->twig->render($view, $args);

        $response = new Response();
        $response->setContent($output);
        return $response;
    }
}
