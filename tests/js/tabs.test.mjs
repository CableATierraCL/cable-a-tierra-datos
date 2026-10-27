// Pure helpers of assets/js/tabs.js. The script is a classic browser script that exposes them
// through a `module.exports` guard, so Node loads it as CommonJS (no build step, no packages).
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const { selectFromHash, nextIndex, canEnhance } = require('../../assets/js/tabs.js');

const REGIONS = ['nuble', 'biobio', 'araucania'];

test('selectFromHash selects an exact-case region id', () => {
  assert.equal(selectFromHash('#nuble', REGIONS), 'nuble');
  assert.equal(selectFromHash('#biobio', REGIONS), 'biobio');
  assert.equal(selectFromHash('#araucania', REGIONS), 'araucania');
});

test('selectFromHash falls back to biobio for anything else', () => {
  for (const hash of ['', '#', '#foo', '#metodologia', '#BIOBIO', '#Nuble', 'nuble ', '#nuble-2', undefined, null]) {
    assert.equal(selectFromHash(hash, REGIONS), 'biobio', String(hash));
  }
});

test('selectFromHash decodes percent-encoding and survives malformed input', () => {
  assert.equal(selectFromHash('#nu%62le', REGIONS), 'nuble');
  assert.equal(selectFromHash('#bio%62io', ['biobio']), 'biobio');
  assert.equal(selectFromHash('#%E0%A4%A', REGIONS), 'biobio');
});

test('selectFromHash honours a custom fallback', () => {
  assert.equal(selectFromHash('#x', REGIONS, 'nuble'), 'nuble');
});

test('nextIndex moves with arrows and wraps', () => {
  assert.equal(nextIndex(0, 'ArrowRight', 3), 1);
  assert.equal(nextIndex(2, 'ArrowRight', 3), 0);
  assert.equal(nextIndex(1, 'ArrowLeft', 3), 0);
  assert.equal(nextIndex(0, 'ArrowLeft', 3), 2);
});

test('nextIndex jumps with Home and End', () => {
  assert.equal(nextIndex(1, 'Home', 3), 0);
  assert.equal(nextIndex(0, 'End', 3), 2);
});

test('nextIndex ignores every other key', () => {
  for (const key of ['Enter', ' ', 'Tab', 'ArrowUp', 'ArrowDown', 'a', '', undefined]) {
    assert.equal(nextIndex(1, key, 3), null, String(key));
  }
});

test('canEnhance is true only for exactly three panels', () => {
  assert.equal(canEnhance(3), true);
  for (const n of [0, 1, 2, 4, -1, NaN, undefined]) {
    assert.equal(canEnhance(n), false, String(n));
  }
});
