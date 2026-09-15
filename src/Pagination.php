<?php
namespace Rhapsody\Core;

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
     * CSS classes applied to the rendered markup. Defaults match the
     * original hardcoded render() output exactly, so calling code that
     * never touches setCssClasses() sees no visual change.
     */
    protected array $cssClasses = [
        'container' => '',
        'list'      => 'flex items-center space-x-2',
        'item'      => '',
        'link'      => 'px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600',
        'disabled'  => 'px-4 py-2 bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md text-gray-400 dark:text-gray-500 cursor-not-allowed',
        'active'    => 'px-4 py-2 bg-blue-500 dark:bg-blue-600 text-white border border-blue-500 dark:border-blue-600 rounded-md',
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
     * any of: container, list, item, link, disabled, active. Unspecified
     * keys keep their default value.
     */
    public function setCssClasses(array $classes): self
    {
        $this->cssClasses = array_merge($this->cssClasses, $classes);
        return $this;
    }

    public function render(): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }

        $queryParams = $_GET;
        unset($queryParams['page']);
        $queryString = http_build_query($queryParams);
        $queryString = $queryString ? "&{$queryString}" : "";

        $path = $this->baseUrl !== null ? $this->baseUrl : '';

        $listClass       = $this->cssClasses['list'];
        $itemClass       = $this->cssClasses['item'];
        $linkClasses     = $this->cssClasses['link'];
        $disabledClasses = $this->cssClasses['disabled'];
        $activeClasses   = $this->cssClasses['active'];

        $itemAttr = $itemClass !== '' ? " class='{$itemClass}'" : '';

        $output = "<nav aria-label=\"Page navigation\"><ul class='{$listClass}'>";

        // Previous button
        if ($this->currentPage > 1) {
            $prevPage  = $this->currentPage - 1;
            $output   .= "<li{$itemAttr}><a href='{$path}?page={$prevPage}{$queryString}' class='{$linkClasses}'>Previous</a></li>";
        } else {
            $output .= "<li{$itemAttr}><span class='{$disabledClasses}'>Previous</span></li>";
        }

        // Page number links
        for ($i = 1; $i <= $this->totalPages; $i++) {
            if ($i === $this->currentPage) {
                $output .= "<li{$itemAttr}><span class='{$activeClasses}'>{$i}</span></li>";
            } else {
                $output .= "<li{$itemAttr}><a href='{$path}?page={$i}{$queryString}' class='{$linkClasses}'>{$i}</a></li>";
            }
        }

        // Next button
        if ($this->currentPage < $this->totalPages) {
            $nextPage  = $this->currentPage + 1;
            $output   .= "<li{$itemAttr}><a href='{$path}?page={$nextPage}{$queryString}' class='{$linkClasses}'>Next</a></li>";
        } else {
            $output .= "<li{$itemAttr}><span class='{$disabledClasses}'>Next</span></li>";
        }

        $output .= '</ul></nav>';

        if ($this->cssClasses['container'] !== '') {
            $output = "<div class='{$this->cssClasses['container']}'>{$output}</div>";
        }

        return $output;
    }
}
