<?php
namespace Rhapsody\Core;

class Request
{
    private readonly array $getParams;
    private readonly array $postParams;
    private readonly array $cookies;
    private readonly array $files;
    private readonly array $server;
    public readonly string $uri;

    public function __construct()
    {
        // Automatically sanitize all incoming $_GET data immediately on initialization
        $this->getParams  = $this->sanitizeArray($_GET);
        $this->postParams = $_POST;
        $this->cookies    = $_COOKIE;
        $this->files      = $_FILES;
        $this->server     = $_SERVER;
        $this->uri        = $_SERVER['REQUEST_URI'] ?? '/';
    }

    /**
     * Internal helper to sanitize an input array against basic XSS and Null-Byte injection.
     */
    private function sanitizeArray(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                // Eliminate potential hidden null-byte characters
                $value = str_replace(chr(0), '', $value);
                // Sanitize special characters
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    public function getMethod(): string
    {
        return strtolower($this->server['REQUEST_METHOD'] ?? 'get');
    }

    /**
     * Strips the APP_BASE_URL prefix and query string from the URI
     * to produce a clean path for the router.
     */
    public function getPath(): string
    {
        $path     = $this->server['REQUEST_URI'] ?? '/';
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }

        $baseUrl = $_ENV['APP_BASE_URL'] ?? '';

        if (! empty($baseUrl) && $baseUrl !== '/' && str_starts_with($path, $baseUrl)) {
            $path = substr($path, strlen($baseUrl));
        }

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return empty($path) ? '/' : $path;
    }

    /**
     * Gets the request body, supporting both traditional form data and JSON payloads.
     */
    public function getBody(): array
    {
        if (isset($this->server['CONTENT_TYPE']) && str_contains(strtolower($this->server['CONTENT_TYPE']), 'application/json')) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            return is_array($data) ? $data : [];
        }

        if ($this->getMethod() === 'post' && ! empty($this->postParams)) {
            return $this->postParams;
        }

        $input = file_get_contents('php://input');
        if (! empty($input) && str_contains($input, '=')) {
            parse_str($input, $data);
            return $data;
        }

        return [];
    }

    /**
     * Fetch a general fallback parameter (reads from the already-sanitized getParams pool).
     */
    public function get(string $key, $default = null)
    {
        return $this->getParams[$key] ?? $default;
    }

    /**
     * Fetch a POST parameter.
     */
    public function post(string $key, $default = null)
    {
        return $this->postParams[$key] ?? $default;
    }

    /**
     * Fetch a single query parameter. Now automatically safe!
     * * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getQueryParam(string $key, $default = null)
    {
        return $this->getParams[$key] ?? $default;
    }

    /**
     * Fetch all query parameters as an associative array. Now automatically safe!
     * * @return array
     */
    public function allQueryParams(): array
    {
        return $this->getParams;
    }

    /**
     * Kept for backwards-compatibility context. Returns the pre-sanitized array.
     * * @return array
     */
    public function safeQueryParams(): array
    {
        return $this->getParams;
    }

    /**
     * Return raw, unfiltered query parameters explicitly if a developer ever needs them.
     * Essential for cases where raw markup/HTML is intentionally allowed via a trusted query string.
     * * @return array
     */
    public function rawQueryParams(): array
    {
        return $_GET;
    }

    /**
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }
}
