/* Rhapsody Module UI — inset helper, v1
   --------------------------------------------------------------------------
   Themes position navigation differently: a fixed top bar, a side menu, a
   floating pill, or nothing at all. This script finds menus that actually
   overlap the module content and reserves space for them, so module pages
   never start underneath a menu. It does not assume what kind of menu a theme
   has or how big it is; it measures.

   What counts as a menu: a visible, interactive (contains a link or button)
   element with position fixed/absolute that is
     - wide and short  -> a top bar (bottom bars are ignored: cookie banners)
     - tall and narrow -> a left or right side menu
   Elements that contain the module content (app shells), non-interactive
   decoration, hidden or off-canvas elements are ignored. A menu that does not
   overlap the content (theme already pads for it) results in zero extra space.

   Result is written to .rhapsody-ui as --_inset-top / --_inset-left /
   --_inset-right (px), consumed by rhapsody-ui.css. Without JavaScript the page
   keeps its default padding. */
(function () {
  'use strict';

  var root = document.querySelector('.rhapsody-ui');
  if (!root) { return; }

  var timer = 0;

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

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(measure, 100);
  }

  measure();
  window.addEventListener('load', schedule);
  window.addEventListener('resize', schedule);
  window.addEventListener('orientationchange', schedule);
  // Menus that slide in/out or collapse (mobile drawers, collapsible sidebars).
  document.addEventListener('transitionend', schedule, true);
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(schedule); }
})();
