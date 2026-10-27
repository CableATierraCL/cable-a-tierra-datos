/* Cable a Tierra Datos: region tabs (enhancement only). Enhances the FIRST [data-cat-tabs] only.
 * Focus is never moved automatically; a deep link (#slug on load) scrolls its panel into view once. */
(function () {
  'use strict';
  function hashId(hash) {
    var id = String(hash).charAt(0) === '#' ? hash.slice(1) : '';
    try {
      id = decodeURIComponent(id);
    } catch (e) {}
    return id;
  }
  function selectFromHash(hash, regions, fallback) {
    var id = hashId(hash);
    return regions.indexOf(id) > -1 ? id : fallback || 'biobio';
  }
  function nextIndex(current, key, count) {
    if (key === 'ArrowRight') return (current + 1) % count;
    if (key === 'ArrowLeft') return (current + count - 1) % count;
    if (key === 'Home') return 0;
    return key === 'End' ? count - 1 : null;
  }
  function canEnhance(n) {
    return n === 3;
  }
  function modified(e) {
    return e.altKey || e.ctrlKey || e.metaKey || e.shiftKey;
  }
  function enhance(root, win) {
    var nav = root && root.querySelector('.cat-detail__nav');
    var panels = root ? [...root.querySelectorAll('.cat-detail__panel')] : [];
    if (!nav || !canEnhance(panels.length)) return;
    var regions = panels.map(p => p.getAttribute('data-region'));
    var tabs = panels.map(p => nav.querySelector('a[href$="#' + p.id + '"]'));
    if (tabs.indexOf(null) > -1) return;
    var fallback = regions.indexOf(selectFromHash('#' + root.getAttribute('data-default'), regions));

    function attr(el, o) {
      for (var k in o) el.setAttribute(k, o[k]);
    }
    attr(nav, { role: 'tablist', 'aria-label': 'Región' });
    tabs.forEach((tab, i) => {
      attr(tab.parentNode, { role: 'presentation' });
      attr(tab, { role: 'tab', id: 'tab-' + panels[i].id, 'aria-controls': panels[i].id });
      attr(panels[i], { role: 'tabpanel', 'aria-labelledby': 'tab-' + panels[i].id });
    });

    function select(i) {
      tabs.forEach((tab, j) => {
        attr(tab, { 'aria-selected': j === i, tabindex: j === i ? 0 : -1 });
        panels[j].hidden = j !== i;
      });
    }
    // Foreign hashes (#metodologia) keep the current tab.
    function sync() {
      var i = regions.indexOf(hashId(win.location.hash));
      if (i > -1) select(i);
      return i;
    }
    function activate(i) {
      select(i);
      win.history.replaceState(null, '', '#' + regions[i]);
    }

    nav.addEventListener('click', e => {
      var i = tabs.indexOf(e.target.closest('a'));
      if (i < 0 || e.defaultPrevented || e.button !== 0 || modified(e)) return;
      e.preventDefault();
      activate(i);
    });
    nav.addEventListener('keydown', e => {
      var i = tabs.indexOf(e.target);
      var n = nextIndex(i, e.key, tabs.length);
      if (i < 0 || modified(e) || (n === null && e.key !== ' ')) return;
      e.preventDefault();
      if (n === null) activate(i);
      else tabs[n].focus();
    });
    win.addEventListener('hashchange', sync);
    select(fallback);
    var deep = sync();
    if (deep > -1) panels[deep].scrollIntoView({ block: 'start' });
  }
  if (typeof document === 'undefined') {
    if (typeof module !== 'undefined' && module.exports) {
      module.exports = { selectFromHash, nextIndex, canEnhance, enhance };
    }
    return;
  }
  enhance(document.querySelector('[data-cat-tabs]'), window);
})();
