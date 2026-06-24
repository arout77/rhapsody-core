<?php

namespace Rhapsody\Core\React;

use Rhapsody\Core\Session;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * ReactIslandExtension
 *
 * Registers Twig functions that allow React components to be embedded
 * inside regular Twig templates — the "React Islands" pattern.
 *
 * Available in every template once BaseController registers this extension.
 *
 * ─── Functions ────────────────────────────────────────────────────────────────
 *
 * react_component(name, props)
 *   Stamps out a self-contained mount point.  The JS bridge finds every
 *   element with the class "rhapsody-island" and mounts the named component
 *   into it with the supplied props.
 *
 *   Example:
 *     {{ react_component('UserStats', { userId: user.id, period: 'monthly' }) }}
 *
 * vite_assets(entry)
 *   Emits the correct <script> / <link> tags for the given Vite entry point,
 *   switching between the dev server (HMR) and production manifest automatically.
 *
 *   Example (place in <head> or {% block scripts %}):
 *     {{ vite_assets('resources/js/app.jsx') }}
 *
 * csrf_token()
 *   Returns the current session CSRF token as a plain string.
 *   Useful for injecting it into a <meta> tag so React can send it on
 *   non-API POST requests.
 *
 *   Example:
 *     <meta name="csrf-token" content="{{ csrf_token() }}">
 */
class ReactIslandExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'react_component',
                [$this, 'renderIsland'],
                ['is_safe' => ['html']]   // tells Twig not to escape the returned HTML
            ),
            new TwigFunction(
                'vite_assets',
                [$this, 'renderViteAssets'],
                ['is_safe' => ['html']]
            ),
            new TwigFunction(
                'csrf_token',
                [$this, 'getCsrfToken']
            ),
        ];
    }

    /**
     * Render a React island mount point.
     *
     * Produces a <div> that the JS bridge locates and hydrates.
     * Props are stored in a nested non-executable JSON block for XSS safety.
     *
     * @param string              $component Component name, matching a file in
     *                                       resources/js/components/ (e.g. "UserStats").
     *                                       Nested paths are supported: "Charts/LineChart".
     * @param array<string, mixed> $props    Data passed to the component as props.
     * @return string Safe HTML — marked is_safe so Twig will not double-escape it.
     */
    public function renderIsland(string $component, array $props = []): string
    {
        // JSON_HEX_TAG prevents </script>-style injection inside the JSON block.
        $propsJson = json_encode(
            $props,
            JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $safeComponent = htmlspecialchars($component, ENT_QUOTES, 'UTF-8');

        // The wrapping div carries the component name.
        // The nested <script type="application/json"> carries the props.
        // Both are read by the JS bridge; neither is executed by the browser.
        return <<<HTML
        <div class="rhapsody-island" data-component="{$safeComponent}">
            <script type="application/json" class="rhapsody-island-props">{$propsJson}</script>
        </div>
        HTML;
    }

    /**
     * Emit the Vite asset tags (<script> / <link>) for a given entry point.
     *
     * In development (VITE_DEV_SERVER=true) this points to the Vite dev server
     * so HMR works inside Twig pages just as it does in full-SPA mode.
     *
     * In production this resolves fingerprinted URLs from public/build/.vite/manifest.json.
     *
     * @param string $entry The Vite entry-point path (e.g. "resources/js/app.jsx").
     * @return string Safe HTML.
     */
    public function renderViteAssets(string $entry = 'resources/js/app.jsx'): string
    {
        return ViteManifest::tags($entry);
    }

    /**
     * Return the current session CSRF token.
     *
     * @return string
     */
    public function getCsrfToken(): string
    {
        return Session::csrfToken();
    }
}
