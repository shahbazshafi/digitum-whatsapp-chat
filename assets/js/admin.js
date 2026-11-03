/* Digitum GHL WhatsApp Popup – settings page behaviour */
(function ($) {
  'use strict';
  var A = window.DGWC_ADMIN || {};
  var el = function (id) { return document.getElementById(id); };
  var wrap = document.querySelector('.dgwc-admin');
  var form = el('aform');
  if (!wrap || !form) return;

  function field(key) { return form.querySelector('[name="dgwc[' + key + ']"]:not([type=hidden])') || form.querySelector('[name="dgwc[' + key + ']"]'); }
  function val(key) { var f = field(key); return f ? f.value : ''; }
  function on(key) { var f = el('s-' + key); return !!(f && f.checked); }
  function radio(key) { var r = form.querySelector('input[name="dgwc[' + key + ']"]:checked'); return r ? r.value : ''; }
  function txt(id, v) { var n = el(id); if (n) n.textContent = v; }
  function show(id, yes) { var n = el(id); if (n) n.hidden = !yes; }

  /* ---------- tabs ---------- */
  var tabs = wrap.querySelectorAll('.tabs button');
  function setTab(name) {
    tabs.forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === name ? 'true' : 'false'); });
    wrap.querySelectorAll('.tabp').forEach(function (p) { p.hidden = p.dataset.panel !== name; });
    wrap.classList.toggle('is-leads', name === 'leads' || name === 'clicks');
    el('dgwc-tab').value = name;
    try { var u = new URL(location.href); u.searchParams.set('tab', name); u.searchParams.delete('dgwc_msg'); history.replaceState(null, '', u); } catch (e) {}
  }
  tabs.forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });
  setTab(el('dgwc-tab').value || 'general');

  /* ---------- page picker ---------- */
  var PAGES = A.pages || [], sel = (A.selected || []).slice();
  var chips = el('chips'), pin = el('pg-input'), sugg = el('sugg'), hiddenIds = el('s-page_ids');
  function title(id) { for (var i = 0; i < PAGES.length; i++) if (PAGES[i].id === id) return PAGES[i].t; return 'Page'; }
  function syncIds() { hiddenIds.value = sel.join(','); }
  function renderChips() {
    chips.querySelectorAll('.chip2').forEach(function (c) { c.remove(); });
    sel.forEach(function (id) {
      var c = document.createElement('span'); c.className = 'chip2';
      var t = document.createElement('span'); t.textContent = title(id) + ' ';
      var sm = document.createElement('small'); sm.textContent = '#' + id;
      var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Remove ' + title(id));
      x.innerHTML = '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M1 1l8 8M9 1L1 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
      x.addEventListener('click', function () { sel = sel.filter(function (v) { return v !== id; }); renderChips(); changed(); });
      c.appendChild(t); c.appendChild(sm); c.appendChild(x);
      chips.insertBefore(c, pin);
    });
    syncIds();
  }
  function addPage(id) { if (id && sel.indexOf(id) < 0) { sel.push(id); renderChips(); changed(); } pin.value = ''; sugg.hidden = true; }
  function showSugg() {
    var q = pin.value.trim().toLowerCase();
    var list = PAGES.filter(function (p) { return sel.indexOf(p.id) < 0 && (!q || p.t.toLowerCase().indexOf(q) >= 0 || String(p.id).indexOf(q) === 0); });
    sugg.innerHTML = '';
    list.slice(0, 8).forEach(function (p) {
      var b = document.createElement('button'); b.type = 'button';
      var a = document.createElement('span'); a.textContent = p.t;
      var s = document.createElement('small'); s.textContent = (p.type ? p.type + ' · ' : '') + 'ID ' + p.id;
      b.appendChild(a); b.appendChild(s);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); addPage(p.id); });
      sugg.appendChild(b);
    });
    if (/^\d+$/.test(q) && !PAGES.some(function (p) { return String(p.id) === q; })) {
      var b = document.createElement('button'); b.type = 'button';
      b.innerHTML = '<span>Add page ID ' + q + '</span><small>Press Enter</small>';
      b.addEventListener('mousedown', function (e) { e.preventDefault(); addPage(parseInt(q, 10)); });
      sugg.appendChild(b);
    }
    sugg.hidden = !sugg.children.length;
  }
  pin.addEventListener('focus', showSugg);
  pin.addEventListener('input', showSugg);
  pin.addEventListener('blur', function () { setTimeout(function () { sugg.hidden = true; }, 150); });
  pin.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var q = pin.value.trim();
      if (/^\d+$/.test(q)) addPage(parseInt(q, 10));
      else { var f = sugg.querySelector('button'); if (f) f.dispatchEvent(new Event('mousedown')); }
    }
    if (e.key === 'Backspace' && !pin.value && sel.length) { sel.pop(); renderChips(); changed(); }
  });
  renderChips();

  /* ---------- media pickers ---------- */
  function picker(opts) {
    var frame;
    el(opts.button).addEventListener('click', function () {
      if (!window.wp || !wp.media) return;
      if (!frame) {
        frame = wp.media({ title: opts.title, button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
        frame.on('select', function () {
          var a = frame.state().get('selection').first().toJSON();
          el(opts.input).value = a.id;
          var sizes = a.sizes || {};
          el(opts.img).src = (sizes[opts.size] || sizes.full || a).url;
          txt(opts.name, a.filename || a.title || 'Image');
          if (opts.after) opts.after();
          changed();
        });
      }
      frame.open();
    });
  }
  picker({ button: 'avatar-pick', input: 's-avatar_id', img: 'avatar-img', name: 'avatar-name', size: 'thumbnail', title: 'Choose avatar' });
  picker({ button: 'bg-pick', input: 's-bg_id', img: 'bg-img', name: 'bg-name', size: 'large', title: 'Choose chat background',
    after: function () { var r = form.querySelector('input[name="dgwc[bg]"][value="custom"]'); if (r) r.checked = true; } });
  el('avatar-reset').addEventListener('click', function () {
    el('s-avatar_id').value = 0; el('avatar-img').src = A.defaultAvatar; txt('avatar-name', 'Default Digitum avatar'); changed();
  });

  /* ---------- embed validation (mirrors PHP) ---------- */
  function parseEmbed(s) {
    s = String(s || '').trim();
    var m = s.match(/src\s*=\s*["']([^"']+)["']/i);
    var u = (m ? m[1] : s).trim();
    return /^https:\/\/[A-Za-z0-9.\-]+(:\d+)?\/widget\/(form|survey|booking)\/[A-Za-z0-9_\-]+/.test(u) ? u : '';
  }

  /* ---------- render state + preview ---------- */
  function render() {
    var enabled = on('enabled'), vis = radio('visibility'), src = radio('form_source'), after = radio('after_submit'), bg = radio('bg');
    show('pages-box', vis === 'pages');
    show('teaser-box', on('teaser'));
    show('box-builtin', src === 'builtin');
    show('box-iframe', src === 'iframe');
    show('after-wa-box', after === 'wa');
    show('after-th-box', after === 'thanks');
    show('bg-custom-box', bg === 'custom');
    var conv = on('conv_enabled');
    el('conv-box').classList.toggle('off', !conv);
    // Faded, not disabled: disabled fields aren't submitted and the saved ID/label would be lost.
    el('conv-box').querySelectorAll('input[type=text]').forEach(function (i) { i.readOnly = !conv; });
    show('conv-open-warn', radio('conv_trigger') === 'open');

    // WhatsApp button references: show an example of the message a customer will send.
    var refOn = on('ref_enabled');
    el('ref-box').classList.toggle('off', !refOn);
    var prefix = (val('ref_prefix') || 'REF').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 10) || 'REF';
    var rtext = val('ref_text').trim(), rlabel = val('ref_label').trim() || 'Ref:';
    txt('ref-example', 'Example message: “' + (rtext ? rtext + ' ' : '') + rlabel + ' ' + prefix + '-KM3P” (only when the page URL has a gclid; otherwise the message is sent without a Ref)');

    var embed = val('embed'), ok = !!parseEmbed(embed), vp = el('embed-ok');
    vp.textContent = embed.trim() ? (ok ? 'Valid form URL' : 'Not a GHL form URL') : '';
    vp.classList.toggle('bad', !ok);

    var pill = el('st-pill'), live = enabled && !(src === 'iframe' && !ok);
    pill.classList.toggle('live', live && !(vis === 'pages' && !sel.length));
    pill.classList.toggle('warn', enabled && !live);
    txt('st-text', !enabled ? 'Turned off'
      : (src === 'iframe' && !ok) ? 'Hidden until a valid form URL is saved'
      : vis === 'pages' ? (sel.length ? 'Live on ' + sel.length + ' page' + (sel.length === 1 ? '' : 's') : 'No pages selected')
      : 'Live on entire website');

    var name = val('business_name') || 'Your business';
    txt('pv-name', name); txt('pv-sender', name); txt('pv-sub', val('heading'));
    txt('pv-welcome', val('welcome')); txt('pv-notice', val('reply_notice'));
    txt('pv-powered', val('powered_by')); show('pv-foot', !!val('powered_by').trim());
    show('pv-ver', on('verified'));
    txt('pv-consent', val('consent_text'));
    el('pv-check').classList.toggle('off', !on('consent_default'));
    txt('pv-btn', val('button_text') || 'Send message');
    txt('pv-cc', val('default_cc'));
    var iso = ((A.codes || {})[val('default_cc')] || 'ae').toLowerCase();
    el('pv-flag').className = 'iti__flag iti__' + iso;
    show('pv-form', src === 'builtin'); show('pv-btn', src === 'builtin'); show('pv-iframe', src === 'iframe');
    var h = parseInt(val('iframe_height'), 10) || 365, trim = parseInt(val('iframe_trim'), 10) || 0;
    el('pv-iframe').style.height = Math.max(110, Math.min(320, Math.round((h - trim) * 0.6))) + 'px';
    txt('pv-h', (h - trim) + 'px visible in the chat');
    var body = el('pv-body');
    body.classList.toggle('plain', bg === 'plain');
    body.style.backgroundImage = bg === 'plain' ? 'none' : 'url(' + (bg === 'custom' && +el('s-bg_id').value ? el('bg-img').src : A.defaultBg) + ')';
    el('pv-av').src = el('avatar-img').src;
    show('pv-teaser', on('teaser') && !!val('teaser_text').trim()); txt('pv-teaser', val('teaser_text'));
    el('pv-fab').classList.toggle('pulse', on('pulse'));
    show('pv-badge', on('badge'));
    el('pv-row').classList.toggle('left', radio('position') === 'left');
    show('pv-off', !enabled);
  }

  /* ---------- dirty state / save bar ---------- */
  function snapshot() { return $(form).serialize(); }
  var initial = snapshot(), submitting = false;
  var initialHidden = {};
  form.querySelectorAll('input[type=hidden]').forEach(function (h) { initialHidden[h.name] = h.value; });
  var initialImgs = { avatar: el('avatar-img').src, avatarName: el('avatar-name').textContent, bg: el('bg-img').src, bgName: el('bg-name').textContent };

  function changed() { render(); el('savebar').hidden = snapshot() === initial; }
  form.addEventListener('input', changed);
  form.addEventListener('change', changed);
  form.addEventListener('submit', function (e) {
    if (radio('form_source') === 'iframe' && !parseEmbed(val('embed'))) {
      if (!window.confirm('The GHL form URL is not valid, so the button will stay hidden. Save anyway?')) { e.preventDefault(); return; }
    }
    submitting = true;
    el('save').disabled = true; el('save').textContent = 'Saving…';
  });
  el('discard').addEventListener('click', function () {
    form.reset();
    form.querySelectorAll('input[type=hidden]').forEach(function (h) { if (h.name in initialHidden) h.value = initialHidden[h.name]; });
    sel = (A.selected || []).slice(); renderChips();
    el('avatar-img').src = initialImgs.avatar; txt('avatar-name', initialImgs.avatarName);
    el('bg-img').src = initialImgs.bg; txt('bg-name', initialImgs.bgName);
    changed();
  });
  window.addEventListener('beforeunload', function (e) {
    if (!submitting && snapshot() !== initial) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ---------- webhook test ---------- */
  el('test-hook').addEventListener('click', function () {
    var b = this, msg = el('test-hook-msg');
    b.disabled = true; msg.hidden = false; msg.className = 'help'; msg.textContent = 'Sending a test lead…';
    $.post(A.ajax, { action: 'dgwc_test_webhook', nonce: A.nonce, url: val('webhook_url') })
      .done(function (r) { msg.className = 'help ' + (r && r.success ? 'ok' : 'bad'); msg.textContent = (r && r.data && r.data.message) || 'Unknown response'; })
      .fail(function () { msg.className = 'help bad'; msg.textContent = 'Request failed. Check your connection and try again.'; })
      .always(function () { b.disabled = false; });
  });

  /* ---------- Google Sheet ---------- */
  el('test-sheet').addEventListener('click', function () {
    var b = this, msg = el('test-sheet-msg');
    b.disabled = true; msg.hidden = false; msg.className = 'help'; msg.textContent = 'Sending a test row to the sheet…';
    $.post(A.ajax, { action: 'dgwc_test_sheet', nonce: A.nonce, url: val('sheet_url') })
      .done(function (r) { msg.className = 'help ' + (r && r.success ? 'ok' : 'bad'); msg.textContent = (r && r.data && r.data.message) || 'Unknown response'; })
      .fail(function () { msg.className = 'help bad'; msg.textContent = 'Request failed. Check your connection and try again.'; })
      .always(function () { b.disabled = false; });
  });
  el('copy-script').addEventListener('click', function () {
    var t = el('sheet-script'), b = this;
    function done() { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1800); }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t.value).then(done, function () { t.select(); document.execCommand('copy'); done(); });
    else { t.select(); document.execCommand('copy'); done(); }
  });

  render();
})(jQuery);
