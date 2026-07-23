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
        $this->getParams  = $_GET;
        $this->postParams = $_POST;
        $this->cookies    = $_COOKIE;
        $this->files      = $_FILES;
        $this->server     = $_SERVER;
        $this->uri        = $_SERVER['REQUEST_URI'] ?? '/';
    }

    /**
     * Returns the server/environment data captured when this Request was constructed.
     */
    public function getServerParams(): array
    {
        return $this->server;
    }

    /**
     * Automatically generates a clean, standardized canonical URL tag.
     * Filters out non-structural parameters to prevent duplicate content penalties.
     *
     * @param array $allowedParams Query parameters allowed to persist in the canonical link (e.g., 'page')
     * @return string
     */
    public function getCanonicalUrl(array $allowedParams = ['page']): string
    {
        // 1. Determine the active server protocol securely
        $protocol = (! empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https://' : 'http://';

        // 2. Capture the exact host domain
        $host = $this->server['HTTP_HOST'] ?? 'localhost';

        // 3. Extract only the clean structural path mapping (removes raw text strings)
        $path = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // 4. Isolate tracking keys using our pre-sanitized array snapshot
        $filteredParams = array_intersect_key(
            $this->getParams,
            array_flip($allowedParams)
        );

        // 5. Reassemble query structure cleanly if allowed criteria are present
        $queryString = ! empty($filteredParams) ? '?' . http_build_query($filteredParams) : '';

        return $protocol . $host . $path . $queryString;
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
     * Returns raw values — sanitization is the responsibility of the Validator or controller.
     * Behaviour is now consistent regardless of how the client sends data.
     *
     * @return array
     */
    public function getBody(): array
    {
        // 1. JSON content type (always attempt, regardless of HTTP method)
        if (isset($this->server['CONTENT_TYPE']) && str_contains(strtolower($this->server['CONTENT_TYPE']), 'application/json')) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            return is_array($data) ? $data : [];
        }

        // 2. For POST requests with form data, return $_POST
        if ($this->getMethod() === 'post' && ! empty($this->postParams)) {
            return $this->postParams;
        }

        // 3. Fallback: parse php://input for PUT/PATCH/DELETE with application/x-www-form-urlencoded
        $input = file_get_contents('php://input');
        if (! empty($input) && str_contains($input, '=')) {
            parse_str($input, $data);
            return $data;
        }

        return [];
    }

    /**
     * @param string $key
     * @param $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->getParams[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param $default
     * @return mixed
     */
    public function post(string $key, $default = null)
    {
        return $this->postParams[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param $default
     */
    public function getQueryParam(string $key, $default = null)
    {
        return filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS) ?? $default;
    }

    /**
     * @param string $key
     * @param $default
     */
    public function allQueryParams(string $key, $default = null)
    {
        return $this->getParams;
    }

    /**
     * Get a request header by name (case-insensitive).
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function header(string $name, $default = null)
    {
        // PHP exposes headers as HTTP_X_FOO_BAR in $_SERVER, except
        // Content-Type/Content-Length which have no HTTP_ prefix.
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return $this->server[$key];
        }

        $key = strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    /**
     * Get the raw request body (e.g. for webhook signature verification,
     * where the exact bytes matter and can't go through getBody()'s parsing).
     *
     * @return string
     */
    public function getContent(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * @return mixed
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Get a single value from the request body (POST fields or JSON payload —
     * see getBody()), falling back to the query string if not present there.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $body = $this->getBody();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $this->getParams[$key] ?? $default;
    }

    /**
     * Get all input: the request body merged with the query string
     * (body values take precedence).
     *
     * @return array
     */
    public function all(): array
    {
        return array_merge($this->getParams, $this->getBody());
    }

    /**
     * Determine if the request's path matches a given pattern.
     * Supports a trailing wildcard, e.g. 'api/*' matches 'api/users', 'api/foo/bar', etc.
     *
     * @param string $pattern
     * @return bool
     */
    public function is(string $pattern): bool
    {
        // getPath() returns a leading-slash path (e.g. '/api/users'); patterns
        // are written without the leading slash (e.g. 'api/*'), so normalize both.
        $path    = ltrim($this->getPath(), '/');
        $pattern = ltrim($pattern, '/');

        if ($pattern === $path) {
            return true;
        }

        // Turn the wildcard pattern into a regex: escape everything except '*',
        // which becomes '.*'.
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

        return (bool) preg_match($regex, $path);
    }
}
