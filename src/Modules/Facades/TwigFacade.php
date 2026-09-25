<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;
use Rhapsody\Core\Response;
use Rhapsody\Core\View\ViewRenderer;
use Twig\Environment;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TwigFacade
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ViewRenderer $viewRenderer,
        private readonly ModulePermissions $permissions,
        private readonly string $slug,
        private readonly ?string $viewsPath = null,
    ) {
        // Module views are module-owned, not app-owned (Phase 1 decision):
        // register this module's own namespaced view path the same way
        // bootstrap.php registers @core, so @{slug}/foo.twig always
        // resolves here and never falls back into the app's /views.
        if ($this->viewsPath !== null && is_dir($this->viewsPath)) {
            $this->registerViewsNamespace();
        }
    }

    private function registerViewsNamespace(): void
    {
        $loader = $this->twig->getLoader();

        // Only FilesystemLoader supports namespaced addPath(). If the app
        // is ever running a different loader implementation there's
        // nothing safe to hook into here — render() will fail with a
        // clear Twig "template not found" rather than a silent no-op.
        if (! $loader instanceof FilesystemLoader) {
            return;
        }

        // Idempotent: twig() gets constructed once during boot() to wire
        // up module Twig functions, and may be constructed again later
        // (e.g. a future controller holding its own instance) — without
        // this guard, addPath() would stack up duplicate entries under
        // the same namespace on every call.
        if (in_array($this->slug, $loader->getNamespaces(), true)) {
            return;
        }

        $loader->addPath($this->viewsPath, $this->slug);
    }

    public function addExtension(ExtensionInterface $extension): void
    {
        if (! $this->permissions->can('twig.extensions')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to register a Twig extension without declaring \"twig.extensions\""
            );
        }
        $this->twig->addExtension($extension);
    }

    public function addFunction(string $name, callable $callback, array $options = []): void
    {
        if (! $this->permissions->can('twig.functions')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to register a Twig function without declaring \"twig.functions\""
            );
        }

        // Namespace-prefix so two modules (or a module and core) can never
        // clobber each other's function names: mod_acme_seo_sitemap_myFunc(...)
        $prefixed = 'mod_' . str_replace('-', '_', $this->slug) . '_' . $name;
        $this->twig->addFunction(new TwigFunction($prefixed, $callback, $options));
    }

    /**
     * Renders one of this module's own views through the shared
     * ViewRenderer pipeline — Twig render -> Response wrap only, for now.
     * No schema, no captcha, no meta-defaults: those land in Phase 5,
     * which will extend this signature to accept them as opt-in
     * parameters rather than duplicating the pipeline.
     *
     * $view is resolved against this module's own namespaced views path.
     * Pass a bare filename ('dashboard.twig') and it's rewritten to
     * '@{slug}/dashboard.twig' automatically; an already-namespaced
     * reference (starting with '@') is left alone.
     *
     * Deliberately not permission-gated, unlike addExtension()/
     * addFunction() above: rendering a module's own view into a Response
     * it returns from its own route handler touches no shared/global Twig
     * state, so there's no "views.render"-style permission to declare —
     * same posture as settings() reads being ungated while writes require
     * settings.manage.
     *
     * @param string $view
     * @param array<string, mixed> $data
     * @return Response
     */
    public function render(string $view, array $data = []): Response
    {
        if (! str_starts_with($view, '@')) {
            $view = '@' . $this->slug . '/' . $view;
        }

        return $this->viewRenderer->render($view, $data, [], [], null, false);
    }
}