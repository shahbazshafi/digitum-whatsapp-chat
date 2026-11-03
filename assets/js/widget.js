/* Digitum GHL WhatsApp Popup – front-end behaviour. No dependencies. */
(function () {
  'use strict';
  // Some themes/optimisers move scripts above the footer markup, so wait for the DOM if needed.
  if (!document.getElementById('dgwc') && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
  var C = window.DGWC || {};
  var root = document.getElementById('dgwc');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';

  var $ = function (id) { return document.getElementById(id); };
  var fab = $('dgwc-fab'), win = $('dgwc-win'), body = $('dgwc-body'), teaser = $('dgwc-teaser'),
      badge = $('dgwc-badge'), statusEl = $('dgwc-status');
  var openedAt = 0, convFired = false;

  /* ---------- storage helpers (never throw) ---------- */
  function ss(k, v) { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } }
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }

  /* ---------- attribution: capture click IDs + UTMs, keep 90 days ---------- */
  var ATTR_KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var attr = (function () {
    var stored = {};
    try { stored = JSON.parse(ls('dgwc_attr') || '{}') || {}; } catch (e) { stored = {}; }
    if (stored._t && Date.now() - stored._t > 90 * 864e5) stored = {};
    var q;
    try { q = new URLSearchParams(location.search); } catch (e) { q = null; }
    if (q) {
      var fresh = {};
      ATTR_KEYS.forEach(function (k) { var v = q.get(k); if (v) fresh[k] = v.slice(0, 300); });
      // A new ad click replaces the old click IDs; UTMs travel with it.
      if (fresh.gclid || fresh.gbraid || fresh.wbraid || fresh.fbclid || fresh.utm_source) {
        stored = fresh; stored._t = Date.now();
        ls('dgwc_attr', JSON.stringify(stored));
      }
    }
    // Fall back to Google's own conversion-linker cookie if present.
    if (!stored.gclid) {
      var m = document.cookie.match(/(?:^|;\s*)_gcl_aw=([^;]+)/);
      if (m) { var parts = decodeURIComponent(m[1]).split('.'); if (parts.length >= 3) stored.gclid = parts.slice(2).join('.'); }
    }
    return stored;
  })();

  /* ---------- Google Ads tag ---------- */
  function gtagReady() {
    if (!C.conv) return;
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
      window.gtag = function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(C.convId);
      document.head.appendChild(s);
    }
    var configured = false;
    for (var i = 0; i < window.dataLayer.length; i++) {
      var e = window.dataLayer[i];
      if (e && e[0] === 'config' && e[1] === C.convId) { configured = true; break; }
    }
    if (!configured) window.gtag('config', C.convId);
  }
  gtagReady(); // load early so the conversion linker can store the click ID

  function once(fn) { var done = false; return function () { if (!done) { done = true; fn && fn(); } }; }

  function pushEvent(name, extra) {
    window.dataLayer = window.dataLayer || [];
    var ev = { event: name, dgwc_source: C.source, dgwc_page: location.href };
    for (var k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) ev[k] = extra[k];
    window.dataLayer.push(ev);
  }

  function fireConversion(phone, cb) {
    var done = once(cb);
    if (!C.conv || convFired) { done(); return; }
    convFired = true;
    try {
      if (C.enhanced && phone) window.gtag('set', 'user_data', { phone_number: phone });
      window.gtag('event', 'conversion', { send_to: C.convId + '/' + C.convLabel, event_callback: done, event_timeout: 1500 });
    } catch (e) { /* tag blocked */ }
    setTimeout(done, 1600);
  }

  /* ---------- open / close ---------- */
  function hideTeaser() { if (teaser) teaser.classList.add('dgwc-gone'); }
  function open() {
    root.classList.add('dgwc-open', 'dgwc-seen');
    hideTeaser();
    if (badge) badge.hidden = true;
    ss('dgwc_seen', '1');
    fab.setAttribute('aria-expanded', 'true');
    fab.setAttribute('aria-label', 'Close chat');
    if (!openedAt) openedAt = Date.now();
    loadIframe();
    initPhone();
    pushEvent('dgwc_chat_open', {});
    if (C.convTrigger === 'open') fireConversion('', null);
    setTimeout(function () {
      var n = $('dgwc-name');
      if (n && window.innerWidth > 480) { try { n.focus({ preventScroll: true }); } catch (e) { n.focus(); } }
    }, 280);
  }
  function close() {
    root.classList.remove('dgwc-open');
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-label', 'Open chat');
  }
  function isOpen() { return root.classList.contains('dgwc-open'); }

  fab.addEventListener('click', function () { isOpen() ? close() : open(); });
  $('dgwc-min').addEventListener('click', function () { close(); fab.focus(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && e.isTrusted && isOpen()) { close(); fab.focus(); } });

  if (ss('dgwc_seen')) { root.classList.add('dgwc-seen'); if (badge) badge.hidden = true; }

  if (teaser) {
    teaser.addEventListener('click', function (e) { if (e.target.closest('.dgwc-x')) return; open(); });
    teaser.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    $('dgwc-teaser-x').addEventListener('click', function (e) { e.stopPropagation(); hideTeaser(); ss('dgwc_teaser_x', '1'); });
    if (C.teaser && !ss('dgwc_teaser_x') && !ss('dgwc_seen')) {
      setTimeout(function () { if (!isOpen()) teaser.classList.remove('dgwc-gone'); }, Math.max(0, C.teaserDelay | 0) * 1000);
    }
  }

  /* ---------- chat helpers ---------- */
  function now() { try { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
  body.querySelectorAll('[data-time]').forEach(function (m) { if (!m.textContent) m.textContent = now(); });

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function add(el) { body.appendChild(el); try { body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' }); } catch (e) { body.scrollTop = body.scrollHeight; } return el; }
  function bubble(cls, html) { var d = document.createElement('div'); d.className = 'dgwc-msg ' + cls; d.innerHTML = html; return add(d); }
  function fill(tpl, v) {
    return String(tpl || '').replace(/\{(name|full_name|phone|page_url|page_title)\}/g, function (_, k) { return v[k] != null ? v[k] : ''; });
  }
  var tickSVG = '<svg class="dgwc-tick" viewBox="0 0 16 11" aria-hidden="true"><path d="M1 6l3 3 6-7M6 9l1 1 7-8" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var extSVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function isMobile() { return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) || window.innerWidth <= 480; }
  function waLink(text) { return 'https://wa.me/' + C.wa + (text ? '?text=' + encodeURIComponent(text) : ''); }
  function goWhatsApp(text) {
    if (!C.wa) return;
    var url = waLink(text);
    if (isMobile()) { window.location.href = url; return; }
    var w = window.open(url, '_blank', 'noopener');
    if (!w) { /* popup blocked: the "Open WhatsApp" button in the chat is the fallback */ }
  }

  /* ---------- phone normalisation (mirrors the server) ---------- */
  function toE164(cc, raw) {
    raw = String(raw || '').trim();
    cc = String(cc || '').replace(/\D/g, '');
    var dig = raw.replace(/\D/g, ''), full;
    if (!dig) return '';
    if (raw.charAt(0) === '+') full = dig;
    else if (dig.indexOf('00') === 0) full = dig.slice(2);
    else {
      var local = dig.replace(/^0+/, '');
      full = (cc && local.indexOf(cc) === 0 && local.length >= 11 && local.length - cc.length >= 8) ? local : cc + local;
    }
    return (full.length >= 8 && full.length <= 15) ? '+' + full : '';
  }

  /* ---------- built-in form ---------- */
  function post(data) {
    var json = JSON.stringify(data);
    function viaAjax() {
      var fd = new FormData();
      fd.append('action', C.ajaxAction);
      fd.append('payload', json);
      return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json().then(function (b) { return { status: r.status, body: b }; }); });
    }
    return fetch(C.rest, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: json, credentials: 'omit' })
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        // REST blocked by a security plugin, or HTML error page → fall back to admin-ajax.
        if (r.status === 401 || r.status === 404 || r.status >= 500 || ct.indexOf('json') < 0) return viaAjax();
        return r.json().then(function (b) {
          if (r.status === 403 && b && b.code === 'rest_forbidden') return viaAjax();
          return { status: r.status, body: b };
        });
      })
      .catch(viaAjax);
  }

  var form = $('dgwc-form');

  /* ---------- phone field with country flag (intl-tel-input) ---------- */
  var iti = null;
  function guessCountry() {
    var iso = '';
    if (C.autoCountry) {
      try {
        var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        iso = (window.DGWC_TZ && window.DGWC_TZ[tz]) || '';
      } catch (e) { iso = ''; }
    }
    return iso || C.defaultIso || 'ae';
  }
  function initPhone() {
    var input = $('dgwc-phone');
    if (iti || !input || typeof window.intlTelInput !== 'function') return;
    try {
      var start = guessCountry();
      iti = window.intlTelInput(input, {
        initialCountry: start,
        separateDialCode: true,
        countrySearch: true,
        countryOrder: [start, 'ae', 'sa', 'qa', 'om', 'kw', 'bh', 'gb', 'us', 'in', 'pk'],
        autoPlaceholder: 'off',
        formatAsYouType: true,
        nationalMode: true,
        containerClass: 'dgwc-iti',
        i18n: { searchPlaceholder: C.i18n.search },
        loadUtils: function () { return import(C.itiUtils); }
      });
      input.placeholder = input.getAttribute('aria-label') || 'WhatsApp number';

      // Visitor keeps typing a full number after "+" (it lands in the country search):
      // pick the country from the number and move the digits back into the phone field.
      var search = iti.searchInput || document.querySelector('#dgwc .iti__search-input');
      if (search) {
        search.addEventListener('input', function () {
          var v = search.value.replace(/[\s\-().]/g, '');
          if (/^\+?\d{6,}$/.test(v)) {
            iti.setNumber('+' + v.replace('+', ''));
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            setTimeout(function () { input.focus(); }, 0);
          }
        });
      }
      // "00" international prefix (e.g. 0044...) → treat as "+".
      input.addEventListener('input', function () {
        var v = input.value.replace(/[\s\-().]/g, '');
        if (/^00\d{6,}$/.test(v)) iti.setNumber('+' + v.slice(2));
      });
    } catch (e) { iti = null; }
  }
  function readPhone(raw) {
    // Returns {e164, cc} or {e164:''} when invalid.
    if (iti) {
      var d = iti.getSelectedCountryData() || {}, cc = d.dialCode ? '+' + d.dialCode : C.defaultCc;
      var utils = window.intlTelInput && window.intlTelInput.utils;
      if (utils) {
        var num = iti.getNumber();
        var ok = iti.isValidNumber();
        if (ok && /^\+\d{8,15}$/.test(num)) {
          var real = (iti.getSelectedCountryData() || {}).dialCode;
          return { e164: num, cc: real ? '+' + real : cc };
        }
        if (ok === false) return { e164: '' };
      }
      return { e164: toE164(cc, raw), cc: cc };
    }
    return { e164: toE164(C.defaultCc, raw), cc: C.defaultCc };
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var nameEl = $('dgwc-name'), phoneEl = $('dgwc-phone'), consentEl = $('dgwc-consent'), err = $('dgwc-err'),
          btn = form.querySelector('.dgwc-send'), btnT = form.querySelector('.dgwc-send-t');
      [nameEl, phoneEl].forEach(function (i) { i.classList.remove('dgwc-bad'); i.removeAttribute('aria-invalid'); });
      err.textContent = '';
      var ph = readPhone(phoneEl.value);
      var name = nameEl.value.trim().replace(/\s+/g, ' '), cc = ph.cc || C.defaultCc, phone = ph.e164, problems = [];
      if (name.length < 2) { nameEl.classList.add('dgwc-bad'); nameEl.setAttribute('aria-invalid', 'true'); problems.push(C.i18n.needName); }
      if (!phone) { phoneEl.classList.add('dgwc-bad'); phoneEl.setAttribute('aria-invalid', 'true'); problems.push(C.i18n.needPhone); }
      if (problems.length) { err.textContent = C.i18n.pleaseAdd.replace('%s', problems.join(' ' + C.i18n.and + ' ')); (problems[0] === C.i18n.needName ? nameEl : phoneEl).focus(); return; }
      if (!consentEl.checked) { err.textContent = C.i18n.needConsent; return; }

      var label = btnT.textContent;
      btn.disabled = true; btnT.textContent = C.i18n.sending;

      var data = {
        full_name: name, country_code: cc, phone: phone, consent: 1,
        website: ($('dgwc-website') || {}).value || '',
        elapsed: openedAt ? Date.now() - openedAt : 0,
        page_url: location.href, page_title: document.title, referrer: document.referrer
      };
      ATTR_KEYS.forEach(function (k) { if (attr[k]) data[k] = attr[k]; });

      post(data).then(function (res) {
        var b = res.body || {};
        if (!b.ok) {
          btn.disabled = false; btnT.textContent = label;
          err.textContent = b.message || C.i18n.netError;
          if (b.code === 'name') nameEl.classList.add('dgwc-bad');
          if (b.code === 'phone') phoneEl.classList.add('dgwc-bad');
          return;
        }
        success(name, b.phone || phone);
      }).catch(function () {
        btn.disabled = false; btnT.textContent = label;
        err.textContent = C.i18n.netError;
      });
    });
  }

  function success(name, phone) {
    var first = name.split(' ')[0];
    var vars = { name: first, full_name: name, phone: phone, page_url: location.href, page_title: document.title };
    var waText = fill(C.waText, vars);

    pushEvent('dgwc_lead', { dgwc_phone: phone });

    if (iti) { try { iti.destroy(); } catch (e) {} iti = null; }
    form.remove();
    var out = bubble('dgwc-out dgwc-tail', esc(name) + '<br>' + esc(phone) + '<span class="dgwc-meta">' + now() + ' ' + tickSVG + '</span>');

    // Conversion fires on the real submit, then WhatsApp opens (callback waits for the tag, max ~1.6s).
    if (C.convTrigger === 'submit') {
      fireConversion(phone, function () {
        if (C.after === 'wa') goWhatsApp(waText);
      });
    } else if (C.after === 'wa') {
      setTimeout(function () { goWhatsApp(waText); }, 900);
    }

    setTimeout(function () {
      var t = out.querySelector('.dgwc-tick'); if (t) t.classList.add('dgwc-read');
      statusEl.dataset.orig = statusEl.dataset.orig || statusEl.textContent;
      statusEl.textContent = C.i18n.typing;
      var ty = document.createElement('div'); ty.className = 'dgwc-typing'; ty.innerHTML = '<i></i><i></i><i></i>'; add(ty);
      setTimeout(function () {
        ty.remove(); statusEl.textContent = statusEl.dataset.orig;
        var sender = '<span class="dgwc-sender">' + esc(C.name) + '</span>';
        if (C.after === 'wa' && C.wa) {
          var b = bubble('dgwc-in dgwc-tail', sender + esc(fill(C.waReply, vars)) + '<span class="dgwc-meta">' + now() + '</span>' +
            '<button class="dgwc-cta" type="button">' + extSVG + esc(C.i18n.openWa) + '</button>');
          b.querySelector('.dgwc-cta').addEventListener('click', function () {
            // Direct user tap: never blocked.
            if (isMobile()) window.location.href = waLink(waText); else window.open(waLink(waText), '_blank', 'noopener');
          });
        } else {
          bubble('dgwc-in dgwc-tail', sender + esc(fill(C.thanks, vars)) + '<span class="dgwc-meta">' + now() + '</span>');
        }
      }, 1100);
    }, 600);
  }

  /* ---------- GHL embedded form ---------- */
  var iframe = $('dgwc-iframe');
  function loadIframe() {
    if (!iframe || iframe.getAttribute('src')) return;
    var src = iframe.getAttribute('data-src');
    if (!src) return;
    try {
      var u = new URL(src);
      var key = C.gclidKey || 'gclid';
      if (attr.gclid) u.searchParams.set(key, attr.gclid);
      ['gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) { if (attr[k]) u.searchParams.set(k, attr[k]); });
      u.searchParams.set('page_url', location.href.split('#')[0].slice(0, 500));
      src = u.toString();
    } catch (e) { /* keep original */ }
    iframe.setAttribute('src', src);
  }
  if (iframe) {
    var formOrigin = '';
    try { formOrigin = new URL(iframe.getAttribute('data-src')).origin; } catch (e) { formOrigin = ''; }
    window.addEventListener('message', function (e) {
      var o = e.origin || '';
      var trusted = o === formOrigin || /(^|\.)leadconnectorhq\.com$|(^|\.)msgsndr\.com$|(^|\.)gohighlevel\.com$/.test(o.replace(/^https?:\/\//, ''));
      if (!trusted) return;
      var d = e.data, submitted = false;
      if (Array.isArray(d) && d[0] === 'set-sticky-contacts') submitted = true;
      else if (d && typeof d === 'object' && !Array.isArray(d)) {
        var t = String(d.event || d.type || d.action || '');
        if (/form[_-]?submit|survey[_-]?submit|submitted/i.test(t)) submitted = true;
      }
      if (!submitted) return;
      pushEvent('dgwc_lead', {});
      if (C.convTrigger === 'submit') fireConversion('', null);
    });
  }
  }
})();
