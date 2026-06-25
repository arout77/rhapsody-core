<?php
// core/Toolbar.php

namespace Rhapsody\Core;

class Toolbar
{
    /**
     * @param array $data
     */
    public function __construct(protected array $data)
    {}

    public function render(): string
    {
        // --- Data Preparation for Toolbar Header ---
        $appVersion   = htmlspecialchars($this->data['app_version'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $execTime     = $this->data['execution_time'] ?? '0';
        $memUsage     = $this->data['memory_usage'] ?? '0';
        $responseCode = $this->data['response_code'] ?? 'N/A';
        $queryCount   = count($this->data['queries'] ?? []);

        // --- Prepare performance data for React component ---
        $performanceProps = json_encode([
            'time'    => (float) $execTime,
            'memory'  => (float) $memUsage,
            'queries' => $queryCount,
            'route'   => $this->data['route'] ?? null,
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

        // --- Format Request/Routes panel as a structured table ---
        $routeData   = $this->data['route'] ?? null;
        $requestHtml = '<h3 class="text-white text-lg font-bold mb-4">Request &amp; Route</h3>';
        if ($routeData) {
            $requestHtml .= '<div class="bg-gray-800 rounded p-3 mb-3">';
            $requestHtml .= '<table class="toolbar-table">';
            $requestHtml .= '<tr><td class="label">Method</td><td class="value"><span class="badge badge-method">' . htmlspecialchars(strtoupper($routeData['method'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
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
            $requestHtml .= '</table></div>';
        } else {
            $requestHtml .= '<p class="text-gray-400">No route matched for this request.</p>';
        }

        // --- Format Session panel as a key-value table ---
        $sessionData  = $this->data['session'] ?? [];
        $sessionHtml  = '<h3 class="text-white text-lg font-bold mb-4">Session Data</h3>';
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

        // --- Data Preparation for Toolbar Panels (in PHP) ---
        $panels_data = [
            'panel-request'     => $requestHtml,
            'panel-logs'        => '<h3 class="text-white text-lg font-bold">PHP Error Log</h3><pre>' . ($this->data['logs']['php'] ?? 'Log not available.') . '</pre><h3 class="text-white text-lg font-bold mt-4">Apache Error Log</h3><pre>' . ($this->data['logs']['apache'] ?? 'Log not available.') . '</pre>',
            'panel-db'          => '<h3 class="text-white text-lg font-bold mb-4">Database Queries</h3><div>' . $this->formatQueries() . '</div>',
            'panel-session'     => $sessionHtml,
            'panel-performance' => <<<HTML
                <div class="rhapsody-island" data-component="Toolbar/PerformancePanel">
                    <script type="application/json" class="rhapsody-island-props">{$performanceProps}</script>
                </div>
            HTML,
        ];

        $panels_json  = json_encode($panels_data);

        // --- CSS for the new tables (inserted into the existing style block) ---
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
        <div class="toolbar-item" data-panel="panel-logs"><span class="toolbar-label">Logs</span></div>
        <div class="toolbar-item" data-panel="panel-db"><span class="toolbar-label">Database</span> <span class="toolbar-value">{$queryCount} Queries</span></div>
        <div class="toolbar-item" data-panel="panel-session"><span class="toolbar-label">Session</span></div>
        <div class="toolbar-item" data-panel="panel-performance"><span class="toolbar-label">Performance</span></div>

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
            $sql     = htmlspecialchars($query['sql'], ENT_QUOTES, 'UTF-8');
            $params  = isset($query['params']) ? htmlspecialchars(json_encode($query['params']), ENT_QUOTES, 'UTF-8') : '';
            $time    = isset($query['executionMS']) ? round($query['executionMS'] * 1000, 2) : '0';
            $caller  = isset($query['caller']['file']) ? $query['caller']['file'] . ':' . ($query['caller']['line'] ?? '-') : 'N/A';
            $html   .= "<div class='query-item'>
                <div class='query-sql'>{$sql}</div>
                <div class='query-meta'>
                    <span>Params: {$params}</span>
                    <span>Time: {$time}ms</span>
                    <span style='margin-left: auto;'>{$caller}</span>
                </div>
            </div>";
        }
        return $html;
    }
}
