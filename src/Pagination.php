<?php
namespace Rhapsody\Core;

use Twig\Environment;

class Pagination
{
    protected int $totalItems;
    protected int $itemsPerPage;
    protected int $currentPage;
    protected int $totalPages;

    /**
     * Base URL used to build page links. When null (the default),
     * render() falls back to its original behavior of relative "?page="
     * links, which resolve against whatever URL the page was requested
     * on — this keeps any existing callers working unchanged.
     */
    protected ?string $baseUrl = null;

    /**
     * Standing window size for getPageRange(). Null (the default) means
     * "every page, no windowing" — the same behavior render() has always
     * had. Set via setMaxLinks() so a custom template accessing
     * `pagination.pageRange` reflects a configured window without having
     * to pass an argument through Twig's zero-arg property access.
     */
    protected ?int $maxLinks = null;

    /**
     * Whether render() appends a jump-to-page <select> after the nav.
     * Defaults to false so existing callers see no visual change.
     */
    protected bool $showJumpMenu = false;

    /**
     * CSS classes applied to the rendered markup. Defaults match the
     * original hardcoded render() output exactly, so calling code that
     * never touches setCssClasses() sees no visual change.
     */
    protected array $cssClasses = [
        'container'  => '',
        'list'       => 'flex items-center space-x-2',
        'item'       => '',
        'link'       => 'px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600',
        'disabled'   => 'px-4 py-2 bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md text-gray-400 dark:text-gray-500 cursor-not-allowed',
        'active'     => 'px-4 py-2 bg-blue-500 dark:bg-blue-600 text-white border border-blue-500 dark:border-blue-600 rounded-md',
        'ellipsis'   => 'px-4 py-2 text-gray-400 dark:text-gray-500',
        'jumpForm'   => 'flex items-center space-x-2 mt-4',
        'jumpLabel'  => 'text-sm text-gray-600 dark:text-gray-400',
        'jumpMenu'   => 'px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md',
        'jumpButton' => 'px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600',
    ];

    /**
     * @param int $totalItems
     * @param int $itemsPerPage
     * @param int $currentPage
     */
    public function __construct(int $totalItems, int $itemsPerPage, int $currentPage = 1)
    {
        $this->totalItems   = $totalItems;
        $this->itemsPerPage = $itemsPerPage;
        $this->totalPages   = (int) ceil($this->totalItems / $this->itemsPerPage);
        $this->currentPage  = $this->setCurrentPage($currentPage);
    }

    /**
     * @param  int     $page
     * @return mixed
     */
    private function setCurrentPage(int $page): int
    {
        if ($page < 1) {
            return 1;
        }
        if ($page > $this->totalPages && $this->totalPages > 0) {
            return $this->totalPages;
        }
        return $page;
    }

    /**
     * @return mixed
     */
    public function getLimit(): int
    {
        return $this->itemsPerPage;
    }

    public function getOffset(): int
    {
        return ($this->currentPage - 1) * $this->itemsPerPage;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /**
     * The page immediately before the current one, or null if the current
     * page is already the first. Callable from Twig without parens as
     * `pagination.previousPage`.
     */
    public function getPreviousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    /**
     * The page immediately after the current one, or null if the current
     * page is already the last. Callable from Twig without parens as
     * `pagination.nextPage`.
     */
    public function getNextPage(): ?int
    {
        return $this->currentPage < $this->totalPages ? $this->currentPage + 1 : null;
    }

    /**
     * The list of page numbers to render as links.
     *
     * With no argument (and no standing setMaxLinks() value), returns
     * every page from 1 to totalPages — the original, un-windowed
     * behavior. Pass $maxLinks (or call setMaxLinks() first) to get a
     * window of at most $maxLinks pages centered on the current page;
     * render() uses this to decide whether First/Last links and ellipses
     * are needed.
     *
     *                            setMaxLinks() value, then to "no window"
     * @param  int|null $maxLinks Window size; falls back to the standing
     * @return int[]
     */
    public function getPageRange(?int $maxLinks = null): array
    {
        $maxLinks = $maxLinks ?? $this->maxLinks;

        if ($maxLinks === null || $maxLinks >= $this->totalPages) {
            return range(1, $this->totalPages);
        }

        $maxLinks = max(1, $maxLinks);
        $half     = intdiv($maxLinks - 1, 2);
        $start    = max(1, $this->currentPage - $half);
        $end      = min($this->totalPages, $start + $maxLinks - 1);
        $start    = max(1, $end - $maxLinks + 1);

        return range($start, $end);
    }

    /**
     * Builds the URL for an arbitrary page number, using the configured
     * base URL and preserving the current request's other query
     * parameters — the same logic render() has always used internally.
     * Callable from Twig with an argument as `pagination.url(3)`.
     */
    public function url(int $page): string
    {
        return $this->buildUrl($page, $this->baseUrl);
    }

    private function buildUrl(int $page, ?string $baseUrl): string
    {
        $queryParams = $_GET;
        unset($queryParams['page']);
        $queryString = http_build_query($queryParams);
        $queryString = $queryString ? "&{$queryString}" : '';

        $path = $baseUrl !== null ? $baseUrl : '';

        return "{$path}?page={$page}{$queryString}";
    }

    /**
     * A ready-to-spread set of data-* attributes describing this
     * pagination's current state, for AJAX-driven pagination widgets to
     * read from the DOM.
     *
     * @return array<string, int|string>
     */
    public function getDataAttributes(): array
    {
        return [
            'data-pagination'   => '',
            'data-current-page' => $this->currentPage,
            'data-total-pages'  => $this->totalPages,
            'data-per-page'     => $this->itemsPerPage,
            'data-total-items'  => $this->totalItems,
        ];
    }

    /**
     * Set the path page links are built against, e.g. "/marketplace/category/security".
     * When set, links become "{$baseUrl}?page=N" instead of the default
     * relative "?page=N" (which resolves against the current request URL).
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Override any subset of the rendered markup's CSS classes. Accepts
     * any of: container, list, item, link, disabled, active, ellipsis,
     * jumpForm, jumpLabel, jumpMenu, jumpButton. Unspecified keys keep
     * their default value.
     */
    public function setCssClasses(array $classes): self
    {
        $this->cssClasses = array_merge($this->cssClasses, $classes);
        return $this;
    }

    /**
     * Sets a standing window size used by getPageRange() (and therefore
     * by render()'s First/Last/ellipsis logic) whenever a call doesn't
     * provide its own. Pass null to go back to "no window" (every page
     * shown, the original behavior).
     */
    public function setMaxLinks(?int $maxLinks): self
    {
        $this->maxLinks = $maxLinks;
        return $this;
    }

    /**
     * Enables or disables the jump-to-page <select> that render()
     * appends after the nav. Defaults to false.
     */
    public function setShowJumpMenu(bool $enabled = true): self
    {
        $this->showJumpMenu = $enabled;
        return $this;
    }

    /**
     * Renders the built-in HTML markup: a Previous link, page number
     * links (windowed with First/Last/ellipses if maxLinks is set), and
     * a Next link — optionally followed by a jump-to-page menu.
     *
     * With no arguments, behavior is unchanged from the original
     * implementation. Recognized $options keys — all optional, and all
     * one-off overrides for this call only (they never mutate the
     * instance's standing setBaseUrl()/setCssClasses()/setMaxLinks()/
     * setShowJumpMenu() values):
     *  - 'maxLinks'     (int)   window size, see getPageRange()
     *  - 'cssClasses'   (array) merged over the instance's current classes
     *  - 'baseUrl'      (string) overrides setBaseUrl() for this call
     *  - 'showJumpMenu' (bool)  overrides setShowJumpMenu() for this call
     *
     * @param  array     $options
     * @return string
     */
    public function render(array $options = []): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }

        $maxLinks     = $options['maxLinks'] ?? $this->maxLinks;
        $cssClasses   = array_merge($this->cssClasses, $options['cssClasses'] ?? []);
        $baseUrl      = array_key_exists('baseUrl', $options) ? $options['baseUrl'] : $this->baseUrl;
        $showJumpMenu = $options['showJumpMenu'] ?? $this->showJumpMenu;

        $listClass       = $cssClasses['list'];
        $itemClass       = $cssClasses['item'];
        $linkClasses     = $cssClasses['link'];
        $disabledClasses = $cssClasses['disabled'];
        $activeClasses   = $cssClasses['active'];
        $ellipsisClasses = $cssClasses['ellipsis'];

        $itemAttr = $itemClass !== '' ? " class='{$itemClass}'" : '';

        $output = "<nav aria-label=\"Page navigation\"><ul class='{$listClass}'>";

        // Previous button
        if ($this->currentPage > 1) {
            $prevPage  = $this->currentPage - 1;
            $output   .= "<li{$itemAttr}><a href='{$this->buildUrl($prevPage, $baseUrl)}' class='{$linkClasses}'>Previous</a></li>";
        } else {
            $output .= "<li{$itemAttr}><span class='{$disabledClasses}'>Previous</span></li>";
        }

        $pageRange    = $this->getPageRange($maxLinks);
        $firstInRange = $pageRange[0] ?? 1;
        $lastInRange  = $pageRange[count($pageRange) - 1] ?? $this->totalPages;

        // First link + leading ellipsis, only when page 1 isn't already visible
        if ($firstInRange > 1) {
            $output .= "<li{$itemAttr}><a href='{$this->buildUrl(1, $baseUrl)}' class='{$linkClasses}'>First</a></li>";
            if ($firstInRange > 2) {
                $output .= "<li{$itemAttr}><span class='{$ellipsisClasses}'>&hellip;</span></li>";
            }
        }

        // Page number links
        foreach ($pageRange as $i) {
            if ($i === $this->currentPage) {
                $output .= "<li{$itemAttr}><span class='{$activeClasses}'>{$i}</span></li>";
            } else {
                $output .= "<li{$itemAttr}><a href='{$this->buildUrl($i, $baseUrl)}' class='{$linkClasses}'>{$i}</a></li>";
            }
        }

        // Trailing ellipsis + Last link, only when the last page isn't already visible
        if ($lastInRange < $this->totalPages) {
            if ($lastInRange < $this->totalPages - 1) {
                $output .= "<li{$itemAttr}><span class='{$ellipsisClasses}'>&hellip;</span></li>";
            }
            $output .= "<li{$itemAttr}><a href='{$this->buildUrl($this->totalPages, $baseUrl)}' class='{$linkClasses}'>Last</a></li>";
        }

        // Next button
        if ($this->currentPage < $this->totalPages) {
            $nextPage  = $this->currentPage + 1;
            $output   .= "<li{$itemAttr}><a href='{$this->buildUrl($nextPage, $baseUrl)}' class='{$linkClasses}'>Next</a></li>";
        } else {
            $output .= "<li{$itemAttr}><span class='{$disabledClasses}'>Next</span></li>";
        }

        $output .= '</ul></nav>';

        if ($showJumpMenu) {
            $output .= $this->buildJumpMenuMarkup($cssClasses, $baseUrl);
        }

        if ($cssClasses['container'] !== '') {
            $output = "<div class='{$cssClasses['container']}'>{$output}</div>";
        }

        return $output;
    }

    /**
     * Renders just the jump-to-page <select> (and its "Go" fallback
     * button), independent of render() — for a custom template that
     * wants to place it somewhere other than immediately after the nav.
     * Returns an empty string when there's only one page, matching
     * render()'s behavior.
     */
    public function renderJumpMenu(): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }

        return $this->buildJumpMenuMarkup($this->cssClasses, $this->baseUrl);
    }

    private function buildJumpMenuMarkup(array $cssClasses, ?string $baseUrl): string
    {
        $formClass   = $cssClasses['jumpForm'];
        $labelClass  = $cssClasses['jumpLabel'];
        $menuClass   = $cssClasses['jumpMenu'];
        $buttonClass = $cssClasses['jumpButton'];

        $path = $baseUrl !== null ? $baseUrl : '';

        // Preserve every current query param (other than page) as hidden
        // inputs, so a no-JS form submit doesn't drop them.
        $queryParams = $_GET;
        unset($queryParams['page']);

        $hiddenInputs = '';
        foreach ($queryParams as $key => $value) {
            $hiddenInputs .= "<input type='hidden' name='" . htmlspecialchars((string) $key) . "' value='" . htmlspecialchars((string) $value) . "'>";
        }

        $optionsMarkup = '';
        for ($i = 1; $i <= $this->totalPages; $i++) {
            $selected       = $i === $this->currentPage ? ' selected' : '';
            $optionsMarkup .= "<option value='{$i}'{$selected}>{$i}</option>";
        }

        return "<form method='get' action='{$path}' class='{$formClass}'>"
            . $hiddenInputs
            . "<label for='rhapsody-pagination-jump' class='{$labelClass}'>Jump to page:</label>"
            . "<select name='page' id='rhapsody-pagination-jump' class='{$menuClass}' onchange='this.form.submit()'>{$optionsMarkup}</select>"
            . "<button type='submit' class='{$buttonClass}'>Go</button>"
            . '</form>';
    }

    /**
     * Renders a custom Twig template with this Pagination instance
     * available as `pagination`, so the template can call
     * `pagination.pageRange`, `pagination.previousPage`,
     * `pagination.nextPage`, and `pagination.url(3)` directly (all plain
     * getter/method calls Twig resolves via its normal object-attribute
     * access). The object is passed rather than an array of primitives
     * specifically so `pagination.url(3)` works — Twig's dot-notation
     * ignores arguments when the left-hand side is a plain array, so
     * only an object supports a callable-with-arguments template
     * property.
     *
     * Requires a bootstrapped container (Container::setInstance() must
     * have been called during bootstrap) so the framework's configured
     * Twig\Environment can be resolved — unlike render(), which has no
     * dependencies at all.
     *
     * @param  string            $template Twig template name (e.g. "@app/pagination.twig")
     * @param  array<string,     mixed>    $extraVars Additional variables merged into the template context
     * @throws \RuntimeException If no container has been registered via Container::setInstance()
     * @return string
     */
    public function renderTemplate(string $template, array $extraVars = []): string
    {
        if (! Container::hasInstance()) {
            throw new \RuntimeException(
                'Pagination::renderTemplate() requires a bootstrapped container. Call Container::setInstance() during bootstrap before rendering a custom pagination template.'
            );
        }

        /**
         * @var Environment $twig
         */
        $twig = Container::getInstance()->get(Environment::class);

        return $twig->render($template, array_merge(['pagination' => $this], $extraVars));
    }
}
