/* Digitum GHL WhatsApp Popup – reference code on WhatsApp button clicks from Google Ads visits.
 *
 * Only when the URL of the current page carries a gclid:
 *  - on page load the server reserves a unique code (e.g. REF-KM3P),
 *  - a click on any wa.me / api.whatsapp.com link adds "Ref: REF-KM3P" to the end of the prefilled message
 *    and logs the click (Code | GCLID | Click time | Button | Page),
 *  - a new code is reserved for the next click.
 * Without a gclid in the page URL no code is made and nothing is logged: the prefilled text is sent as it is.
 * The link in the page is never changed permanently: every click starts from the original link.
 */
(function () {
  'use strict';
  var C = window.DGWC_REF || {};
  if (!C.ajax || window.__dgwcRef) return;
  window.__dgwcRef = 1;

  var ORIG = 'data-dgwc-href';
  var LABEL = C.label || 'Ref:';
  var PREFIX = C.prefix || 'REF';

  /* ---------- click IDs from THIS page's URL only ---------- */
  var ATTR_KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var attr = {};
  try {
    var q = new URLSearchParams(location.search);
    ATTR_KEYS.forEach(function (k) { var v = (q.get(k) || '').trim(); if (v) attr[k] = v.slice(0, 300); });
  } catch (e) { attr = {}; }
  var hasGclid = !!attr.gclid;

  /* ---------- server calls ---------- */
  function form(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('payload', JSON.stringify(data));
    return fd;
  }

  // Codes are reserved on the server so the same code is never given out twice.
  var nextCode = '', reserving = false, used = {};
  function reserve() {
    if (!hasGclid || reserving || nextCode) return;
    reserving = true;
    fetch(C.ajax, { method: 'POST', body: form(C.reserveAction, { gclid: attr.gclid }), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (b) { if (b && b.ok && b.ref && !used[b.ref]) nextCode = b.ref; })
      .catch(function () { /* offline: a browser-made code is used instead */ })
      .then(function () { reserving = false; });
  }

  // Fallback only, if a click comes before the reservation arrives.
  var CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  function browserCode() {
    var out = '', c = window.crypto || window.msCrypto;
    while (out.length < 4) {
      var b = new Uint8Array(8), i;
      if (c && c.getRandomValues) c.getRandomValues(b);
      else for (i = 0; i < b.length; i++) b[i] = Math.floor(Math.random() * 256);
      for (i = 0; i < b.length && out.length < 4; i++) if (b[i] < 252) out += CHARS.charAt(b[i] % 36); // 252 = 7*36: no bias
    }
    return PREFIX + '-' + out;
  }
  function takeCode() {
    var code = nextCode;
    nextCode = '';
    if (!code || used[code]) { do { code = browserCode(); } while (used[code]); }
    used[code] = 1;
    reserve(); // get the next one ready
    return code;
  }

  function log(data) {
    var fd = form(C.action, data), sent = false;
    try { sent = navigator.sendBeacon && navigator.sendBeacon(C.ajax, fd); } catch (e) { sent = false; }
    if (!sent) {
      try { fetch(C.ajax, { method: 'POST', body: form(C.action, data), credentials: 'same-origin', keepalive: true }); } catch (e) { /* offline */ }
    }
  }

  /* ---------- which links count as WhatsApp chat links ---------- */
  function isWa(href) {
    if (/^whatsapp:\/\/send/i.test(href)) return true;
    var m = String(href).match(/^(?:https?:)?\/\/([^\/?#]+)(\/[^?#]*)?/i);
    if (!m) return false;
    var host = m[1].toLowerCase().replace(/^www\./, ''), path = m[2] || '/';
    if (host === 'wa.me') return /^\/\+?\d*\/?$/.test(path);          // wa.me/971…  (not wa.me/message/… short links)
    if (host === 'api.whatsapp.com' || host === 'web.whatsapp.com' || host === 'whatsapp.com') return /^\/send\/?$/i.test(path);
    return false;
  }

  function escRe(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function getParam(qs, key) {
    var out = null;
    qs.split('&').forEach(function (pair) {
      var i = pair.indexOf('='), k = i < 0 ? pair : pair.slice(0, i);
      try { k = decodeURIComponent(k); } catch (e) {}
      if (k === key && out === null) {
        try { out = decodeURIComponent((i < 0 ? '' : pair.slice(i + 1)).replace(/\+/g, ' ')); } catch (e) { out = ''; }
      }
    });
    return out;
  }

  /** Original link → link with the prefilled text, plus " Ref: CODE" when a code is given. Other parameters are kept. */
  function buildLink(href, code) {
    var hash = '', qs = '', base = href;
    var h = base.indexOf('#'); if (h >= 0) { hash = base.slice(h); base = base.slice(0, h); }
    var q = base.indexOf('?'); if (q >= 0) { qs = base.slice(q + 1); base = base.slice(0, q); }

    var text = getParam(qs, 'text');
    if (text === null || !text.trim()) text = C.defaultText || '';
    // Never stack references if the link already carries one.
    text = text.replace(new RegExp('\\s*' + escRe(LABEL) + '\\s*[A-Z0-9]{1,10}-[A-Za-z0-9]{4,8}\\s*$'), '').trim();
    var full = code ? (text ? text + ' ' : '') + LABEL + ' ' + code : text;

    // Every WhatsApp button opens the business number set in the plugin, whatever number the link had.
    if (C.wa) return 'https://wa.me/' + C.wa + (full ? '?text=' + encodeURIComponent(full) : '');
    if (!full) return href;

    var kept = qs ? qs.split('&').filter(function (pair) {
      var k = pair.split('=')[0];
      try { k = decodeURIComponent(k); } catch (e) {}
      return pair && k !== 'text';
    }) : [];
    kept.push('text=' + encodeURIComponent(full));
    return base + '?' + kept.join('&') + hash;
  }

  function waNumber(href) {
    var m = href.match(/wa\.me\/\+?(\d+)/i) || href.match(/[?&]phone=\+?(\d+)/i);
    return m ? m[1] : '';
  }

  function buttonLabel(a) {
    var t = (a.innerText || a.textContent || '').replace(/\s+/g, ' ').trim();
    if (!t) t = a.getAttribute('aria-label') || a.getAttribute('title') || '';
    if (!t) { var img = a.querySelector('img[alt]'); if (img) t = img.getAttribute('alt'); }
    return (t || 'WhatsApp link').slice(0, 120);
  }

  /* ---------- click handler (capture phase, so it runs before theme/builder scripts) ---------- */
  function onClick(e) {
    if (e.type === 'auxclick' && e.button !== 1) return; // middle-click only
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if (a.closest('#dgwc')) return; // the chat widget handles its own WhatsApp hand-off
    var orig = a.getAttribute(ORIG) || a.getAttribute('href');
    if (!orig || !isWa(orig)) return;

    // No gclid in this page's URL: send the prefilled text only, no code, nothing logged.
    var code = hasGclid ? takeCode() : '';
    var link = buildLink(orig, code);
    if (link === orig) return;

    a.setAttribute(ORIG, orig);
    a.setAttribute('href', link);
    // Put the original link back once the browser has followed this one, so the next click starts fresh.
    clearTimeout(a.__dgwcT);
    a.__dgwcT = setTimeout(function () { a.setAttribute('href', orig); }, 2000);

    if (!code) return;

    var data = {
      code: code,
      button: buttonLabel(a),
      wa_number: C.wa || waNumber(orig),
      page_url: location.href.split('#')[0].slice(0, 500),
      page_title: document.title,
      referrer: document.referrer
    };
    ATTR_KEYS.forEach(function (k) { if (attr[k]) data[k] = attr[k]; });
    log(data);

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: 'dgwc_wa_click', dgwc_ref: code, dgwc_button: data.button, dgwc_page: location.href });
  }

  document.addEventListener('click', onClick, true);
  document.addEventListener('auxclick', onClick, true);

  // Reserve the first code as soon as the page is ready (only when the URL has a gclid).
  reserve();
  // Back/forward cache: a restored page may hold a code that was already used.
  window.addEventListener('pageshow', function (e) { if (e.persisted) reserve(); });
})();
