<?php
namespace Rhapsody\Core\Middleware;

use Rhapsody\Core\Request;
use Rhapsody\Core\Response;
use Rhapsody\Core\Services\RateLimiter;

class DdosMiddleware
{
    protected RateLimiter $limiter;
    protected array $config;

    public function __construct(RateLimiter $limiter, array $config)
    {
        $this->limiter = $limiter;
        $this->config  = $config;
    }

    public function handle(Request $request): ?Response
    {
        // Skip if protection is disabled
        if (empty($this->config['ddos_enabled'])) {
            return null;
        }

        $clientIp = $this->getClientIp($request);

        // Whitelist
        $whitelist = $this->config['ddos_whitelist'] ?? [];
        if (in_array($clientIp, $whitelist)) {
            return null;
        }

        // Blacklist
        $blacklist = $this->config['ddos_blacklist'] ?? [];
        if (in_array($clientIp, $blacklist)) {
            return $this->blockedResponse($clientIp);
        }
        $max           = (int) ($this->config['ddos_max_requests'] ?? 60);
        $window        = (int) ($this->config['ddos_time_window'] ?? 60);
        $blockDuration = (int) ($this->config['ddos_block_duration'] ?? 300);
        $result        = $this->limiter->attempt($clientIp, $max, $window, $blockDuration);
        // Attempt rate limiting
        // $result = $this->limiter->attempt(
        //     $clientIp,
        //     $this->config['ddos_max_requests'] ?? 60,
        //     $this->config['ddos_time_window'] ?? 60,
        //     $this->config['ddos_block_duration'] ?? 300
        // );

        if (! $result['allowed']) {
            return $this->rateLimitExceededResponse($result['retry_after']);
        }

        return null;
    }

    protected function getClientIp(Request $request): string
    {
        $server = $request->server ?? $_SERVER; // fallback
        $ip     = $server['HTTP_CLIENT_IP'] ?? $server['HTTP_X_FORWARDED_FOR'] ?? $server['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip  = trim($ips[0]);
        }
        return $ip;
    }

    protected function rateLimitExceededResponse(int $retryAfter): Response
    {
        $response = new Response();
        $response->setStatusCode(429);
        $response->setHeader('Content-Type', 'text/html');
        $response->setHeader('Retry-After', $retryAfter);

        $html = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Too Many Requests</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f7f9fc;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
                padding: 20px;
                box-sizing: border-box;
            }
            .error-container {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 8px 30px rgba(0,0,0,0.12);
                padding: 40px;
                max-width: 500px;
                width: 100%;
                text-align: center;
            }
            h1 {
                font-size: 28px;
                color: #e74c3c;
                margin-top: 0;
                margin-bottom: 10px;
            }
            .emoji {
                font-size: 60px;
                display: block;
                margin-bottom: 10px;
            }
            p {
                color: #555;
                line-height: 1.6;
                margin: 15px 0;
            }
            .retry-info {
                background: #f1f3f5;
                padding: 12px;
                border-radius: 8px;
                font-size: 14px;
                color: #333;
                margin-top: 20px;
            }
            .retry-info strong {
                color: #e74c3c;
            }
            a {
                display: inline-block;
                margin-top: 20px;
                color: #3498db;
                text-decoration: none;
                font-weight: 600;
            }
            a:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <span class="emoji">⏳</span>
            <h1>Too Many Requests</h1>
            <p>You have sent too many requests in a short period. Please wait a moment before trying again.</p>
            <div class="retry-info">
                <strong>Retry after:</strong> <span id="retry-seconds">{$retryAfter}</span> seconds
            </div>
            <a href="javascript:location.reload()">Try again</a>
        </div>
        <script>
            // Simple countdown to show remaining time
            let seconds = {$retryAfter};
            const el = document.getElementById('retry-seconds');
            const interval = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(interval);
                    el.textContent = '0 (ready)';
                    location.reload();
                } else {
                    el.textContent = seconds;
                }
            }, 1000);
        </script>
    </body>
    </html>
    HTML;

        $response->setContent($html);
        return $response;
    }

    protected function blockedResponse(string $ip): Response
    {
        $response = new Response();
        $response->setStatusCode(403);
        $response->setContent('Access denied.');
        return $response;
    }
}
