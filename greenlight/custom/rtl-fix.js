/**
 * RTL enforcer + ensure only ONE Hoda React bundle runs.
 * Layout already loads /styles/hoda-main.js — do NOT inject a second copy.
 */
(function () {
  'use strict';

  function neutralizeStockMain(el) {
    try {
      el.type = 'disabled/javascript';
      el.removeAttribute('src');
      if (el.parentNode) el.parentNode.removeChild(el);
    } catch (e) { /* ignore */ }
  }

  function stripStockMains() {
    var nodes = document.querySelectorAll('script[src*="/assets/main-"]');
    for (var i = 0; i < nodes.length; i++) neutralizeStockMain(nodes[i]);
  }

  // If layout forgot hoda-main, inject once. Never inject a duplicate.
  function ensureHodaMain() {
    var existing = document.querySelectorAll('script[src*="hoda-main.js"]');
    if (existing.length > 0) return;
    var s = document.createElement('script');
    s.src = '/styles/hoda-main.js';
    s.defer = true;
    s.setAttribute('data-hoda-main', '1');
    (document.head || document.documentElement).appendChild(s);
  }

  stripStockMains();
  ensureHodaMain();

  if (window.MutationObserver) {
    var packObs = new MutationObserver(function () {
      stripStockMains();
      // Remove duplicate hoda-main tags (keep the first)
      var all = document.querySelectorAll('script[src*="hoda-main.js"]');
      for (var i = 1; i < all.length; i++) neutralizeStockMain(all[i]);
    });
    packObs.observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', function () {
      stripStockMains();
      var all = document.querySelectorAll('script[src*="hoda-main.js"]');
      for (var i = 1; i < all.length; i++) neutralizeStockMain(all[i]);
      packObs.disconnect();
    });
  }

  function enforceRtl() {
    var doc = document.documentElement;
    if (doc.getAttribute('dir') !== 'rtl') doc.setAttribute('dir', 'rtl');
    if (doc.getAttribute('lang') !== 'fa' && doc.getAttribute('lang') !== 'fa-IR') {
      doc.setAttribute('lang', 'fa');
    }
  }

  enforceRtl();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enforceRtl);
  }

  if (window.MutationObserver) {
    var observer = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        if (mutations[i].attributeName === 'dir' || mutations[i].attributeName === 'lang') {
          enforceRtl();
          break;
        }
      }
    });
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['dir', 'lang']
    });
  }

  function patchI18n() {
    try {
      if (window.i18next && !window.i18next.__hodaPatched) {
        var origDir = window.i18next.dir.bind(window.i18next);
        window.i18next.dir = function (lng) {
          var code = String(lng || window.i18next.language || 'fa').replace(/_/g, '-').toLowerCase();
          if (code.startsWith('fa') || code.startsWith('ar')) return 'rtl';
          return origDir(lng);
        };
        window.i18next.__hodaPatched = true;
      }
    } catch (e) { /* ignore */ }
  }

  patchI18n();
  window.addEventListener('load', patchI18n);
})();
