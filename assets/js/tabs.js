/* Cable a Tierra Datos: region tabs (enhancement only). */
(function () {
  'use strict';
  function selectFromHash(hash, regions, fallback) {
    var id = String(hash).charAt(0) === '#' ? hash.slice(1) : '';
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
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { selectFromHash, nextIndex, canEnhance };
  }
  if (typeof document === 'undefined') return;

  var root = document.querySelector('[data-cat-tabs]');
  var nav = root && root.querySelector('.cat-detail__nav');
  var panels = root ? [...root.querySelectorAll('.cat-detail__panel')] : [];
  if (!nav || !canEnhance(panels.length)) return;
  var regions = panels.map(p => p.getAttribute('data-region'));
  var tabs = panels.map(p => nav.querySelector('a[href$="#' + p.id + '"]'));
  if (tabs.indexOf(null) > -1) return;
  var fallback = selectFromHash('#' + root.getAttribute('data-default'), regions);

  function attr(el, o) {
    for (var k in o) el.setAttribute(k, o[k]);
  }
  attr(nav, { role: 'tablist', 'aria-label': 'Región' });
  tabs.forEach((tab, i) => {
    attr(tab.parentNode, { role: 'presentation' });
    attr(tab, { role: 'tab', id: 'tab-' + panels[i].id, 'aria-controls': panels[i].id });
    attr(panels[i], { role: 'tabpanel', 'aria-labelledby': 'tab-' + panels[i].id });
  });
  root.classList.add('is-tabs');

  function select(i) {
    tabs.forEach((tab, j) => {
      attr(tab, { 'aria-selected': j === i, tabindex: j === i ? 0 : -1 });
      panels[j].hidden = j !== i;
    });
  }
  function sync() {
    select(Math.max(0, regions.indexOf(selectFromHash(location.hash, regions, fallback))));
  }
  function activate(i) {
    select(i);
    history.replaceState(null, '', '#' + regions[i]);
  }

  nav.addEventListener('click', e => {
    var i = tabs.indexOf(e.target.closest('a'));
    if (i > -1) e.preventDefault(), activate(i);
  });
  nav.addEventListener('keydown', e => {
    var i = tabs.indexOf(e.target);
    var n = nextIndex(i, e.key, 3);
    if (i < 0 || (n === null && e.key !== ' ')) return;
    e.preventDefault();
    if (n === null) activate(i);
    else tabs[n].focus();
  });
  window.addEventListener('hashchange', sync);
  sync();
})();
