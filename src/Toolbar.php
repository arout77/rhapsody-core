<?php
// core/Toolbar.php

namespace Rhapsody\Core;

use Rhapsody\Core\TraceablePDO;

class Toolbar
{
    /**
     * @param array $data
     */
    public function __construct(protected array $data)
    {}

    public function render(): string
    {
        // --- FALLBACK: if no queries were provided, get them from TraceablePDO ---
        if (empty($this->data['queries'])) {
            $this->data['queries']       = TraceablePDO::getQueryLog();
            $this->data['total_queries'] = count($this->data['queries']);

            // Also compute N+1 count (optional, but helps the panel)
            $sqlCounts = [];
            foreach ($this->data['queries'] as &$q) {
                $sql = $q['sql'] ?? '';
                // Same normalization as Debug::end(), so both panels agree.
                $normalized               = preg_replace('/\b\d+\b/', '?', $sql);
                $normalized               = preg_replace("/'[^']*'/", '?', $normalized);
                $normalized               = preg_replace('/\s+/', ' ', $normalized);
                $sqlCounts[$normalized][] = &$q;
            }
            unset($q);

            $nPlusOneAlerts = [];
            foreach ($sqlCounts as $queries) {
                if (count($queries) > 3) {
                    foreach ($queries as &$q) {
                        $q['is_n_plus_1'] = true;
                        $nPlusOneAlerts[] = $q;
                    }
                    unset($q);
                }
            }
            $this->data['n_plus_one_alerts'] = $nPlusOneAlerts;
            $this->data['n_plus_1_count']    = count($nPlusOneAlerts);
        }
        // --- Data Preparation for Toolbar Header ---
        $appVersion   = htmlspecialchars($this->data['app_version'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $execTime     = $this->data['execution_time'] ?? '0';
        $memUsage     = $this->data['memory_usage'] ?? '0';
        $responseCode = $this->data['response_code'] ?? 'N/A';
        $queryCount   = $this->data['total_queries'] ?? count($this->data['queries'] ?? []);
        $nPlusOne     = $this->data['n_plus_1_count'] ?? 0;

        // --- Prepare performance data for React component ---
        $performanceProps = json_encode([
            'time'     => (float) $execTime,
            'memory'   => (float) $memUsage,
            'queries'  => $queryCount,
            'route'    => $this->data['route'] ?? null,
            'nPlusOne' => $this->data['n_plus_one_alerts'] ?? [],
            'cache'    => $this->data['cache_stats'] ?? ['hits' => 0, 'misses' => 0, 'ratio' => 0],
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // --- Data Preparation for React Island (Memory Profiler) ---
        $memoryPropsJson = json_encode([
            'memory' => $this->data['memory'] ?? [
                'used_mb'  => $memUsage,
                'limit_mb' => 'N/A',
                'percent'  => 0,
                'status'   => 'ok',
            ],
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // --- Enhanced Request Panel ---
        $req         = $this->data['request'] ?? [];
        $requestHtml = '<h3 class="text-white text-lg font-bold mb-4">Request Details</h3>';

        // Overview
        $requestHtml .= '<div class="bg-gray-800 rounded p-3 mb-3">';
        $requestHtml .= '<table class="toolbar-table">';
        $requestHtml .= '<tr><td class="label">Method</td><td class="value"><span class="badge badge-method">' . htmlspecialchars($req['method'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
        $requestHtml .= '<tr><td class="label">Full URL</td><td class="value"><code>' . htmlspecialchars($req['full_url'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
        $requestHtml .= '<tr><td class="label">Client IP</td><td class="value">' . htmlspecialchars($req['ip'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $requestHtml .= '<tr><td class="label">Timestamp</td><td class="value">' . htmlspecialchars($req['timestamp'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $requestHtml .= '</table></div>';

        // Route info (with middleware list)
        $routeData   = $this->data['route'] ?? null;
        $requestHtml .= '<h4 class="text-gray-300 font-bold mt-3 mb-2">Route Details</h4>';
        if ($routeData) {
            $requestHtml .= '<div class="bg-gray-800 rounded p-3 mb-3">';
            $requestHtml .= '<table class="toolbar-table">';
            $requestHtml .= '<tr><td class="label">Path</td><td class="value"><code>' . htmlspecialchars($routeData['path'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
            $requestHtml .= '<tr><td class="label">Controller</td><td class="value">' . htmlspecialchars($routeData['controller'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</td></tr>';
            $requestHtml .= '<tr><td class="label">Action</td><td class="value">' . htmlspecialchars($routeData['action'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</td></tr>';
            if (! empty($routeData['params'])) {
                $params   = is_array($routeData['params']) ? $routeData['params'] : json_decode($routeData['params'], true);
                $paramStr = '';
                foreach ($params as $key => $val) {
                    $paramStr .= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ': <span class="text-cyan-300">' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '</span> ';
                }
                $requestHtml .= '<tr><td class="label">Parameters</td><td class="value">' . $paramStr . '</td></tr>';
            }
            $middlewareList = $req['middleware'] ?? [];
            if (! empty($middlewareList)) {
                $mwStr = implode(' → ', array_map(function ($mw) {
                    return '<span class="text-purple-400">' . htmlspecialchars($mw, ENT_QUOTES, 'UTF-8') . '</span>';
                }, $middlewareList));
                $requestHtml .= '<tr><td class="label">Middleware</td><td class="value">' . $mwStr . '</td></tr>';
            }
            $requestHtml .= '</table></div>';
        } else {
            $requestHtml .= '<p class="text-gray-400">No route matched for this request.</p>';
        }

        // Headers
        $headers     = $req['headers'] ?? [];
        $requestHtml .= '<h4 class="text-gray-300 font-bold mt-3 mb-2">Request Headers</h4>';
        if (empty($headers)) {
            $requestHtml .= '<p class="text-gray-400">No headers available.</p>';
        } else {
            $requestHtml .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto mb-3">';
            $requestHtml .= '<table class="toolbar-table">';
            foreach ($headers as $key => $value) {
                $requestHtml .= '<tr><td class="label">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</td>';
                $requestHtml .= '<td class="value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $requestHtml .= '</table></div>';
        }

        // Query Parameters
        $query = $req['query'] ?? [];
        if (! empty($query)) {
            $requestHtml .= '<h4 class="text-gray-300 font-bold mt-3 mb-2">Query Parameters</h4>';
            $requestHtml .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto mb-3">';
            $requestHtml .= '<table class="toolbar-table">';
            foreach ($query as $key => $value) {
                $requestHtml .= '<tr><td class="label">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</td>';
                $requestHtml .= '<td class="value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $requestHtml .= '</table></div>';
        }

        // Body (raw)
        $body  = $req['body'] ?? null;
        if ($body !== null && $body !== '') {
            $requestHtml .= '<h4 class="text-gray-300 font-bold mt-3 mb-2">Request Body</h4>';
            $decoded      = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                $pretty       = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $requestHtml .= '<pre class="bg-gray-900 text-gray-300 p-3 rounded border border-gray-700 text-sm font-mono">' . htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                $requestHtml .= '<pre class="bg-gray-900 text-gray-300 p-3 rounded border border-gray-700 text-sm font-mono">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>';
            }
        }

        // cURL command copy button
        $curlCommand = $this->generateCurlCommand($req);
        $requestHtml .= '<div class="mt-3 flex items-center gap-3">';
        $requestHtml .= '<button class="copy-curl-btn bg-indigo-600 hover:bg-indigo-700 text-white text-xs px-3 py-1.5 rounded transition-colors" data-curl="' . htmlspecialchars($curlCommand, ENT_QUOTES, 'UTF-8') . '">📋 Copy cURL</button>';
        $requestHtml .= '<span class="text-gray-500 text-xs">Copy a cURL command to reproduce this request</span>';
        $requestHtml .= '</div>';

        // --- Format Session panel ---
        $sessionData = $this->data['session'] ?? [];
        $sessionHtml = '<h3 class="text-white text-lg font-bold mb-4">Session Data</h3>';
        if (! empty($sessionData)) {
            $sessionHtml .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto">';
            $sessionHtml .= '<table class="toolbar-table">';
            foreach ($sessionData as $key => $value) {
                $displayValue  = $this->formatSessionValue($value);
                $sessionHtml  .= '<tr><td class="label">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</td><td class="value">' . $displayValue . '</td></tr>';
            }
            $sessionHtml .= '</table></div>';
        } else {
            $sessionHtml .= '<p class="text-gray-400">No session data available.</p>';
        }

        // --- Cache Panel ---
        $cacheStats  = $this->data['cache'] ?? ['hits' => 0, 'misses' => 0, 'total' => 0, 'ratio' => 0];
        $cacheHtml   = '<h3 class="text-white text-lg font-bold mb-4">Cache Performance</h3>';
        $cacheHtml  .= '<div class="bg-gray-800 rounded p-3">';
        $cacheHtml  .= '<table class="toolbar-table">';
        $cacheHtml  .= '<tr><td class="label">Hits</td><td class="value"><span class="text-green-400">' . $cacheStats['hits'] . '</span></td></tr>';
        $cacheHtml  .= '<tr><td class="label">Misses</td><td class="value"><span class="text-red-400">' . $cacheStats['misses'] . '</span></td></tr>';
        $cacheHtml  .= '<tr><td class="label">Total Requests</td><td class="value">' . $cacheStats['total'] . '</td></tr>';
        $cacheHtml  .= '<tr><td class="label">Hit Ratio</td><td class="value">';
        $ratio       = $cacheStats['ratio'];
        $ratioColor  = $ratio > 70 ? 'text-green-400' : ($ratio > 40 ? 'text-yellow-400' : 'text-red-400');
        $cacheHtml  .= '<span class="' . $ratioColor . ' font-bold">' . $ratio . '%</span>';
        $cacheHtml  .= '<div class="w-full bg-gray-700 rounded-full h-2 mt-1">';
        $cacheHtml  .= '<div class="h-2 rounded-full ' . ($ratio > 70 ? 'bg-green-500' : ($ratio > 40 ? 'bg-yellow-500' : 'bg-red-500')) . '" style="width: ' . min(100, $ratio) . '%;"></div>';
        $cacheHtml  .= '</div>';
        $cacheHtml  .= '</td></tr>';
        $cacheHtml  .= '</table></div>';

        // --- Database panel with N+1 alerts ---
        $dbHtml = '<h3 class="text-white text-lg font-bold mb-4">Database Queries</h3>';
        if ($nPlusOne > 0) {
            $dbHtml .= '<div class="bg-red-900/30 border border-red-700 rounded p-3 mb-3">';
            $dbHtml .= '<span class="text-red-400 font-bold">⚠️ N+1 Query Pattern Detected</span>';
            $dbHtml .= ' <span class="text-gray-300">(' . $nPlusOne . ' repeated query structures)</span>';
            $dbHtml .= '<p class="text-gray-400 text-sm mt-1">Multiple identical queries executed >3 times. Consider eager loading.</p>';
            $dbHtml .= '</div>';
        }
        $dbHtml .= '<div>' . $this->formatQueries() . '</div>';

        // --- Logs panel ---
        $logHtml  = '<h3 class="text-white text-lg font-bold">PHP Error Log</h3><pre>' . ($this->data['logs']['php'] ?? 'Log not available.') . '</pre><h3 class="text-white text-lg font-bold mt-4">Apache Error Log</h3><pre>' . ($this->data['logs']['apache'] ?? 'Log not available.') . '</pre>';

        // --- Environment Panel ---
        $envHtml = $this->formatEnvPanel();

        // --- NEW: Services and Middleware panels ---
        $servicesHtml    = $this->renderServicesPanel();
        $middlewareHtml  = $this->renderMiddlewarePanel();

        // --- Build panels array ---
        $panels_data  = [
            'panel-request'     => $requestHtml,
            'panel-logs'        => $logHtml,
            'panel-db'          => $dbHtml,
            'panel-session'     => $sessionHtml,
            'panel-cache'       => $cacheHtml,
            'panel-performance' => <<<HTML
                <div class="rhapsody-island" data-component="Toolbar/PerformancePanel">
                    <script type="application/json" class="rhapsody-island-props">{$performanceProps}</script>
                </div>
            HTML,
            'panel-env'        => $envHtml,
            'panel-services'   => $servicesHtml,
            'panel-middleware' => $middlewareHtml,
        ];

        $panels_json = json_encode($panels_data);

        // --- CSS for the new tables ---
        $extraCss = <<<CSS
    .toolbar-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .toolbar-table td { padding: 6px 10px; border-bottom: 1px solid #2d3748; }
    .toolbar-table tr:last-child td { border-bottom: none; }
    .toolbar-table .label { color: #9CA3AF; font-weight: 600; width: 30%; vertical-align: top; }
    .toolbar-table .value { color: #E5E7EB; word-break: break-word; }
    .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .badge-method { background: #4f46e5; color: white; }
    .badge-status { background: #10B981; color: white; }
    .session-array { font-family: monospace; font-size: 12px; color: #A5B4FC; }
    .session-array-item { padding-left: 16px; display: block; }
CSS;

        // --- HEREDOC for HTML, CSS, JS ---
        return <<<HTML
<style>
    #rhapsody-debug-toolbar { position: fixed; bottom: 0; left: 0; width: 100%; background-color: #111827; color: #F9FAFB; z-index: 99999; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px; box-shadow: 0 -2px 10px rgba(0,0,0,0.3); }
    #rhapsody-debug-toolbar-header { display: flex; align-items: stretch; height: 40px; }
    #rhapsody-debug-toolbar .toolbar-item { display: flex; align-items: center; padding: 0 15px; border-right: 1px solid #374151; cursor: pointer; }
    #rhapsody-debug-toolbar .toolbar-item:hover { background-color: #1F2937; }
    #rhapsody-debug-toolbar .toolbar-item.active { background-color: #374151; }
    #rhapsody-debug-toolbar .toolbar-label { font-weight: 600; margin-right: 8px; }
    #rhapsody-debug-toolbar .toolbar-value { color: #9CA3AF; }
    #rhapsody-debug-toolbar .toolbar-logo { font-weight: bold; background: #4f46e5; }
    #rhapsody-debug-toolbar .status-ok { color: #10B981; }
    #rhapsody-debug-toolbar-panel { display: none; padding: 20px; background-color: #1F2937; border-top: 1px solid #374151; max-height: 40vh; overflow-y: auto; }
    #rhapsody-debug-toolbar-panel h3 { font-size: 1.5rem; font-weight: bold; border-bottom: 1px solid #4B5563; padding-bottom: 10px; margin: 0 0 15px 0; }
    #rhapsody-debug-toolbar-panel pre { background: #111827; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-break: break-all; }
    .query-item { border-bottom: 1px solid #374151; padding: 10px 0; }
    .query-item:last-child { border-bottom: none; }
    .query-sql { font-family: monospace; color: #A5B4FC; margin-bottom: 5px; }
    .query-meta { font-size: 12px; color: #6B7280; }
    .query-meta span { margin-right: 15px; }
    {$extraCss}
</style>

<div id="rhapsody-debug-toolbar">
    <div id="rhapsody-debug-toolbar-panel"></div>
    <div id="rhapsody-debug-toolbar-header">
        <div class="toolbar-item toolbar-logo" id="toolbar-close-btn">Rhapsody {$appVersion}</div>
        <div class="toolbar-item" data-panel="panel-request"><span class="toolbar-label">Request</span> <span class="toolbar-value status-ok">{$responseCode}</span></div>
        <div class="toolbar-item" data-panel="panel-services"><span class="toolbar-label">Services</span></div>
        <div class="toolbar-item" data-panel="panel-middleware"><span class="toolbar-label">Middleware</span></div>
        <div class="toolbar-item" data-panel="panel-logs"><span class="toolbar-label">Logs</span></div>
        <div class="toolbar-item" data-panel="panel-db"><span class="toolbar-label">Database</span> <span class="toolbar-value">{$queryCount} Queries</span></div>
        <div class="toolbar-item" data-panel="panel-session"><span class="toolbar-label">Session</span></div>
        <div class="toolbar-item" data-panel="panel-cache"><span class="toolbar-label">Cache</span></div>
        <div class="toolbar-item" data-panel="panel-performance"><span class="toolbar-label">Performance</span></div>
        <div class="toolbar-item" data-panel="panel-env"><span class="toolbar-label">Environment</span></div>

        <div style="margin-left: auto; display: flex; align-items: stretch;">
            <div class="toolbar-item"><span class="toolbar-label">Time:</span> <span class="toolbar-value">{$execTime} ms</span></div>

            <div class="rhapsody-island toolbar-item" style="border-right: none; padding: 0;" data-component="Toolbar/MemoryProfiler">
                <script type="application/json" class="rhapsody-island-props">
                    {$memoryPropsJson}
                </script>
            </div>
        </div>
    </div>
</div>

<script>
    // Global copy function for cURL command
    function copyToClipboard(text) {
    text = String(text);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showCopySuccess();
        }).catch((err) => {
            console.error('Clipboard write failed:', err);
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const success = document.execCommand('copy');
        document.body.removeChild(textarea);
        if (success) {
            showCopySuccess();
        } else {
            console.log('cURL command copied to clipboard! (fallback)');
            alert('cURL command copied to clipboard!');
        }
    } catch (err) {
        console.error('Fallback copy error:', err);
        alert('Could not copy. Please copy manually.');
    }
}

function showCopySuccess() {
    // Try the notification system first
    if (typeof showNotification === 'function') {
        showNotification('cURL command copied to clipboard!', 'success');
    } else {
        // Fallback to console and alert
        console.log('cURL command copied to clipboard!');
        alert('cURL command copied to clipboard!');
    }
}

    // Delegate click event for cURL copy buttons (works for dynamically added content)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.copy-curl-btn');
        if (btn) {
            const command = btn.getAttribute('data-curl');
            if (command) {
                copyToClipboard(command);
            } else {
                console.error('No cURL command found on button');
            }
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
        const toolbar = document.getElementById('rhapsody-debug-toolbar');
        const panel = document.getElementById('rhapsody-debug-toolbar-panel');
        const items = document.querySelectorAll('#rhapsody-debug-toolbar-header .toolbar-item[data-panel]');
        const closeBtn = document.getElementById('toolbar-close-btn');
        let activePanel = null;

        const panels = {$panels_json};

        items.forEach(item => {
            item.addEventListener('click', () => {
                const panelId = item.getAttribute('data-panel');
                if (activePanel === panelId) {
                    panel.style.display = 'none';
                    activePanel = null;
                    item.classList.remove('active');
                } else {
                    panel.innerHTML = panels[panelId];
                    panel.style.display = 'block';
                    activePanel = panelId;
                    items.forEach(i => i.classList.remove('active'));
                    item.classList.add('active');

                    // Mount React islands inside the dynamically loaded panel
                    if (typeof window.Rhapsody !== 'undefined' && window.Rhapsody.mountIslands) {
                        setTimeout(() => {
                            window.Rhapsody.mountIslands();
                        }, 50);
                    }
                }
            });
        });

        closeBtn.addEventListener('click', (e) => {
            if (e.target === closeBtn) {
                 panel.style.display = 'none';
                 activePanel = null;
                 items.forEach(i => i.classList.remove('active'));
            }
        });
    });
</script>
HTML;
    }

    // -------------------------------------------------------------------------
    // Helper formatting methods
    // -------------------------------------------------------------------------

    /**
     * Format a session value for display (strings, arrays, objects).
     */
    private function formatSessionValue($value): string
    {
        if (is_array($value)) {
            if (empty($value)) {
                return '<span class="text-gray-400">(empty array)</span>';
            }

            $items = '';
            foreach ($value as $k => $v) {
                $items .= '<span class="session-array-item"><span class="text-cyan-400">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</span>: ' . $this->formatSessionValue($v) . '</span>';
            }
            return '<span class="session-array">' . $items . '</span>';
        }
        if (is_bool($value)) {
            return $value ? '<span class="text-green-400">true</span>' : '<span class="text-red-400">false</span>';
        }
        if (is_null($value)) {
            return '<span class="text-gray-500">null</span>';
        }
        if (is_object($value)) {
            return '<span class="text-yellow-400">' . get_class($value) . ' object</span>';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format the queries panel as a list with better styling.
     */
    private function formatQueries(): string
    {
        $queries = $this->data['queries'] ?? [];
        if (empty($queries)) {
            return '<p class="text-gray-400">No queries were executed for this request.</p>';
        }
        $html = '';
        foreach ($queries as $query) {
            $sql         = htmlspecialchars($query['sql'], ENT_QUOTES, 'UTF-8');
            $params      = isset($query['params']) ? htmlspecialchars(json_encode($query['params']), ENT_QUOTES, 'UTF-8') : '';
            $time        = isset($query['executionMS']) ? round($query['executionMS'] * 1000, 2) : '0';
            $caller      = isset($query['caller']['file']) ? $query['caller']['file'] . ':' . ($query['caller']['line'] ?? '-') : 'N/A';
            $isNPlusOne  = isset($query['is_n_plus_1']) && $query['is_n_plus_1'];
            $warning     = $isNPlusOne ? ' ⚠️' : '';
            $html       .= "<div class='query-item'>";
            $html       .= "<div class='query-sql'>{$sql}{$warning}</div>";
            $html       .= "<div class='query-meta'>";
            $html       .= "<span>Params: {$params}</span>";
            $html       .= "<span>Time: {$time}ms</span>";
            $html       .= "<span style='margin-left: auto;'>{$caller}</span>";
            $html       .= "</div>";
            $html       .= "</div>";
        }
        return $html;
    }

    /**
     * Generate a cURL command from request data.
     */
    private function generateCurlCommand(array $request): string
    {
        $method  = strtoupper($request['method'] ?? 'GET');
        $url     = $request['full_url'] ?? '/';
        $headers = $request['headers'] ?? [];
        $body    = $request['body'] ?? null;

        // Use curl.exe on Windows (PowerShell alias), curl on Unix
        $curlCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'curl.exe' : 'curl';

        $cmd = "{$curlCmd} -X {$method} '" . addslashes($url) . "'";

        foreach ($headers as $key => $value) {
            $cmd .= " -H '" . addslashes($key) . ": " . addslashes($value) . "'";
        }

        if ($body !== null && $body !== '') {
            $escapedBody  = addslashes($body);
            $cmd         .= " -d '" . $escapedBody . "'";
        }

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // Environment Panel helpers
    // -------------------------------------------------------------------------

    /**
     * Format the Environment panel with middleware trace and container inspector.
     */
    private function formatEnvPanel(): string
    {
        $env             = $this->data['env'] ?? [];
        $middlewareTrace = $this->data['middleware_trace'] ?? [];
        $containerTrace  = $this->data['container_trace'] ?? [];

        $html = '<h3 class="text-white text-lg font-bold mb-4">Environment</h3>';

        // --- Environment Variables ---
        $html .= '<h4 class="text-gray-300 font-bold mt-4 mb-2">Environment Variables</h4>';
        if (empty($env)) {
            $html .= '<p class="text-gray-400">No environment variables available.</p>';
        } else {
            ksort($env);
            $html .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto mb-4">';
            $html .= '<table class="toolbar-table">';
            foreach ($env as $key => $value) {
                $displayValue  = $this->formatEnvValue($key, $value);
                $html         .= '<tr><td class="label">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</td>';
                $html         .= '<td class="value">' . $displayValue . '</td></tr>';
            }
            $html .= '</table></div>';
        }

        // --- Middleware Tracer ---
        $html .= '<h4 class="text-gray-300 font-bold mt-4 mb-2">Middleware Tracer</h4>';
        if (empty($middlewareTrace)) {
            $html .= '<p class="text-gray-400">No middleware executed.</p>';
        } else {
            $html                .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto mb-4">';
            $html                .= '<table class="toolbar-table">';
            $html                .= '<tr><td class="label">#</td><td class="label">Type</td><td class="label">Class</td><td class="label">Route</td><td class="label">Duration</td></tr>';
            $totalMiddlewareTime  = 0;
            foreach ($middlewareTrace as $index => $item) {
                $type     = $item['type'] === 'global' ? '<span class="text-blue-400">Global</span>' : '<span class="text-purple-400">Route</span>';
                $route    = $item['route'] ?? '-';
                $duration = $item['duration'] ?? '—';
                if (is_numeric($duration)) {
                    $totalMiddlewareTime += $duration;
                    $color                = $duration > 50 ? 'text-red-400' : ($duration > 20 ? 'text-yellow-400' : 'text-green-400');
                    $duration             = '<span class="' . $color . '">' . $duration . ' ms</span>';
                }
                $html .= '<tr>';
                $html .= '<td class="text-gray-500">' . ($index + 1) . '</td>';
                $html .= '<td>' . $type . '</td>';
                $html .= '<td class="font-mono text-xs">' . htmlspecialchars($this->shortenClass($item['class']), ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td class="text-gray-400">' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . $duration . '</td>';
                $html .= '</tr>';
            }
            $html .= '<tr style="border-top: 2px solid #4B5563;">';
            $html .= '<td colspan="4" class="text-right font-bold text-gray-400">Total Middleware Time:</td>';
            $html .= '<td class="font-bold text-cyan-400">' . round($totalMiddlewareTime, 2) . ' ms</td>';
            $html .= '</tr>';
            $html .= '</table></div>';
        }

        // --- Container Inspector ---
        $html .= '<h4 class="text-gray-300 font-bold mt-4 mb-2">Container Inspector</h4>';
        if (empty($containerTrace)) {
            $html .= '<p class="text-gray-400">No services resolved.</p>';
        } else {
            $html             .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto">';
            $html             .= '<table class="toolbar-table">';
            $html             .= '<tr><td class="label">#</td><td class="label">Service</td><td class="label">Duration</td><td class="label">Called By</td><td class="label">Status</td></tr>';
            $totalResolveTime  = 0;
            foreach ($containerTrace as $index => $item) {
                $duration          = $item['duration'] ?? 0;
                $totalResolveTime += $duration;
                $color             = $duration > 50 ? 'text-red-400' : ($duration > 20 ? 'text-yellow-400' : 'text-green-400');
                $class             = $this->shortenClass($item['class']);
                $calledBy          = $this->shortenClass($item['called_by'] ?? 'unknown');

                if (isset($item['circular']) && $item['circular']) {
                    $status   = '<span class="text-red-400 font-bold">⚠️ Circular</span>';
                    $duration = '—';
                } else {
                    $status   = '<span class="text-green-400">✅ Resolved</span>';
                    $duration = '<span class="' . $color . '">' . $duration . ' ms</span>';
                }

                $html .= '<tr>';
                $html .= '<td class="text-gray-500">' . ($index + 1) . '</td>';
                $html .= '<td class="font-mono text-xs text-cyan-300">' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . $duration . '</td>';
                $html .= '<td class="text-gray-400 text-xs">' . htmlspecialchars($calledBy, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . $status . '</td>';
                $html .= '</tr>';
            }
            $html .= '<tr style="border-top: 2px solid #4B5563;">';
            $html .= '<td colspan="2" class="text-right font-bold text-gray-400">Total Resolve Time:</td>';
            $html .= '<td class="font-bold text-cyan-400">' . round($totalResolveTime, 2) . ' ms</td>';
            $html .= '<td colspan="2"></td>';
            $html .= '</tr>';
            $html .= '</table></div>';
        }

        return $html;
    }

    /**
     * Format an environment variable value, masking sensitive keys.
     */
    private function formatEnvValue(string $key, $value): string
    {
        $sensitivePatterns = [
            'PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'SALT', 'HASH',
            'API_KEY', 'AUTH', 'CREDENTIAL', 'PRIVATE',
        ];

        $shouldMask = false;
        foreach ($sensitivePatterns as $pattern) {
            if (stripos($key, $pattern) !== false) {
                $shouldMask = true;
                break;
            }
        }

        if ($shouldMask && is_string($value) && strlen($value) > 4) {
            $masked = substr($value, 0, 2) . str_repeat('•', strlen($value) - 4) . substr($value, -2);
            return '<span class="text-yellow-400 font-mono">' . htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        if (is_bool($value)) {
            return $value ? '<span class="text-green-400">true</span>' : '<span class="text-red-400">false</span>';
        }

        if (is_null($value)) {
            return '<span class="text-gray-500">null</span>';
        }

        if (is_array($value)) {
            return '<span class="text-gray-400">(array)</span>';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Shorten fully qualified class names for display.
     */
    private function shortenClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Render the Services panel.
     */
    /**
     * Render the Services panel.
     */
    private function renderServicesPanel(): string
    {
        $services = $this->data['container_trace'] ?? [];
        if (empty($services)) {
            return '<div id="panel-services" class="toolbar-panel"><p class="text-gray-400">No services resolved.</p></div>';
        }

        // Sort by duration descending
        usort($services, fn($a, $b) => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));

        $html  = '<div id="panel-services" class="toolbar-panel">';
        $html .= '<h3 class="text-white text-lg font-bold mb-4">Resolved Services</h3>';
        $html .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto">';
        $html .= '<table class="toolbar-table">';
        $html .= '<thead style="text-align: left;"><tr><th class="label">Service</th><th class="label">Duration (ms)</th><th class="label">Called By</th><th class="label">Lazy?</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($services as $svc) {
            $class      = $svc['class'] ?? 'unknown';
            $duration   = $svc['duration'] ?? 0;
            $calledBy   = $svc['called_by'] ?? 'unknown';
            $isLazy     = isset($svc['proxy']) && $svc['proxy'] === true;
            $lazyText   = $isLazy ? 'Yes' : 'No';
            $lazyColor  = $isLazy ? 'text-green-400' : 'text-red-400';
            $html      .= sprintf(
                '<tr><td class="font-mono text-xs text-cyan-300">%s</td><td>%.2f</td><td class="text-gray-400 text-xs">%s</td><td><span class="%s">%s</span></td></tr>',
                htmlspecialchars($class),
                $duration,
                htmlspecialchars($calledBy),
                $lazyColor,
                $lazyText
            );
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Render the Middleware panel.
     */
    private function renderMiddlewarePanel(): string
    {
        $trace = $this->data['middleware_trace'] ?? [];
        if (empty($trace)) {
            return '<div id="panel-middleware" class="toolbar-panel"><p class="text-gray-400">No middleware executed.</p></div>';
        }

        $html   = '<div id="panel-middleware" class="toolbar-panel">';
        $html  .= '<h3 class="text-white text-lg font-bold mb-4">Middleware Execution Times</h3>';
        $html  .= '<div class="bg-gray-800 rounded p-3 overflow-x-auto">';
        $html  .= '<table class="toolbar-table">';
        $html  .= '<thead><tr><th class="label">#</th><th class="label">Type</th><th class="label">Class</th><th class="label">Route</th><th class="label">Duration (ms)</th></tr></thead>';
        $html  .= '<tbody>';
        $total  = 0;
        foreach ($trace as $index => $item) {
            $class     = $item['class'] ?? 'unknown';
            $type      = $item['type'] ?? 'global';
            $route     = $item['route'] ?? '-';
            $duration  = $item['duration'] ?? 0;
            $total    += $duration;
            $color     = $duration > 50 ? 'text-red-400' : ($duration > 20 ? 'text-yellow-400' : 'text-green-400');
            $html     .= sprintf(
                '<tr><td>%d</td><td>%s</td><td class="font-mono text-xs">%s</td><td class="text-gray-400">%s</td><td class="%s">%.2f</td></tr>',
                $index + 1,
                htmlspecialchars($type),
                htmlspecialchars($class),
                htmlspecialchars($route),
                $color,
                $duration
            );
        }
        $html .= '<tr style="border-top: 2px solid #4B5563;">';
        $html .= '<td colspan="4" class="text-right font-bold text-gray-400">Total Middleware Time:</td>';
        $html .= '<td class="font-bold text-cyan-400">' . round($total, 2) . ' ms</td>';
        $html .= '</tr>';
        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }
}
