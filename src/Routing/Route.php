<?php
namespace Rhapsody\Core\Routing;

class Route
{
    protected string $method;
    protected string $path;
    protected $callback;
    protected array $middleware = [];
    protected array $params     = [];
    protected ?string $name     = null;

    public function __construct(string $method, string $path, $callback)
    {
        $this->method   = $method;
        $this->path     = $path;
        $this->callback = $callback;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function middleware(string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Check if this route matches the given HTTP method and URI.
     * If it matches, extracts dynamic parameters and stores them in $this->params.
     *
     * @param string $method  The request method (e.g. GET, POST).
     * @param string $uri     The request path (e.g. /user/123).
     * @return bool           True if the route matches, false otherwise.
     */
    public function matches(string $method, string $uri): bool
    {
        // 1. Method mismatch?
        if ($this->method !== strtoupper($method)) {
            return false;
        }

        // 2. Convert route path to a regular expression.
        //    Replace {parameter} with ([^/]+) and escape all literal
        //    characters so regex metacharacters in the path (e.g. a literal
        //    '.') are matched literally rather than as regex syntax.
        $pattern = preg_replace_callback('/\{[a-zA-Z0-9_]+\}|[^{}]+/', function (array $m) {
            if (preg_match('/^\{[a-zA-Z0-9_]+\}$/', $m[0])) {
                return '([^/]+)';
            }
            return preg_quote($m[0], '#');
        }, $this->path);
        $pattern = '#^' . $pattern . '$#';

        // 3. Test the URI against the pattern.
        if (preg_match($pattern, $uri, $matches)) {
            // Remove the full match (index 0) – the rest are the parameter values.
            array_shift($matches);
            $this->params = $matches;
            return true;
        }

        return false;
    }

    // --- Named route support ---
    public function name(string $name): self
    {
        $this->name = $name;
        // Register this route with the Router's static named route collection.
        // (Make sure Router::addNamedRoute exists, or adjust accordingly.)
        Router::addNamedRoute($name, $this);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
