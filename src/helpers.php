<?php

use Rhapsody\Core\RedirectResponse;

if (! function_exists('redirect')) {
    /**
     * @param string $url          Root-relative path ("/login") or absolute URL
     * @param int    $redirectCode HTTP redirect status (default 302)
     */
    function redirect(string $url, int $redirectCode = 302): RedirectResponse
    {
        // Absolute (https://...) and protocol-relative (//host) URLs pass through untouched
        if (! preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url)) {
            $base = rtrim($_ENV['APP_BASE_URL'] ?? '', '/');
            $url  = $base . '/' . ltrim($url, '/');
        }

        return new RedirectResponse($url, $redirectCode);
    }
}

// --- NEW: Debugging Helpers ---

if (! function_exists('dd')) {
    /**
     * Dump one or more variables and stop script execution.
     *
     * @param  mixed  ...$vars
     * @return void
     */
    function dd(...$vars): void
    {
        foreach ($vars as $var) {
            dump($var); // Use Symfony's dump if available, otherwise var_dump
        }
        die(1);
    }
}

if (! function_exists('d')) {
    /**
     * Dump one or more variables (without stopping).
     *
     * @param  mixed  ...$vars
     * @return void
     */
    function d(...$vars): void
    {
        foreach ($vars as $var) {
            dump($var);
        }
    }
}

// Optional: Use Symfony VarDumper if installed (it usually is via Whoops)
if (! function_exists('dump') && class_exists('Symfony\Component\VarDumper\VarDumper')) {
    function dump(...$vars)
    {
        foreach ($vars as $var) {
            Symfony\Component\VarDumper\VarDumper::dump($var);
        }
    }
} elseif (! function_exists('dump')) {
    // Fallback to simple var_dump
    function dump(...$vars)
    {
        foreach ($vars as $var) {
            var_dump($var);
        }
    }
}
