/* Rhapsody Module UI — layout & contrast helper, v2
   --------------------------------------------------------------------------
   Themes differ in two ways that module pages cannot know in advance:
   where their navigation sits, and whether the page is light or dark. This
   script measures both instead of assuming.

   1. Insets
      Themes position navigation differently: a fixed top bar, a side menu, a
      floating pill, or nothing at all. This finds menus that actually overlap
      the module content and reserves space for them, so module pages never
      start underneath a menu.

      What counts as a menu: a visible, interactive (contains a link or button)
      element with position fixed/absolute that is
        - wide and short  -> a top bar (bottom bars are ignored: cookie banners)
        - tall and narrow -> a left or right side menu
      Elements that contain the module content (app shells), non-interactive
      decoration, hidden or off-canvas elements are ignored. A menu that does
      not overlap the content (theme already pads for it) results in zero extra
      space.

      Result is written to .rhapsody-ui as --_inset-top / --_inset-left /
      --_inset-right (px), consumed by rhapsody-ui.css.

   2. Contrast guard
      The baseline stylesheet derives borders, input tints and placeholders
      from currentColor, so everything depends on the inherited text colour
      being readable. If the theme's inherited text colour has poor contrast
      (< 4.5:1) against the effective page background, the guard sets a
      readable colour on .rhapsody-ui. It also sets color-scheme (unless the
      theme declared one) so native controls match light/dark themes. A theme
      that already works is left untouched.

   Without JavaScript the page keeps its default padding and inherited colours. */
(function () {
  'use strict';

  var root = document.querySelector('.rhapsody-ui');
  if (!root) { return; }

  var timer = 0;

  /* ---- 1. Inset measuring ------------------------------------------------ */

  function measure() {
    var vw = document.documentElement.clientWidth;
    var vh = window.innerHeight;
    var sy = window.pageYOffset || 0;
    var rr = root.getBoundingClientRect();
    var rootTop = rr.top + sy;        // content top at scroll position 0
    var rootBottom = rr.bottom + sy;
    var inset = { top: 0, left: 0, right: 0 };
    var els = document.body.getElementsByTagName('*');

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (root.contains(el) || el.contains(root)) { continue; }

      var cs = window.getComputedStyle(el);
      var pos = cs.position;
      if (pos !== 'fixed' && pos !== 'absolute') { continue; }
      if (cs.display === 'none' || cs.visibility === 'hidden' ||
          cs.pointerEvents === 'none' || cs.opacity === '0') { continue; }
      if (el.getAttribute('aria-hidden') === 'true') { continue; }
      if (!el.querySelector('a, button')) { continue; }

      var r = el.getBoundingClientRect();
      if (r.width < 1 || r.height < 1) { continue; }

      // Fixed elements sit at viewport coordinates; absolute ones scroll with the page.
      var dy = pos === 'absolute' ? sy : 0;
      var top = r.top + dy;
      var bottom = r.bottom + dy;
      var overlapsX = r.left < rr.right && r.right > rr.left;
      var overlapsY = top < rootBottom && bottom > rootTop;

      if (r.width >= vw * 0.5 && r.height <= vh * 0.3) {
        // Horizontal bar. Only bars in the upper half of the screen matter.
        if ((top + bottom) / 2 < vh / 2 && overlapsX && overlapsY) {
          inset.top = Math.max(inset.top, bottom - rootTop);
        }
      } else if (r.height >= vh * 0.5 && r.width <= vw * 0.5) {
        // Vertical bar (side menu).
        if (!overlapsY || !overlapsX) { continue; }
        if ((r.left + r.right) / 2 < vw / 2) {
          inset.left = Math.max(inset.left, r.right - rr.left);
        } else {
          inset.right = Math.max(inset.right, rr.right - r.left);
        }
      }
    }

    root.style.setProperty('--_inset-top', Math.ceil(Math.max(0, inset.top)) + 'px');
    root.style.setProperty('--_inset-left', Math.ceil(Math.max(0, inset.left)) + 'px');
    root.style.setProperty('--_inset-right', Math.ceil(Math.max(0, inset.right)) + 'px');
  }

  /* ---- 2. Contrast guard ------------------------------------------------- */

  // A 1x1 canvas parses any CSS colour syntax (rgb, oklch, color-mix results...)
  // into plain RGBA, which getComputedStyle alone does not guarantee.
  var cv = document.createElement('canvas');
  cv.width = 1;
  cv.height = 1;
  var cx = cv.getContext('2d', { willReadFrequently: true });

  function toRGBA(css) {
    if (!cx) { return [0, 0, 0, 0]; }
    cx.clearRect(0, 0, 1, 1);
    cx.fillStyle = '#000';   // reset so an unparseable value can't reuse the last colour
    cx.fillStyle = css;
    cx.fillRect(0, 0, 1, 1);
    var d = cx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2], d[3] / 255];
  }

  // WCAG relative luminance.
  function lum(c) {
    var v = [c[0], c[1], c[2]].map(function (n) {
      n /= 255;
      return n <= 0.03928 ? n / 12.92 : Math.pow((n + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }

  function contrast(a, b) {
    var l1 = lum(a);
    var l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  }

  // First mostly-opaque background colour walking up from the container.
  // Falls back to white (the browser canvas) if the theme never sets one.
  function effectiveBackground() {
    for (var el = root; el; el = el.parentElement) {
      var c = toRGBA(window.getComputedStyle(el).backgroundColor);
      if (c[3] >= 0.5) { return c; }
    }
    return [255, 255, 255, 1];
  }

  function guardContrast() {
    if (!cx) { return; }

    // Clear our own earlier corrections first, so we always evaluate what the
    // theme actually provides (matters after a dark/light toggle).
    root.style.removeProperty('color');
    root.style.removeProperty('color-scheme');

    var bg = effectiveBackground();
    var cs = window.getComputedStyle(root);
    var fg = toRGBA(cs.color);
    var dark = lum(bg) < 0.4;

    // Native controls (select lists, checkboxes, autofill) follow color-scheme.
    // Only set it when the theme hasn't declared its own.
    if (cs.colorScheme === 'normal') {
      root.style.setProperty('color-scheme', dark ? 'dark' : 'light');
    }

    // Inline style beats the zero-specificity baseline and theme rules alike,
    // and is only applied when the inherited text is actually unreadable.
    if (contrast(fg, bg) < 4.5) {
      root.style.setProperty('color', dark ? '#f3f4f6' : '#111827');
    }
  }

  /* ---- Lifecycle --------------------------------------------------------- */

  function run() {
    guardContrast();
    measure();
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, 100);
  }

  run();
  window.addEventListener('load', schedule);
  window.addEventListener('resize', schedule);
  window.addEventListener('orientationchange', schedule);
  // Menus that slide in/out or collapse (mobile drawers, collapsible sidebars).
  document.addEventListener('transitionend', schedule, true);
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(schedule); }

  // Theme switchers (dark-mode toggles) usually flip a class or data attribute
  // on <html> or <body>. Observing only those two elements (not their
  // subtrees) means our own style writes on .rhapsody-ui never retrigger this.
  if (window.MutationObserver) {
    var mo = new MutationObserver(schedule);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme'] };
    mo.observe(document.documentElement, opts);
    mo.observe(document.body, opts);
  }
})();