/* Admin behaviours (from the ICM admin). No library, no inline handlers (CSP).
 * window.ICMAdmin: api(url, opts), upload(file, extra, onProgress), pickMedia(opts), toast(msg)
 * Hooks: data-drawer-open/-close, data-confirm, data-busy, data-reveal, data-copy, data-print,
 *        data-count, data-lint, data-dirty-guard, data-focal, data-upload, data-image-field, data-serp. */
(function () {
  'use strict';
  var d = document;
  var $ = function (sel, root) { return (root || d).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || d).querySelectorAll(sel)); };
  var token = function () { var m = $('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; };
  var HEIC = 'This photo is in HEIC format. Share it as JPEG, or set the camera to Most Compatible.';

  // ---------------------------------------------------------------- API
  function api(url, opts) {
    opts = opts || {};
    var send = function (retry) {
      var init = { method: opts.method || (opts.body || opts.form ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-CSRF-Token': token(), 'Accept': 'application/json' } };
      if (opts.form) init.body = opts.form;
      else if (opts.body) { init.body = JSON.stringify(opts.body); init.headers['Content-Type'] = 'application/json'; }
      return fetch(url, init).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (r.status === 419 && j.token && retry) { var m = $('meta[name="csrf-token"]'); if (m) m.setAttribute('content', j.token); return send(false); }
          if (r.status === 401) { var e = new Error('Your session ended. Sign in again, then retry.'); e.auth = true; throw e; }
          if (!r.ok || j.ok === false) { throw new Error(j.error && typeof j.error === 'string' && j.error.length > 12 ? j.error : (j.message || 'Something went wrong. Please try again.')); }
          return j;
        });
      });
    };
    return send(true);
  }

  /** One file per request (spec 2.5), with progress. Resolves the media JSON. */
  function upload(file, extra, onProgress) {
    return new Promise(function (resolve, reject) {
      var name = (file.name || '').toLowerCase();
      if (/image\/hei[cf]/.test(file.type) || /\.(heic|heif)$/.test(name)) return reject(new Error(HEIC));
      var isPdf = file.type === 'application/pdf' || /\.pdf$/.test(name);
      if (file.size > (isPdf ? 10 : 12) * 1048576) return reject(new Error('That file is too large (limit ' + (isPdf ? 10 : 12) + ' MB).'));
      var fd = new FormData();
      fd.append('file', file, file.name);
      Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
      var go = function (retry) {
        var x = new XMLHttpRequest();
        x.open('POST', '/admin/api/media/upload');
        x.setRequestHeader('X-CSRF-Token', token());
        x.setRequestHeader('Accept', 'application/json');
        if (x.upload && onProgress) x.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        x.onload = function () {
          var j = {}; try { j = JSON.parse(x.responseText); } catch (e) { /* not JSON */ }
          if (x.status === 419 && j.token && retry) { var m = $('meta[name="csrf-token"]'); if (m) m.setAttribute('content', j.token); return go(false); }
          if (x.status === 401) return reject(new Error('Your session ended. Sign in again, then retry.'));
          if (x.status >= 200 && x.status < 300 && j.ok) return resolve(j.media);
          reject(new Error(j.error && j.error.length > 12 ? j.error : (j.message || 'The upload failed. Please try again.')));
        };
        x.onerror = function () { reject(new Error('The connection dropped. Please try again.')); };
        x.send(fd);
      };
      go(true);
    });
  }

  function toast(msg, bad) {
    var main = $('#main') || d.body;
    var el = d.createElement('div');
    el.className = 'alert ' + (bad ? 'alert--bad' : 'alert--ok');
    el.setAttribute('role', bad ? 'alert' : 'status');
    el.textContent = msg;
    main.insertBefore(el, main.firstChild);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 6000);
  }

  // ---------------------------------------------------------------- dialog helper
  function dialog(title, bodyEl, buttons) {
    var dlg = d.createElement('dialog');
    dlg.className = 'dlg';
    dlg.setAttribute('aria-label', title);
    var head = d.createElement('div'); head.className = 'dlg__head';
    var h = d.createElement('h2'); h.textContent = title; head.appendChild(h);
    var x = d.createElement('button'); x.type = 'button'; x.className = 'btn btn--line btn--sm'; x.textContent = 'Close';
    x.addEventListener('click', function () { dlg.close(); });
    head.appendChild(x);
    var body = d.createElement('div'); body.className = 'dlg__body'; body.appendChild(bodyEl);
    var foot = d.createElement('div'); foot.className = 'dlg__foot';
    (buttons || []).forEach(function (b) { foot.appendChild(b); });
    dlg.appendChild(head); dlg.appendChild(body); dlg.appendChild(foot);
    dlg.addEventListener('close', function () { setTimeout(function () { if (dlg.parentNode) dlg.parentNode.removeChild(dlg); }, 0); });
    d.body.appendChild(dlg);
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    return dlg;
  }
  function button(label, cls) { var b = d.createElement('button'); b.type = 'button'; b.className = 'btn ' + (cls || 'btn--line'); b.textContent = label; return b; }

  /** The media picker. Resolves the chosen item's JSON, or null. */
  function pickMedia(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var wrap = d.createElement('div');
      var row = d.createElement('div'); row.className = 'toolbar';
      var q = d.createElement('input'); q.type = 'search'; q.placeholder = 'Search alt text or file names'; q.setAttribute('aria-label', 'Search media');
      var fileLbl = d.createElement('label'); fileLbl.className = 'btn btn--line'; fileLbl.textContent = 'Upload';
      var file = d.createElement('input'); file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp'; file.className = 'sr-only'; fileLbl.appendChild(file);
      var qf = d.createElement('div'); qf.className = 'field field--grow'; qf.appendChild(q);
      row.appendChild(qf); row.appendChild(fileLbl);
      var status = d.createElement('p'); status.className = 'muted small'; status.setAttribute('aria-live', 'polite');
      var grid = d.createElement('div'); grid.className = 'picker-grid';
      var more = button('Show more'); more.hidden = true;
      wrap.appendChild(row); wrap.appendChild(status); wrap.appendChild(grid); wrap.appendChild(more);
      var chosen = null; var page = 1; var pages = 1;
      var choose = button('Use this image', 'btn--primary'); choose.disabled = true;
      var done = false;
      var finish = function (v) { if (done) return; done = true; resolve(v); dlg.close(); };
      var dlg = dialog(opts.title || 'Choose an image', wrap, [choose]);
      dlg.addEventListener('close', function () { if (!done) { done = true; resolve(null); } });
      choose.addEventListener('click', function () { finish(chosen); });
      var render = function (items, append) {
        if (!append) grid.textContent = '';
        items.forEach(function (it) {
          var b = d.createElement('button'); b.type = 'button'; b.setAttribute('aria-pressed', 'false');
          b.setAttribute('aria-label', it.alt || it.name || 'Image');
          var img = d.createElement('img'); img.src = it.thumb; img.alt = ''; img.loading = 'lazy'; b.appendChild(img);
          b.addEventListener('click', function () { $$('button', grid).forEach(function (o) { o.setAttribute('aria-pressed', 'false'); }); b.setAttribute('aria-pressed', 'true'); chosen = it; choose.disabled = false; });
          b.addEventListener('dblclick', function () { chosen = it; finish(it); });
          grid.appendChild(b);
        });
      };
      var load = function (append) {
        status.textContent = 'Loading...';
        api('/admin/api/media/list?kind=image&page=' + page + '&q=' + encodeURIComponent(q.value)).then(function (j) {
          pages = j.pages; render(j.items, append);
          status.textContent = j.total ? j.total + ' images' : 'No images found.';
          more.hidden = page >= pages;
        }).catch(function (e) { status.textContent = e.message; });
      };
      var t; q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { page = 1; load(false); }, 300); });
      more.addEventListener('click', function () { page++; load(true); });
      file.addEventListener('change', function () {
        if (!file.files[0]) return;
        status.textContent = 'Uploading...';
        upload(file.files[0], {}, function (p) { status.textContent = 'Uploading ' + Math.round(p * 100) + '%'; })
          .then(function (m) { finish(m); })
          .catch(function (e) { status.textContent = e.message; });
      });
      load(false);
    });
  }

  window.ICMAdmin = { api: api, upload: upload, pickMedia: pickMedia, toast: toast, dialog: dialog, button: button };

  // ---------------------------------------------------------------- drawer
  var shell = $('[data-shell]');
  var backdrop = $('.backdrop');
  var openers = $$('[data-drawer-open]');
  var setDrawer = function (open) {
    if (!shell) return;
    shell.classList.toggle('is-open', open);
    if (backdrop) backdrop.hidden = !open;
    openers.forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (open) { var f = $('#side a, #side button'); if (f) f.focus(); }
  };
  openers.forEach(function (b) { b.addEventListener('click', function () { setDrawer(!shell.classList.contains('is-open')); }); });
  $$('[data-drawer-close]').forEach(function (b) { b.addEventListener('click', function () { setDrawer(false); }); });
  d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && shell && shell.classList.contains('is-open')) { setDrawer(false); if (openers[0]) openers[0].focus(); } });

  // ---------------------------------------------------------------- confirm, busy, dirty
  d.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-confirm], a[data-confirm]') : null;
    if (b && !window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
  }, true);
  d.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute('data-confirm') && !window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); return; }
    var req = $$('[data-image-field][data-require-alt]', f).filter(function (w) { return $('[data-image-id]', w).value && !$('[data-image-alt]', w).value.trim(); });
    if (req.length) {
      e.preventDefault();
      var err = $('[data-image-error]', req[0]); err.textContent = 'Add alt text for this image before saving.'; err.hidden = false;
      $('[data-image-alt]', req[0]).focus();
      return;
    }
    f.removeAttribute('data-dirty');
    var btn = e.submitter || $('[type=submit]', f);
    if (btn && btn.hasAttribute('data-busy')) {
      setTimeout(function () { btn.disabled = true; btn.setAttribute('data-label', btn.textContent); btn.textContent = btn.getAttribute('data-busy'); }, 0);
    }
  });
  $$('form[data-dirty-guard]').forEach(function (f) { f.addEventListener('input', function () { f.setAttribute('data-dirty', '1'); }); });
  window.addEventListener('beforeunload', function (e) { if ($('form[data-dirty="1"]')) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('pageshow', function () { $$('button[data-label]').forEach(function (b) { b.disabled = false; b.textContent = b.getAttribute('data-label'); }); });

  // ---------------------------------------------------------------- small helpers
  $$('[data-reveal]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = d.getElementById(b.getAttribute('data-reveal')); if (!i) return;
      var show = i.type === 'password'; i.type = show ? 'text' : 'password';
      b.textContent = show ? 'Hide' : 'Show'; b.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = b.getAttribute('data-copy'); var label = b.textContent;
      var ok = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = label; }, 1800); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok, function () { fallback(); });
      else fallback();
      function fallback() { var t = d.createElement('textarea'); t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0'; d.body.appendChild(t); t.select(); try { d.execCommand('copy'); ok(); } catch (e) { /* ignore */ } d.body.removeChild(t); }
    });
  });
  $$('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  // Character counters: data-count="min,max"
  $$('[data-count]').forEach(function (i) {
    var p = i.getAttribute('data-count').split(','); var min = +p[0] || 0; var max = +p[1] || 0;
    var out = d.createElement('span'); out.className = 'counter'; out.setAttribute('aria-live', 'polite');
    i.parentNode.insertBefore(out, i.nextSibling);
    var up = function () { var n = i.value.length; out.textContent = n + ' characters (aim for ' + min + ' to ' + max + ')'; out.classList.toggle('is-off', n > 0 && (n < min || n > max)); };
    i.addEventListener('input', up); up();
  });

  // Live house-style hints on plain fields (the server check runs on save too).
  var BANNED = ['leverage', 'seamless', 'empower', 'unlock', 'robust', 'actionable', 'data-driven', 'solutions', 'testament', 'landscape', 'delve', 'elevate'];
  function lint(text) {
    var out = [];
    if (/[\u2013\u2014]/.test(text)) out.push('Has an em dash or en dash. Use a comma or a hyphen instead.');
    BANNED.forEach(function (w) { var m = text.match(new RegExp('\\b' + w + '[a-z]*', 'i')); if (m && !/^elevators?$/i.test(m[0])) out.push('Uses "' + m[0].toLowerCase() + '", which is on the house style list.'); });
    return out;
  }
  window.ICMAdmin.lint = lint;
  $$('[data-lint]').forEach(function (i) {
    var box = null; var t;
    i.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () {
        var p = lint(i.value);
        if (!box) { box = d.createElement('div'); box.className = 'lint'; box.setAttribute('role', 'status'); i.parentNode.appendChild(box); }
        box.hidden = !p.length; box.textContent = p.join(' ');
      }, 500);
    });
  });

  // Google result preview (SEO panel).
  $$('[data-serp]').forEach(function (s) {
    var ti = d.getElementById(s.getAttribute('data-serp-title') || ''); var me = d.getElementById(s.getAttribute('data-serp-meta') || '');
    var to = $('[data-serp-title-out]', s); var mo = $('[data-serp-meta-out]', s);
    var fb = to ? to.textContent : '';
    var up = function () {
      if (ti && to) { var v = ti.value.trim(); var sn = document.documentElement.getAttribute('data-site') || 'RIELBUILD'; to.textContent = v ? (v.slice(-sn.length) === sn ? v : v + ' | ' + sn) : fb; }
      if (me && mo) mo.textContent = me.value.trim();
    };
    if (ti) ti.addEventListener('input', up); if (me) me.addEventListener('input', up);
  });

  // Media item flags: one radio group drives two hidden fields.
  $$('input[name="kind_flag"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var f = r.form; var real = $('[data-flag-real-v]', f); var ai = $('[data-flag-ai-v]', f);
      if (real) real.value = r.value === 'real' ? '1' : '0'; if (ai) ai.value = r.value === 'ai' ? '1' : '0';
    });
  });

  // ---------------------------------------------------------------- focal point
  function setupFocal(box, onSet) {
    var dot = $('.focal__dot', box); var img = $('img', box);
    if (!dot || !img) return;
    var place = function (x, y, save) {
      x = Math.min(1, Math.max(0, x)); y = Math.min(1, Math.max(0, y));
      box.setAttribute('data-x', x.toFixed(3)); box.setAttribute('data-y', y.toFixed(3));
      dot.style.left = (x * 100).toFixed(1) + '%'; dot.style.top = (y * 100).toFixed(1) + '%';
      var scope = box.closest('[data-image-field]') || box.closest('form') || d;
      var og = $('[data-ogcrop]', scope); if (og) og.style.backgroundPosition = (x * 100).toFixed(1) + '% ' + (y * 100).toFixed(1) + '%';
      if (save && onSet) onSet(x, y);
    };
    box.addEventListener('click', function (e) {
      if (box.classList.contains('is-empty')) return;
      var r = img.getBoundingClientRect(); if (!r.width) return;
      place((e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height, true);
    });
    dot.addEventListener('keydown', function (e) {
      var k = { ArrowLeft: [-.02, 0], ArrowRight: [.02, 0], ArrowUp: [0, -.02], ArrowDown: [0, .02] }[e.key];
      if (!k) return; e.preventDefault();
      place(+box.getAttribute('data-x') + k[0], +box.getAttribute('data-y') + k[1], true);
    });
    return place;
  }
  $$('[data-focal]').forEach(function (box) {
    if (box.hasAttribute('data-media-focal')) return;   // image fields wire their own
    var f = box.closest('form');
    setupFocal(box, function (x, y) {
      var fx = f && $('[data-focal-x]', f); var fy = f && $('[data-focal-y]', f);
      if (fx) fx.value = x.toFixed(3); if (fy) fy.value = y.toFixed(3);
      if (f) f.setAttribute('data-dirty', '1');
    });
  });

  // ---------------------------------------------------------------- image fields
  $$('[data-image-field]').forEach(function (w) {
    var idIn = $('[data-image-id]', w); var prev = $('[data-image-preview]', w); var box = $('[data-focal]', w);
    var dot = $('.focal__dot', w); var empty = $('.imgfield__empty', w); var og = $('[data-ogcrop]', w);
    var alt = $('[data-image-alt]', w); var err = $('[data-image-error]', w); var clear = $('[data-image-clear]', w);
    var onMedia = w.hasAttribute('data-alt-on-media');
    var showErr = function (m) { err.textContent = m; err.hidden = !m; };
    var set = function (m) {
      idIn.value = m ? m.id : '';
      prev.hidden = !m; if (dot) dot.hidden = !m; if (empty) empty.hidden = !!m; if (clear) clear.hidden = !m;
      box.classList.toggle('is-empty', !m);
      if (m) {
        prev.src = m.preview || m.thumb;
        box.setAttribute('data-x', m.focal[0]); box.setAttribute('data-y', m.focal[1]);
        if (dot) { dot.style.left = (m.focal[0] * 100) + '%'; dot.style.top = (m.focal[1] * 100) + '%'; }
        if (og) { og.hidden = false; og.style.backgroundImage = 'url("' + (m.preview || m.thumb) + '")'; og.style.backgroundPosition = (m.focal[0] * 100) + '% ' + (m.focal[1] * 100) + '%'; }
        if (alt && (onMedia || !alt.value)) alt.value = m.alt || '';
      } else if (og) { og.hidden = true; }
      var f = w.closest('form'); if (f) f.setAttribute('data-dirty', '1');
      showErr('');
    };
    setupFocal(box, function (x, y) {
      if (!idIn.value) return;
      api('/admin/api/media/update', { body: { id: +idIn.value, focal_x: x, focal_y: y } }).catch(function (e) { showErr(e.message); });
    });
    var up = $('[data-image-upload]', w);
    if (up) up.addEventListener('change', function () {
      if (!up.files[0]) return;
      showErr('Uploading...');
      upload(up.files[0], alt && alt.value ? { alt: alt.value } : {}, function (p) { showErr('Uploading ' + Math.round(p * 100) + '%'); })
        .then(function (m) { set(m); up.value = ''; })
        .catch(function (e) { showErr(e.message); up.value = ''; });
    });
    var pick = $('[data-image-pick]', w);
    if (pick) pick.addEventListener('click', function () { pickMedia().then(function (m) { if (m) set(m); }); });
    if (clear) clear.addEventListener('click', function () { set(null); });
    if (alt && onMedia) alt.addEventListener('change', function () {
      if (!idIn.value) return;
      api('/admin/api/media/update', { body: { id: +idIn.value, alt: alt.value } }).catch(function (e) { showErr(e.message); });
    });
  });

  // ---------------------------------------------------------------- media library uploads
  $$('[data-upload]').forEach(function (zone) {
    var input = $('[data-upload-input]', zone); var list = $('[data-upload-list]', zone); var drop = $('.drop', zone);
    var queue = []; var busy = false; var okCount = 0;
    var next = function () {
      if (busy) return;
      var item = queue.shift();
      if (!item) { if (okCount && zone.hasAttribute('data-reload')) setTimeout(function () { location.reload(); }, 900); return; }
      busy = true;
      upload(item.file, {}, function (p) { item.bar.value = p; })
        .then(function (m) { okCount++; item.msg.textContent = 'Added'; item.bar.value = 1; item.row.setAttribute('data-id', m.id); })
        .catch(function (e) { item.msg.textContent = e.message; item.msg.className = 'is-bad'; item.bar.hidden = true; })
        .then(function () { busy = false; next(); });
    };
    var add = function (files) {
      Array.prototype.forEach.call(files, function (file) {
        var row = d.createElement('li'); var name = d.createElement('span'); name.textContent = file.name;
        var msg = d.createElement('span'); msg.textContent = 'Waiting'; var bar = d.createElement('progress'); bar.max = 1; bar.value = 0;
        row.appendChild(name); row.appendChild(msg); row.appendChild(bar); list.appendChild(row);
        queue.push({ file: file, row: row, msg: msg, bar: bar });
      });
      next();
    };
    input.addEventListener('change', function () { add(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) add(e.dataTransfer.files); });
  });
})();

/* v3 (2026-10-06): light and dark admin. The switch cycles the theme and remembers it for a year (cookie). */
(function () {
  var b = document.querySelector('[data-theme-toggle]'), root = document.documentElement;
  if (!b) return;
  function dark() { var t = root.getAttribute('data-theme'); return t === 'dark' || (t === 'system' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches); }
  function label() { b.setAttribute('aria-pressed', dark() ? 'true' : 'false'); b.title = dark() ? 'Switch to light' : 'Switch to dark'; }
  b.addEventListener('click', function () {
    var next = dark() ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    document.cookie = 'rb_admin_theme=' + next + ';path=/admin;max-age=31536000;samesite=lax' + (location.protocol === 'https:' ? ';secure' : '');
    label();
  });
  label();
})();

/* v3 step 4: attendance. "Everyone present" ticks every player still open; the counter shows what is marked. */
(function () {
  var f = document.querySelector('[data-att]');
  if (!f) return;
  var out = f.querySelector('[data-att-count]'), all = f.querySelector('[data-att-all]');
  function count() {
    var rows = f.querySelectorAll('.att-row'), yes = 0, no = 0;
    rows.forEach(function (r) {
      var c = r.querySelector('input:checked');
      r.classList.toggle('is-open', !c);
      if (c && c.value === '1') yes++; else if (c) no++;
    });
    if (out) out.textContent = yes + ' present, ' + no + ' absent, ' + (rows.length - yes - no) + ' not marked';
  }
  if (all) all.addEventListener('click', function () {
    f.querySelectorAll('.att-row').forEach(function (r) { if (!r.querySelector('input:checked')) { var y = r.querySelector('input[value="1"]'); if (y) y.checked = true; } });
    count();
  });
  f.addEventListener('change', count);
  count();
})();

/* Certificates (client 2026-10-22): the design fills the level line; players are picked by class and time, all or some. */
(function () {
  var f = document.querySelector('[data-certpick]');
  if (!f) return;
  var design = f.querySelector('[data-cert-design]'), level = f.querySelector('[data-cert-level]');
  var course = f.querySelector('[data-cert-course]'), slot = f.querySelector('[data-cert-slot]');
  var rows = [].slice.call(f.querySelectorAll('.certpick__row')), out = f.querySelector('[data-cert-count]');
  var bar = f.querySelector('[data-cert-bar]'), empty = f.querySelector('[data-cert-empty]');
  var last = design && design.selectedOptions[0] ? design.selectedOptions[0].getAttribute('data-level') : '';
  if (design && level) design.addEventListener('change', function () {
    var l = design.selectedOptions[0].getAttribute('data-level') || '';
    if (l && (level.value.trim() === '' || level.value === last)) level.value = l;
    last = l;
  });
  function shown() { return rows.filter(function (r) { return !r.hidden; }); }
  function count() {
    var n = rows.filter(function (r) { return r.querySelector('input').checked; }).length;
    out.textContent = course.value ? '(' + n + ' of ' + shown().length + ' ticked)' : '';
  }
  function show() {
    var c = course.value, s = slot.value;
    slot.disabled = !c;
    [].forEach.call(slot.options, function (o) { if (o.value) o.hidden = o.getAttribute('data-course') !== c; });
    if (slot.selectedOptions[0] && slot.selectedOptions[0].hidden) { slot.value = ''; s = ''; }
    rows.forEach(function (r) {
      var on = !!c && r.getAttribute('data-course') === c && (!s || r.getAttribute('data-slot') === s);
      var i = r.querySelector('input');
      r.hidden = !on; i.disabled = !on; if (!on) i.checked = false;
    });
    bar.hidden = !c; empty.hidden = !!c;
    count();
  }
  course.addEventListener('change', show);
  slot.addEventListener('change', show);
  f.querySelector('[data-cert-all]').addEventListener('click', function () { shown().forEach(function (r) { r.querySelector('input').checked = true; }); count(); });
  f.querySelector('[data-cert-none]').addEventListener('click', function () { shown().forEach(function (r) { r.querySelector('input').checked = false; }); count(); });
  f.addEventListener('change', function (e) { if (e.target.name === 'students[]') count(); });
  show();
})();
