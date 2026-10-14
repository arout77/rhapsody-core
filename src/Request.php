<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Support\Json;

class Request
{
    /** @var array<string, mixed> */
    private readonly array $getParams;
    /** @var array<string, mixed> */
    private readonly array $postParams;
    /** @var array<string, mixed> */
    private readonly array $files;
    /** @var array<string, mixed> */
    private readonly array $server;
    private readonly string $rawBody;
    public readonly string $uri;

    /**
     * @param string|null               $rawBody The raw request body. When omitted, falls back
     *                                           to reading php://input once (the classic
     *                                           per-request SAPI behavior). A persistent-worker
     *                                           front controller (e.g. a RoadRunner adapter)
     *                                           should pass the body it received over the wire
     *                                           here instead, since php://input is not
     *                                           populated per-iteration outside a real SAPI
     *                                           request.
     * @param array<string, mixed>|null $get     Overrides $_GET (defaults to the superglobal)
     * @param array<string, mixed>|null $post    Overrides $_POST
     * @param array<string, mixed>|null $files   Overrides $_FILES
     * @param array<string, mixed>|null $server  Overrides $_SERVER
     */
    public function __construct(
        ?string $rawBody = null,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null,
        ?array $server = null,
    ) {
        $this->getParams  = $get ?? $_GET;
        $this->postParams = $post ?? $_POST;
        $this->files      = $files ?? $_FILES;
        $this->server     = $server ?? $_SERVER;
        $this->uri        = $this->serverString('REQUEST_URI', '/');
        $this->rawBody    = $rawBody ?? (file_get_contents('php://input') ?: '');
    }

    /**
     * Returns the server/environment data captured when this Request was constructed.
     *
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->server;
    }

    /**
     * Automatically generates a clean, standardized canonical URL tag.
     * Filters out non-structural parameters to prevent duplicate content penalties.
     *
     * @param  list<string> $allowedParams Query parameters allowed to persist in the canonical link (e.g., 'page')
     * @return string
     */
    public function getCanonicalUrl(array $allowedParams = ['page']): string
    {
        // 1. Determine the active server protocol securely
        $https    = $this->serverString('HTTPS');
        $protocol = (! empty($https) && $https !== 'off') ? 'https://' : 'http://';

        // 2. Capture the exact host domain
        $host = $this->serverString('HTTP_HOST', 'localhost');

        // 3. Extract only the clean structural path mapping (removes raw text strings)
        $path = (string) parse_url($this->uri, PHP_URL_PATH);

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
        return strtolower($this->serverString('REQUEST_METHOD', 'get'));
    }

    /**
     * Strips the APP_BASE_URL prefix and query string from the URI
     * to produce a clean path for the router.
     */
    public function getPath(): string
    {
        $path     = $this->uri;
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }

        $baseUrl = $_ENV['APP_BASE_URL'] ?? '';
        $baseUrl = is_string($baseUrl) ? $baseUrl : '';

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
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        // 1. JSON content type (always attempt, regardless of HTTP method)
        if (str_contains(strtolower($this->serverString('CONTENT_TYPE')), 'application/json')) {
            $data = json_decode($this->rawBody, true);
            if (! is_array($data)) {
                return [];
            }

            /** @var array<string, mixed> $data */
            return $data;
        }

        // 2. For POST requests with form data, return $_POST
        if ($this->getMethod() === 'post' && ! empty($this->postParams)) {
            return $this->postParams;
        }

        // 3. Fallback: parse the captured raw body for PUT/PATCH/DELETE with application/x-www-form-urlencoded
        if (! empty($this->rawBody) && str_contains($this->rawBody, '=')) {
            parse_str($this->rawBody, $data);

            /** @var array<string, mixed> $data */
            return $data;
        }

        return [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getParams[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $default;
    }

    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        return filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS) ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function allQueryParams(): array
    {
        return $this->getParams;
    }

    /**
     * Get a request header by name (case-insensitive).
     */
    public function header(string $name, mixed $default = null): mixed
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
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Get a single value from the request body (POST fields or JSON payload —
     * see getBody()), falling back to the query string if not present there.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->getBody();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $this->getParams[$key] ?? $default;
    }

    /**
     * Build a Request without touching the PHP superglobals (for tests and tooling).
     *
     * GET/HEAD: $parameters go in the query string. JSON content type: $parameters
     * are sent as the JSON body. POST: form fields. PUT/PATCH/DELETE: urlencoded body.
     *
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $uri, array $parameters = [], array $headers = []): self
    {
        $method = strtoupper($method);
        $isRead = in_array($method, ['GET', 'HEAD'], true);

        $queryPos = strpos($uri, '?');
        $path     = $queryPos === false ? $uri : substr($uri, 0, $queryPos);
        $query    = [];
        if ($queryPos !== false) {
            parse_str(substr($uri, $queryPos + 1), $query);
        }
        /** @var array<string, mixed> $query */

        $server = ['REQUEST_METHOD' => $method, 'HTTP_HOST' => 'localhost'];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        $get     = $isRead ? array_merge($query, $parameters) : $query;
        $post    = [];
        $rawBody = '';
        if (! $isRead) {
            if (str_contains(strtolower($server['CONTENT_TYPE'] ?? ''), 'application/json')) {
                $rawBody = Json::encode($parameters);
            } elseif ($method === 'POST') {
                $post = $parameters;
            } else {
                $rawBody = http_build_query($parameters);
            }
        }

        $server['REQUEST_URI'] = $get === [] ? $path : $path . '?' . http_build_query($get);

        return new self($rawBody, $get, $post, [], $server);
    }

    /** String input (body, then query string). Scalars are cast; missing/array values give $default. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Integer input. Accepts ints and integer-like strings; anything else gives $default. */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            if ($filtered !== false) {
                return $filtered;
            }
        }

        return $default;
    }

    /** Boolean input. Accepts true/false, 1/0, 'true'/'false', 'on'/'off', 'yes'/'no'. */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $default;
    }

    /**
     * Array input (e.g. `tags[]=a&tags[]=b`). Non-array values give an empty array.
     *
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->input($key);

        return is_array($value) ? $value : [];
    }

    /**
     * Get all input: the request body merged with the query string
     * (body values take precedence).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->getParams, $this->getBody());
    }

    /**
     * Determine if the request's path matches a given pattern.
     * Supports a trailing wildcard, e.g. 'api/*' matches 'api/users', 'api/foo/bar', etc.
     *
     * @param  string $pattern
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

    /**
     * Whether the client is requesting a JSON response, based on the
     * Accept header — the convention AJAX pagination (and any other
     * fetch()-driven endpoint) uses to distinguish an AJAX request from
     * a normal navigation.
     *
     * @return bool
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('Accept', '');

        return is_string($accept) && str_contains($accept, 'application/json');
    }

    /**
     * Read a $_SERVER value as a string, falling back to $default when the
     * key is missing or holds a non-string value.
     */
    private function serverString(string $key, string $default = ''): string
    {
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
