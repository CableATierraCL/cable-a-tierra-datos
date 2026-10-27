// Behavioural tests of assets/js/tabs.js against a minimal hand-written fake DOM (no packages).
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const { enhance } = require('../../assets/js/tabs.js');

const SLUGS = ['nuble', 'biobio', 'araucania'];

class El {
  constructor(tag = 'div') {
    this.tag = tag;
    this.attrs = {};
    this.handlers = {};
    this.children = [];
    this.parentNode = null;
    this.hidden = false;
    this.focusCalls = 0;
    this.scrollCalls = [];
  }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  removeAttribute(k) { delete this.attrs[k]; }
  addEventListener(t, f) { (this.handlers[t] ||= []).push(f); }
  dispatch(t, e) { (this.handlers[t] || []).forEach(f => f(e)); }
  closest(sel) { return sel === this.tag ? this : null; }
  focus() { this.focusCalls++; }
  scrollIntoView(o) { this.scrollCalls.push(o); }
}

function build({ hash = '', def = 'biobio' } = {}) {
  const tabs = SLUGS.map(() => new El('a'));
  const panels = SLUGS.map((s, i) => {
    const p = new El('section');
    p.id = 'cat-' + s;
    p.setAttribute('data-region', s);
    tabs[i].parentNode = new El('li');
    return p;
  });
  const nav = new El('ul');
  nav.querySelector = sel => tabs[panels.findIndex(p => sel === `a[href$="#${p.id}"]`)] ?? null;
  const root = new El();
  root.setAttribute('data-default', def);
  root.querySelector = sel => (sel === '.cat-detail__nav' ? nav : null);
  root.querySelectorAll = () => panels;
  const win = new El();
  win.location = { hash };
  win.replaced = [];
  win.history = { replaceState: (a, b, url) => { win.replaced.push(url); win.location.hash = url; } };
  enhance(root, win);
  return { tabs, panels, nav, win };
}

const ev = (o = {}) => ({ button: 0, defaultPrevented: false, prevented: 0, preventDefault() { this.prevented++; }, ...o });
const selected = ({ tabs }) => tabs.findIndex(t => t.getAttribute('aria-selected') === 'true');
const visible = ({ panels }) => panels.map((p, i) => (p.hidden ? -1 : i)).filter(i => i > -1);

test('default biobio is selected, others hidden, roving tabindex', () => {
  const d = build();
  assert.equal(selected(d), 1);
  assert.deepEqual(visible(d), [1]);
  assert.deepEqual(d.tabs.map(t => t.getAttribute('tabindex')), ['-1', '0', '-1']);
  assert.equal(d.nav.getAttribute('role'), 'tablist');
  assert.equal(d.tabs[0].getAttribute('role'), 'tab');
  assert.equal(d.panels[0].getAttribute('role'), 'tabpanel');
  assert.equal(d.panels[0].scrollCalls.length + d.panels[1].scrollCalls.length, 0);
});

test('arrows wrap and Home/End move focus only', () => {
  const d = build();
  const key = (i, k) => { const e = ev({ key: k, target: d.tabs[i] }); d.nav.dispatch('keydown', e); return e; };
  assert.equal(key(2, 'ArrowRight').prevented, 1);
  assert.equal(d.tabs[0].focusCalls, 1);
  key(0, 'ArrowLeft');
  assert.equal(d.tabs[2].focusCalls, 1);
  key(1, 'Home');
  assert.equal(d.tabs[0].focusCalls, 2);
  key(1, 'End');
  assert.equal(d.tabs[2].focusCalls, 2);
  assert.equal(selected(d), 1);
  assert.deepEqual(d.win.replaced, []);
});

test('Enter, Space and click activate and write the slug', () => {
  const d = build();
  d.nav.dispatch('keydown', ev({ key: 'Enter', target: d.tabs[0] }));
  assert.equal(selected(d), 1, 'Enter alone is left to the native link click');
  d.nav.dispatch('keydown', ev({ key: ' ', target: d.tabs[0] }));
  assert.equal(selected(d), 0);
  assert.deepEqual(d.win.replaced, ['#nuble']);
  const c = ev({ target: d.tabs[2] });
  d.nav.dispatch('click', c);
  assert.equal(c.prevented, 1);
  assert.equal(selected(d), 2);
  assert.deepEqual(visible(d), [2]);
  assert.deepEqual(d.win.replaced, ['#nuble', '#araucania']);
});

test('modified keys are ignored without preventDefault', () => {
  const d = build();
  for (const m of ['altKey', 'ctrlKey', 'metaKey', 'shiftKey']) {
    for (const k of ['ArrowRight', 'Home', ' ']) {
      const e = ev({ key: k, target: d.tabs[1], [m]: true });
      d.nav.dispatch('keydown', e);
      assert.equal(e.prevented, 0, m + k);
    }
  }
  assert.equal(d.tabs.reduce((n, t) => n + t.focusCalls, 0), 0);
  assert.deepEqual(d.win.replaced, []);
});

test('middle, modified and already-handled clicks are ignored', () => {
  const d = build();
  for (const o of [{ button: 1 }, { ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { defaultPrevented: true }]) {
    const e = ev({ target: d.tabs[0], ...o });
    d.nav.dispatch('click', e);
    assert.equal(e.prevented, 0, JSON.stringify(o));
  }
  assert.equal(selected(d), 1);
  assert.deepEqual(d.win.replaced, []);
});

test('initial exact hash selects the region and scrolls its panel once', () => {
  const d = build({ hash: '#nuble' });
  assert.equal(selected(d), 0);
  assert.deepEqual(d.panels[0].scrollCalls, [{ block: 'start' }]);
  d.win.location.hash = '#araucania';
  d.win.dispatch('hashchange', {});
  assert.equal(selected(d), 2);
  assert.equal(d.panels[2].scrollCalls.length, 0, 'hashchange never scrolls');
  assert.equal(d.panels[0].scrollCalls.length, 1);
});

test('initial foreign hash falls back to the default without scrolling', () => {
  const d = build({ hash: '#metodologia', def: 'araucania' });
  assert.equal(selected(d), 2);
  assert.ok(d.panels.every(p => p.scrollCalls.length === 0));
});

test('hashchange to a foreign hash keeps the current tab', () => {
  const d = build();
  for (const h of ['#metodologia', '#nuble-2', '', '#%E0%A4%A']) {
    d.win.location.hash = h;
    d.win.dispatch('hashchange', {});
    assert.equal(selected(d), 1, h);
  }
});

test('encoded hashes are decoded', () => {
  assert.equal(selected(build({ hash: '#bio%62io' })), 1);
  assert.equal(selected(build({ hash: '#nu%62le' })), 0);
  assert.equal(selected(build({ hash: '#%E0%A4%A' })), 1);
});

test('focus is never moved on load or hashchange', () => {
  const d = build({ hash: '#araucania' });
  d.win.location.hash = '#nuble';
  d.win.dispatch('hashchange', {});
  assert.equal(d.tabs.reduce((n, t) => n + t.focusCalls, 0), 0);
});

test('enhance does nothing without a root or with the wrong panel count', () => {
  assert.doesNotThrow(() => enhance(null, {}));
});
