/* TicketHub theme switcher.
   Three states: "system" (follow prefers-color-scheme), "light", "dark".
   The choice lives in localStorage under `th-theme`; partials/head.php applies it before first
   paint, this file only keeps the controls in sync and handles clicks.
   Markup it understands (works inside cloned modal templates thanks to delegation):
     <button data-theme-toggle>  — cycles system → light → dark → system
     <button data-theme-set="light|dark|system"> — sets that state directly
   Both get data-theme-state / aria-pressed updated so CSS can reflect the current mode. */
(function () {
  'use strict';
  var KEY = 'th-theme';
  var STATES = ['system', 'light', 'dark'];
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function stored() {
    try { var v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'system'; } catch (e) { return 'system'; }
  }
  function effective(state) {
    if (state === 'light' || state === 'dark') return state;
    return mq && mq.matches ? 'dark' : 'light';
  }
  function apply(state) {
    var root = document.documentElement;
    if (state === 'system') {
      root.removeAttribute('data-theme');
      try { localStorage.removeItem(KEY); } catch (e) {}
    } else {
      root.setAttribute('data-theme', state);
      try { localStorage.setItem(KEY, state); } catch (e) {}
    }
    sync();
    document.dispatchEvent(new CustomEvent('th:theme', { detail: { state: state, effective: effective(state) } }));
  }
  function sync() {
    var state = stored();
    var labels = (window.TH && TH.i18n && TH.i18n.theme) || {};
    var toggles = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < toggles.length; i++) {
      toggles[i].setAttribute('data-theme-state', state);
      if (labels.toggle) toggles[i].setAttribute('aria-label', labels.toggle.replace('{0}', labels[state] || state));
      if (labels.toggle) toggles[i].setAttribute('title', labels.toggle.replace('{0}', labels[state] || state));
    }
    var sets = document.querySelectorAll('[data-theme-set]');
    for (var j = 0; j < sets.length; j++) {
      sets[j].setAttribute('aria-pressed', sets[j].getAttribute('data-theme-set') === state ? 'true' : 'false');
    }
  }

  document.addEventListener('click', function (e) {
    var set = e.target.closest && e.target.closest('[data-theme-set]');
    if (set) { e.preventDefault(); apply(set.getAttribute('data-theme-set')); return; }
    var tog = e.target.closest && e.target.closest('[data-theme-toggle]');
    if (tog) {
      e.preventDefault();
      var next = STATES[(STATES.indexOf(stored()) + 1) % STATES.length];
      apply(next);
    }
  });
  // Modals are cloned from <template>s after load; refresh their controls when they appear.
  if (window.MutationObserver) {
    var root = document.getElementById('modalRoot');
    if (root) new MutationObserver(sync).observe(root, { childList: true });
  }
  if (mq) { (mq.addEventListener ? mq.addEventListener('change', sync) : mq.addListener(sync)); }
  window.thTheme = { get: stored, effective: function () { return effective(stored()); }, set: apply, sync: sync };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sync); else sync();
})();
