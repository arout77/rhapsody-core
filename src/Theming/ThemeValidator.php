<?php
namespace Rhapsody\Core\Theming;

use Twig\Environment;

/**
 * Validates that a custom (non-default) theme's layouts/main.twig
 * defines every block the framework's own default theme defines.
 *
 * Why this exists: Twig's behavior when a child template overrides a
 * block that doesn't exist ANYWHERE in the template it extends is to
 * silently drop that content — no error, no warning, just missing
 * output. For a block as foundational as "content" or "scripts", that
 * is a uniquely confusing failure mode: the page renders successfully,
 * just without whatever the child template put in that block. This
 * turns that into a clear, fail-fast error at boot instead of a page
 * that quietly discards a whole section of itself.
 */
class ThemeValidator
{
    /**
     * The block names views/themes/default/layouts/main.twig defines.
     * Any theme other than "default" is expected to define the same
     * set in its own layouts/main.twig — even if a given block is left
     * empty, e.g. {% block scripts %}{% endblock %}.
     *
     * Kept as an explicit list rather than derived dynamically from the
     * default theme's compiled template at runtime: reliably
     * enumerating every block name Twig recognizes for a given compiled
     * template isn't part of Twig's stable public API surface the way
     * checking for one specific block by name (hasBlock()) is. Update
     * this list if the default theme's own blocks ever change.
     */
    public const REQUIRED_BLOCKS = [
        'title',
        'description',
        'styles',
        'head_extensions',
        'body_class',
        'navigation',
        'full_width_content',
        'main',
        'content',
        'scripts',
    ];

    /**
     * @throws \RuntimeException listing every missing block, if any.
     */
    public static function validate(Environment $twig, string $activeTheme): void
    {
        if ($activeTheme === 'default') {
            return;
        }

        // Deliberately NOT $twig->load('layouts/main.twig') here. load()
        // (like render()/display()) initializes Twig's runtime, which
        // permanently locks out addGlobal() afterward — and this method
        // runs from inside the same bootstrap phase that's still
        // actively registering globals (BaseController adds a 'session'
        // global on every controller construction, relying on the
        // runtime not being initialized yet). getSourceContext() only
        // resolves the path and reads the raw file through the loader's
        // own theme-fallback logic — no compilation, no runtime touch,
        // so it can't trip that lock.
        $source = $twig->getLoader()->getSourceContext('layouts/main.twig')->getCode();

        $missing = [];
        foreach (self::REQUIRED_BLOCKS as $block) {
            if (! preg_match('/\{%-?\s*block\s+' . preg_quote($block, '/') . '\b/', $source)) {
                $missing[] = $block;
            }
        }

        if (empty($missing)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Theme "%s" is missing required block(s) in layouts/main.twig: %s. ' .
            'Every theme must define these blocks — they can be left empty, e.g. ' .
            '{%% block scripts %%}{%% endblock %%} — so that pages extending this layout ' .
            'render correctly instead of silently losing content Twig has nowhere to place. ' .
            'See %s/docs/themes for the full list and what each block is for.',
            $activeTheme,
            implode(', ', $missing),
            rtrim($_ENV['APP_URL'] ?? '', '/')
        ));
    }
}
