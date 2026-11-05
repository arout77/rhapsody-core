<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use Rhapsody\Core\Session;
use Rhapsody\Core\Support\Env;
use Rhapsody\Core\Support\Json;
use Throwable;

/**
 * The browser side of toolbar "Ask AI" and error page "Explain and fix": one
 * small self-contained script and stylesheet, plus the markup that hosts them
 * in either place.
 *
 * It exists only in development (see {@see tryCreate()}) and never contains
 * request data: just the snapshot id, the session CSRF token and the endpoint
 * path. Everything the model writes is inserted with textContent, never as
 * HTML.
 *
 * @example
 * $widget = AssistWidget::tryCreate($config, $snapshotPath);
 * if ($widget !== null) { echo $widget->errorPageHtml(); }
 */
final class AssistWidget
{
    private function __construct(
        private readonly string $snapshotId,
        private readonly string $token,
        private readonly string $base,
        private readonly bool $apply,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @param string|null          $snapshotPath The file SnapshotRecorder wrote, or null if nothing was recorded
     */
    public static function tryCreate(array $config, ?string $snapshotPath): ?self
    {
        if ($snapshotPath === null || ! AssistSettings::isDevelopment($config)) {
            return null;
        }

        $settings = AssistSettings::fromConfig($config);
        if (! $settings->enabled) {
            return null;
        }

        try {
            $token = Session::csrfToken();
        } catch (Throwable) {
            return null;
        }

        $path = parse_url(Env::string('APP_BASE_URL'), PHP_URL_PATH);
        $base = rtrim(is_string($path) ? $path : '', '/') . '/_rhapsody/assist';

        return new self(basename($snapshotPath, '.json'), $token, $base, $settings->apply);
    }

    /**
     * The mount point used inside the toolbar's "Ask AI" panel.
     */
    public function toolbarPanelHtml(): string
    {
        return '<div class="rh-assist" data-rh-assist data-mode="ask"></div>';
    }

    /**
     * Stylesheet, configuration and script. Include once per page.
     */
    public function assets(): string
    {
        $config = Json::encode([
            'base'     => $this->base,
            'token'    => $this->token,
            'snapshot' => $this->snapshotId,
            'apply'    => $this->apply,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return '<style>' . self::CSS . '</style><script>window.__RH_ASSIST__ = ' . $config . ';</script><script>' . self::SCRIPT . '</script>';
    }

    /**
     * Everything the error page needs: assets, a floating button and a side drawer.
     */
    public function errorPageHtml(): string
    {
        return $this->assets()
            . '<style>' . self::DRAWER_CSS . '</style>'
            . '<button type="button" id="rh-assist-fab">Explain and fix with AI</button>'
            . '<aside id="rh-assist-drawer" hidden>'
            . '<header><strong>Rhapsody AI</strong><button type="button" id="rh-assist-close" aria-label="Close">&times;</button></header>'
            . '<div class="rh-assist" data-rh-assist data-mode="fix"></div>'
            . '</aside>'
            . '<script>' . self::DRAWER_SCRIPT . '</script>';
    }

    private const DRAWER_CSS = <<<'CSS'
#rh-assist-fab{position:fixed;right:20px;bottom:20px;z-index:2147483000;background:#4f46e5;color:#fff;border:0;border-radius:999px;padding:12px 20px;font:600 14px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.35)}
#rh-assist-fab:hover{background:#4338ca}
#rh-assist-drawer{position:fixed;top:0;right:0;bottom:0;width:min(520px,100vw);z-index:2147483001;background:#111827;color:#e5e7eb;box-shadow:-8px 0 30px rgba(0,0,0,.45);display:flex;flex-direction:column;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
#rh-assist-drawer[hidden]{display:none}
#rh-assist-drawer>header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #374151;background:#1f2937}
#rh-assist-drawer>header button{background:none;border:0;color:#9ca3af;font-size:24px;line-height:1;cursor:pointer}
#rh-assist-drawer .rh-assist{flex:1;overflow-y:auto;padding:16px}
CSS;

    private const DRAWER_SCRIPT = <<<'JS'
(function () {
  var fab = document.getElementById('rh-assist-fab');
  var drawer = document.getElementById('rh-assist-drawer');
  var close = document.getElementById('rh-assist-close');
  if (!fab || !drawer || !close) { return; }
  function open() { drawer.hidden = false; fab.style.display = 'none'; if (window.RhAssist) { window.RhAssist.mountAll(drawer); } }
  function shut() { drawer.hidden = true; fab.style.display = ''; }
  fab.addEventListener('click', open);
  close.addEventListener('click', shut);
})();
JS;

    private const CSS = <<<'CSS'
.rh-assist{color:#e5e7eb;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:left}
.rh-assist *{box-sizing:border-box}
.rh-assist .rh-notice{background:#1e1b4b;border:1px solid #4338ca;border-radius:6px;padding:8px 12px;margin:0 0 12px;color:#c7d2fe;font-size:13px}
.rh-assist .rh-warn{background:#422006;border:1px solid #a16207;border-radius:6px;padding:8px 12px;margin:8px 0;color:#fde68a;font-size:13px}
.rh-assist .rh-error{background:#450a0a;border:1px solid #b91c1c;border-radius:6px;padding:8px 12px;margin:0 0 12px;color:#fecaca;font-size:13px}
.rh-assist .rh-ok{background:#052e16;border:1px solid #15803d;border-radius:6px;padding:8px 12px;margin:8px 0;color:#bbf7d0;font-size:13px}
.rh-assist h4{margin:14px 0 6px;font-size:14px;font-weight:700;color:#f9fafb}
.rh-assist .rh-input{width:100%;background:#0b1220;color:#f9fafb;border:1px solid #4b5563;border-radius:6px;padding:8px 10px;font:inherit;resize:vertical}
.rh-assist .rh-chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
.rh-assist .rh-chip{background:#1f2937;color:#d1d5db;border:1px solid #4b5563;border-radius:999px;padding:3px 10px;font-size:12px;cursor:pointer}
.rh-assist .rh-chip:hover{background:#374151}
.rh-assist .rh-btn{background:#4f46e5;color:#fff;border:0;border-radius:6px;padding:7px 14px;font:600 13px inherit;cursor:pointer;margin:8px 8px 0 0}
.rh-assist .rh-btn:hover{background:#4338ca}
.rh-assist .rh-btn:disabled{opacity:.5;cursor:default}
.rh-assist .rh-btn-quiet{background:#374151}
.rh-assist .rh-btn-quiet:hover{background:#4b5563}
.rh-assist .rh-btn-danger{background:#b45309}
.rh-assist .rh-btn-danger:hover{background:#92400e}
.rh-assist label.rh-row{display:flex;gap:8px;align-items:flex-start;padding:3px 0;cursor:pointer}
.rh-assist .rh-size{color:#9ca3af;font-size:12px;margin-left:auto;white-space:nowrap}
.rh-assist .rh-meta{color:#9ca3af;font-size:12px;margin:0 0 8px}
.rh-assist pre{background:#0b1220;border:1px solid #1f2937;border-radius:6px;padding:10px;margin:6px 0;overflow:auto;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre;word-break:normal;max-height:320px}
.rh-assist code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;background:#1f2937;padding:1px 5px;border-radius:4px}
.rh-assist pre code{background:none;padding:0}
.rh-assist .rh-md p{margin:0 0 10px}
.rh-assist .rh-md ul{margin:0 0 10px;padding-left:20px}
.rh-assist .rh-card{border:1px solid #374151;border-radius:8px;padding:10px 12px;margin:10px 0;background:#161f30}
.rh-assist .rh-card .rh-file{font:600 13px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#a5b4fc;word-break:break-all}
.rh-assist .rh-d-line{display:block}
.rh-assist .rh-d-add{background:#052e16;color:#bbf7d0}
.rh-assist .rh-d-del{background:#450a0a;color:#fecaca}
.rh-assist .rh-d-hunk{color:#93c5fd}
.rh-assist .rh-d-file{color:#9ca3af}
.rh-assist details{margin:8px 0}
.rh-assist summary{cursor:pointer;color:#a5b4fc;font-size:13px}
CSS;

    private const SCRIPT = <<<'JS'
(function () {
  if (window.RhAssist) { return; }
  var cfg = window.__RH_ASSIST__ || {};
  var SKIP = 'rhAssistSkipReview';

  function h(tag, props, kids) {
    var node = document.createElement(tag);
    Object.keys(props || {}).forEach(function (k) {
      var v = props[k];
      if (v === false || v === null || v === undefined) { return; }
      if (k === 'class') { node.className = v; }
      else if (k === 'text') { node.textContent = v; }
      else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), v); }
      else { node.setAttribute(k, v === true ? '' : v); }
    });
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) { return; }
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }
  function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
  function kb(n) { return (n / 1024).toFixed(1) + ' KB'; }
  function store(op, value) {
    try { if (op === 'get') { return window.sessionStorage.getItem(SKIP) === '1'; } window.sessionStorage.setItem(SKIP, value ? '1' : '0'); } catch (e) { return false; }
    return false;
  }

  function post(action, body) {
    var payload = { _token: cfg.token, snapshot_id: cfg.snapshot };
    Object.keys(body || {}).forEach(function (k) { payload[k] = body[k]; });
    return fetch(cfg.base + '/' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Rhapsody-Assist': '1' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { data = null; }
        if (!res.ok) { throw new Error((data && data.error) || ('Request failed (' + res.status + ').')); }
        if (!data) { throw new Error('Unexpected response from the server.'); }
        return data;
      });
    });
  }

  function inline(text) {
    var frag = document.createDocumentFragment();
    text.split(/(`[^`\n]+`|\*\*[^*\n]+\*\*)/).forEach(function (part) {
      if (!part) { return; }
      if (part.charAt(0) === '`' && part.length > 2) { frag.appendChild(h('code', { text: part.slice(1, -1) })); }
      else if (part.slice(0, 2) === '**' && part.length > 4) { frag.appendChild(h('strong', { text: part.slice(2, -2) })); }
      else { frag.appendChild(document.createTextNode(part)); }
    });
    return frag;
  }

  function markdown(text) {
    var box = h('div', { 'class': 'rh-md' });
    String(text).split(/```[^\n]*\n([\s\S]*?)```/).forEach(function (part, i) {
      if (i % 2 === 1) { box.appendChild(h('pre', {}, [h('code', { text: part.replace(/\n$/, '') })])); return; }
      part.split(/\n{2,}/).forEach(function (block) {
        block = block.replace(/^\n+|\n+$/g, '');
        if (!block) { return; }
        var lines = block.split('\n');
        var isList = lines.every(function (l) { return /^\s*[-*] /.test(l); });
        if (isList) {
          var ul = h('ul');
          lines.forEach(function (l) { ul.appendChild(h('li', {}, [inline(l.replace(/^\s*[-*] /, ''))])); });
          box.appendChild(ul);
        } else {
          var p = h('p');
          lines.forEach(function (l, j) { if (j) { p.appendChild(document.createElement('br')); } p.appendChild(inline(l)); });
          box.appendChild(p);
        }
      });
    });
    return box;
  }

  function diffView(diff) {
    var pre = h('pre');
    diff.split('\n').forEach(function (line) {
      var cls = 'rh-d-line';
      if (line.indexOf('+++') === 0 || line.indexOf('---') === 0) { cls += ' rh-d-file'; }
      else if (line.indexOf('@@') === 0) { cls += ' rh-d-hunk'; }
      else if (line.charAt(0) === '+') { cls += ' rh-d-add'; }
      else if (line.charAt(0) === '-') { cls += ' rh-d-del'; }
      pre.appendChild(h('span', { 'class': cls, text: line === '' ? ' ' : line }));
    });
    return pre;
  }

  function copy(text, button) {
    var done = function () { var old = button.textContent; button.textContent = 'Copied'; setTimeout(function () { button.textContent = old; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); }
  }

  function mount(root) {
    if (root.__rhMounted) { return; }
    root.__rhMounted = true;
    var mode = root.getAttribute('data-mode') === 'fix' ? 'fix' : 'ask';
    var question = '';

    function busy(message) {
      clear(root);
      root.appendChild(h('p', { 'class': 'rh-meta', text: message }));
    }

    function idle(error) {
      clear(root);
      root.appendChild(h('div', { 'class': 'rh-notice', text: 'Nothing is sent until you review it. The text goes to your AI provider, so secrets are masked first.' }));
      if (error) { root.appendChild(h('div', { 'class': 'rh-error', text: error })); }

      if (mode === 'fix') {
        root.appendChild(h('p', { text: 'Ask the AI why this request failed and how to fix it. You see the exact text, including the source lines, before anything is sent, and every change is shown as a diff first.' }));
        root.appendChild(h('button', { type: 'button', 'class': 'rh-btn', text: 'Review and explain', onclick: function () { review(null); } }));
        return;
      }

      var box = h('textarea', { 'class': 'rh-input', rows: '3', maxlength: '500', placeholder: 'Ask about this request, for example: why is it slow?' });
      box.value = question;
      var chips = h('div', { 'class': 'rh-chips' });
      ['Why is this request slow?', 'Are there N+1 queries?', 'Summarize what this request did'].forEach(function (text) {
        chips.appendChild(h('button', { type: 'button', 'class': 'rh-chip', text: text, onclick: function () { box.value = text; box.focus(); } }));
      });
      root.appendChild(box);
      root.appendChild(chips);
      root.appendChild(h('button', { type: 'button', 'class': 'rh-btn', text: 'Review and ask', onclick: function () {
        question = box.value.trim();
        if (!question) { idle('Type a question first.'); return; }
        review(null);
      } }));
    }

    function review(selected) {
      busy('Preparing a preview of what would be sent...');
      post('preview', { mode: mode, question: question, sections: selected }).then(function (preview) {
        if (selected === null && mode === 'ask' && store('get')) { send(null); return; }
        renderReview(preview);
      }).catch(function (e) { idle(e.message); });
    }

    function renderReview(preview) {
      clear(root);
      root.appendChild(h('h4', { text: 'Review what will be sent to ' + preview.provider }));
      root.appendChild(h('p', { 'class': 'rh-meta', text: kb(preview.bytes) + ' in total. Untick anything you would rather keep out.' }));

      var boxes = [];
      preview.sections.forEach(function (s) {
        var cb = h('input', { type: 'checkbox', checked: s.enabled, disabled: s.required });
        cb.checked = s.enabled;
        cb.setAttribute('data-key', s.key);
        boxes.push(cb);
        root.appendChild(h('label', { 'class': 'rh-row' }, [cb, h('span', { text: s.label }), h('span', { 'class': 'rh-size', text: kb(s.bytes) })]));
        if (!s.required) {
          cb.addEventListener('change', function () { review(chosen()); });
        }
      });

      function chosen() {
        return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.getAttribute('data-key'); });
      }

      if (preview.excerpts.length) {
        root.appendChild(h('h4', { text: 'Source files included' }));
        preview.excerpts.forEach(function (e) {
          root.appendChild(h('div', { 'class': 'rh-meta', text: e.file + ' (lines ' + e.lines + ')' + (e.patchable ? '' : ' - read only') }));
        });
      }
      preview.notices.forEach(function (n) { root.appendChild(h('div', { 'class': 'rh-warn', text: n })); });

      var details = h('details', {}, [h('summary', { text: 'Show the exact text' }), h('pre', { text: preview.payload })]);
      root.appendChild(details);

      // Source code is only ever sent from the error page, so that review is never skippable.
      var skip = h('input', { type: 'checkbox' });
      if (mode === 'ask') {
        root.appendChild(h('label', { 'class': 'rh-row' }, [skip, h('span', { text: 'Do not show this review again in this browser session' })]));
      }

      root.appendChild(h('button', { type: 'button', 'class': 'rh-btn', text: 'Send to ' + preview.provider, onclick: function () {
        if (skip.checked) { store('set', true); }
        send(chosen());
      } }));
      root.appendChild(h('button', { type: 'button', 'class': 'rh-btn rh-btn-quiet', text: 'Cancel', onclick: function () { idle(); } }));
    }

    function send(sections) {
      busy('Asking the AI. This usually takes a few seconds...');
      post('explain', { mode: mode, question: question, sections: sections }).then(result).catch(function (e) { idle(e.message); });
    }

    function result(data) {
      clear(root);
      root.appendChild(h('p', { 'class': 'rh-meta', text: 'Sent ' + kb(data.bytes_sent) + ' to ' + data.provider + '. Logged in storage/logs/ai-requests.jsonl.' }));
      data.notices.forEach(function (n) { root.appendChild(h('div', { 'class': 'rh-warn', text: n })); });
      root.appendChild(markdown(data.explanation));

      data.fixes.forEach(function (fix) {
        var status = h('div');
        var actions = h('div');
        var card = h('div', { 'class': 'rh-card' }, [
          h('div', { 'class': 'rh-file', text: fix.file }),
          fix.description ? h('p', { text: fix.description }) : null,
          diffView(fix.diff),
          actions,
          status
        ]);

        if (data.can_apply) {
          var armed = false;
          var timer = null;
          var apply = h('button', { type: 'button', 'class': 'rh-btn rh-btn-danger', text: 'Apply this change' });
          apply.addEventListener('click', function () {
            if (!armed) {
              armed = true;
              apply.textContent = 'Click again to write ' + fix.file;
              timer = setTimeout(function () { armed = false; apply.textContent = 'Apply this change'; }, 6000);
              return;
            }
            clearTimeout(timer);
            apply.disabled = true;
            apply.textContent = 'Applying...';
            post('apply', { proposal_id: fix.id }).then(function (r) {
              apply.remove();
              clear(status);
              status.appendChild(h('div', { 'class': 'rh-ok', text: 'Applied to ' + r.file + '. The original is saved at ' + r.backup + '.' }));
              if (mode === 'fix') { status.appendChild(h('button', { type: 'button', 'class': 'rh-btn', text: 'Reload the page', onclick: function () { window.location.reload(); } })); }
            }).catch(function (e) {
              armed = false;
              apply.disabled = false;
              apply.textContent = 'Apply this change';
              clear(status);
              status.appendChild(h('div', { 'class': 'rh-error', text: e.message }));
            });
          });
          actions.appendChild(apply);
        }

        var copyBtn = h('button', { type: 'button', 'class': 'rh-btn rh-btn-quiet', text: 'Copy diff' });
        copyBtn.addEventListener('click', function () { copy(fix.diff, copyBtn); });
        actions.appendChild(copyBtn);
        root.appendChild(card);
      });

      root.appendChild(h('button', { type: 'button', 'class': 'rh-btn rh-btn-quiet', text: mode === 'fix' ? 'Start over' : 'Ask something else', onclick: function () { idle(); } }));
    }

    idle();
  }

  window.RhAssist = {
    mountAll: function (scope) {
      (scope || document).querySelectorAll('[data-rh-assist]').forEach(mount);
    }
  };
})();
JS;
}
