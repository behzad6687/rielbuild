/* Admin content screens (from the ICM admin). Progressive enhancement only:
   every screen works without this file. No inline handlers (CSP).
   Hooks: data-count, data-serp, data-slug-from, data-lock-until, data-repeater
   and data-rep-*, data-stepper, form[data-quick], table[data-sortable],
   data-gallery, data-w7b-focal, data-autosubmit, data-dialog-open/close,
   data-jf-test, data-next-number, data-add-date. */
(function () {
  'use strict';
  if (window.__icmW7b) return;
  window.__icmW7b = true;
  var d = document;
  var seq = 0;

  function $all(sel, root) { return Array.prototype.slice.call((root || d).querySelectorAll(sel)); }
  function token() {
    var m = d.querySelector('meta[name="csrf-token"]');
    if (m && m.content) return m.content;
    var i = d.querySelector('input[name="_token"]');
    return i ? i.value : '';
  }
  function setToken(t) {
    var m = d.querySelector('meta[name="csrf-token"]');
    if (m) m.content = t;
    $all('input[name="_token"]').forEach(function (i) { i.value = t; });
  }
  /* POST JSON (or FormData) with the CSRF header; one retry with a fresh token on 419. */
  function api(url, body, retried) {
    var isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    var headers = { 'X-CSRF-Token': token(), 'Accept': 'application/json' };
    if (!isForm) headers['Content-Type'] = 'application/json';
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: isForm ? body : JSON.stringify(body) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
          if (r.status === 419 && data && data.token && !retried) { setToken(data.token); return api(url, body, true); }
          if (r.status === 401) { data = data || {}; data.error = data.error || 'Your session ended. Sign in again, then retry.'; }
          return { status: r.status, ok: r.ok && data && data.ok !== false, data: data || {} };
        });
      });
  }
  function slugify(s) {
    s = String(s || '').toLowerCase();
    if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    return s.replace(/[&+]/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 180);
  }

  /* ---- Live counters (data-count="min,max"); admin.js may already have added one ---- */
  function counterFor(el) {
    var parts = String(el.getAttribute('data-count') || '').split(',');
    var min = parseInt(parts[0], 10) || 0, max = parseInt(parts[1], 10) || 0;
    var out = el.parentNode.querySelector('.counter[data-for="' + el.id + '"]');
    if (!out) {
      var next = el.nextElementSibling;
      if (next && next.classList.contains('counter')) return;   // the shell's own counter
      out = d.createElement('span');
      out.className = 'counter';
      out.setAttribute('data-for', el.id);
      out.setAttribute('aria-live', 'polite');
      el.insertAdjacentElement('afterend', out);
    }
    function update() {
      var n = el.value.length;
      out.textContent = n + ' characters' + (max ? (min ? ' (aim for ' + min + ' to ' + max + ')' : ' of ' + max) : '');
      out.classList.toggle('is-off', n > 0 && ((max && n > max) || (min && n < min)));
    }
    el.addEventListener('input', update);
    update();
  }
  window.addEventListener('load', function () { $all('[data-count]').forEach(counterFor); });

  /* ---- Google preview (data-serp) ---- */
  $all('[data-serp]').forEach(function (box) {
    var t = d.getElementById(box.getAttribute('data-serp-title') || '');
    var m = d.getElementById(box.getAttribute('data-serp-meta') || '');
    var tOut = box.querySelector('[data-serp-title-out]');
    var mOut = box.querySelector('[data-serp-meta-out]');
    var t0 = tOut ? tOut.textContent : '', m0 = mOut ? mOut.textContent : '';
    function upd() {
      if (t && tOut) { var v = t.value.trim(); var sn = document.documentElement.getAttribute('data-site') || 'RIELBUILD'; tOut.textContent = v ? (v.slice(-sn.length) === sn ? v : v + ' | ' + sn) : t0; }
      if (m && mOut) { mOut.textContent = m.value.trim() || m0; }
    }
    if (t) t.addEventListener('input', upd);
    if (m) m.addEventListener('input', upd);
  });

  /* ---- Web address built from the headline while a draft ---- */
  $all('[data-slug-from]').forEach(function (slug) {
    var src = d.getElementById(slug.getAttribute('data-slug-from'));
    var dirty = slug.value !== '' && slug.value !== slugify(src ? src.value : '');
    slug.addEventListener('input', function () { dirty = slug.value !== ''; outs(); });
    if (src) src.addEventListener('input', function () { if (!dirty) { slug.value = slugify(src.value); outs(); } });
    function outs() { $all('[data-slug-out="' + slug.id + '"]').forEach(function (o) { o.textContent = slug.value || 'your-headline'; }); }
  });
  $all('[data-lock-until]').forEach(function (input) {
    var box = d.getElementById(input.getAttribute('data-lock-until'));
    function sync() { input.readOnly = !(box && box.checked); }
    if (box) box.addEventListener('change', function () { sync(); if (box.checked) input.focus(); });
    input.addEventListener('input', function () { $all('[data-slug-out="' + input.id + '"]').forEach(function (o) { o.textContent = input.value; }); });
    sync();
  });

  /* ---- Repeaters: add, remove, up, down (event delegation) ---- */
  function cloneTemplate(tpl, index) {
    var html = tpl.innerHTML.split('__i__').join(index);
    var wrap = d.createElement('div');
    wrap.innerHTML = html;
    var suffix = '-r' + (++seq);
    $all('[id]', wrap).forEach(function (el) {
      var old = el.id;
      el.id = old + suffix;
      $all('[for="' + old + '"]', wrap).forEach(function (l) { l.setAttribute('for', el.id); });
      $all('[aria-describedby]', wrap).forEach(function (x) {
        x.setAttribute('aria-describedby', x.getAttribute('aria-describedby').split(' ').map(function (v) { return v.indexOf(old) === 0 ? v.replace(old, el.id) : v; }).join(' '));
      });
    });
    return wrap.firstElementChild;
  }
  /* ---- Course edit: each time slot's "Age group" list follows the age groups on screen,
     so a group added in this visit can be picked for a slot before saving ---- */
  function syncSlotGroups() {
    var groupsBox = d.getElementById('groups'), slotsBox = d.getElementById('slots');
    if (!groupsBox || !slotsBox) return;
    var keys = [], seen = {};
    $all('input[name^="groups["][name$="[key]"]', groupsBox).forEach(function (i) {
      var k = i.value.trim(), lk = k.toLowerCase();
      if (k && !seen[lk]) { seen[lk] = true; keys.push(k); }
    });
    $all('select[name^="slots["][name$="[group]"]', slotsBox).forEach(function (sel) {
      var cur = sel.value, blank = sel.options[0] && sel.options[0].value === '' ? sel.options[0].textContent : 'Every age';
      sel.textContent = '';
      var o0 = d.createElement('option'); o0.value = ''; o0.textContent = blank; sel.appendChild(o0);
      keys.forEach(function (k) { var o = d.createElement('option'); o.value = k.toLowerCase(); o.textContent = k; sel.appendChild(o); });
      sel.value = seen[cur] ? cur : '';
    });
  }
  d.addEventListener('input', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('#groups input[name^="groups["][name$="[key]"]')) syncSlotGroups();
  });
  d.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-rep-add],[data-rep-remove],[data-rep-up],[data-rep-down]') : null;
    if (!b) return;
    e.preventDefault();
    if (b.hasAttribute('data-rep-add')) {
      var box = b.closest('[data-repeater]');
      var tpl = box && box.querySelector('template[data-rep-template]');
      var rows = box && box.querySelector('[data-rep-rows]');
      if (!tpl || !rows) return;
      var row = cloneTemplate(tpl, 'n' + Date.now().toString(36) + (++seq));
      row.classList.add('is-new');
      rows.appendChild(row);
      initSteppers(row);
      syncSlotGroups();
      var first = row.querySelector('input:not([type=hidden]),select,textarea');
      if (first) first.focus();
      return;
    }
    var r = b.closest('[data-rep-row]');
    if (!r) return;
    if (b.hasAttribute('data-rep-remove')) {
      var next = r.nextElementSibling || r.previousElementSibling;
      r.parentNode.removeChild(r);
      syncSlotGroups();
      if (next) { var f = next.querySelector('button,input,select'); if (f) f.focus(); }
    } else if (b.hasAttribute('data-rep-up') && r.previousElementSibling) {
      r.parentNode.insertBefore(r, r.previousElementSibling); b.focus();
    } else if (b.hasAttribute('data-rep-down') && r.nextElementSibling) {
      r.parentNode.insertBefore(r.nextElementSibling, r); b.focus();
    }
  });

  /* ---- Spots-left steppers: 0 is Fully booked, raised from 0 is Available ---- */
  function initSteppers(root) {
    $all('[data-stepper]', root).forEach(function (st) {
      if (st.__w7b) return;
      st.__w7b = true;
      var input = st.querySelector('[data-stepper-input]');
      var row = st.closest('[data-rep-row]') || st.parentNode;
      var status = row ? row.querySelector('[data-slot-status]') : null;
      var last = input.value === '' ? null : parseInt(input.value, 10);
      var timer = null;
      function applyStatus(v) {
        if (!status || status.value === 'hidden') return;
        if (v !== null && v <= 0) status.value = 'fully_booked';
        else if (v !== null && v > 0 && last === 0 && status.value === 'fully_booked') status.value = 'available';
      }
      function changed() {
        var v = input.value === '' ? null : Math.max(0, Math.min(999, parseInt(input.value, 10) || 0));
        if (v !== null) input.value = v;
        applyStatus(v);
        last = v;
        var url = st.getAttribute('data-api'), id = st.getAttribute('data-slot-id');
        if (!url || !id || v === null) return;
        clearTimeout(timer);
        timer = setTimeout(function () {
          st.classList.remove('is-saved', 'is-error');
          st.classList.add('is-saving');
          var capBox = row ? row.querySelector('input[name$="[capacity]"]') : null;   // send the capacity on screen too (2026-10-20)
          var body = { id: parseInt(id, 10), spots: v };
          if (capBox) body.capacity = capBox.value === '' ? null : parseInt(capBox.value, 10);
          api(url, body).then(function (res) {
            st.classList.remove('is-saving');
            if (res.ok) {
              st.classList.add('is-saved');
              if (status && res.data.status) status.value = res.data.status;
              if (row && res.data.capacity !== undefined && res.data.capacity !== null) {   // the capacity follows the spots (2026-10-20)
                var cap = row.querySelector('input[name$="[capacity]"]');
                if (cap) cap.value = res.data.capacity;
                var cw = row.querySelector('[data-capacity-was]'); if (cw) cw.value = res.data.capacity;
                var sw = row.querySelector('[data-spots-was]'); if (sw) sw.value = res.data.spots_left;
              }
              if (row && res.data.spots_left !== undefined) { input.value = res.data.spots_left; last = res.data.spots_left; }
              if (row && res.data.status) { var stw = row.querySelector('[data-status-was]'); if (stw) stw.value = res.data.status; }
              var pl = row ? row.querySelector('[data-places-line]') : null;
              if (pl && res.data.places_line !== undefined) { pl.textContent = res.data.places_line; pl.hidden = !res.data.places_line; }
              $all('[data-spots-stamp]').forEach(function (s) { if (res.data.stamp) s.textContent = res.data.stamp; });
            } else {
              st.classList.add('is-error');
              st.title = (res.data && (res.data.error || res.data.message)) || 'Not saved. Press Save course.';
            }
          }).catch(function () { st.classList.remove('is-saving'); st.classList.add('is-error'); st.title = 'Not saved (offline?). Press Save course.'; });
        }, 450);
      }
      $all('[data-step]', st).forEach(function (b) {
        b.addEventListener('click', function () {
          var v = input.value === '' ? 0 : parseInt(input.value, 10) || 0;
          input.value = Math.max(0, Math.min(999, v + parseInt(b.getAttribute('data-step'), 10)));
          changed();
        });
      });
      input.addEventListener('change', changed);
    });
  }
  initSteppers(d);

  /* ---- A time's places line: typed numbers are saved by "Save course" (2026-10-21) ---- */
  d.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.name || !/\[(capacity|spots_left)\]$/.test(t.name)) return;
    var row = t.closest('[data-rep-row]');
    var pl = row ? row.querySelector('[data-places-line]') : null;
    if (pl) { pl.hidden = false; pl.textContent = 'Not saved yet: press Save course.'; }
  });
  $all('[data-step]').forEach(function (b) {
    b.addEventListener('click', function () {
      var row = b.closest('[data-rep-row]');
      var pl = row ? row.querySelector('[data-places-line]') : null;
      if (pl) { pl.hidden = false; pl.textContent = 'Not saved yet: press Save course.'; }
    });
  });

  /* ---- Quick status buttons (courses list) ---- */
  $all('form[data-quick]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.fetch) return;
      e.preventDefault();
      var btn = f.querySelector('button');
      var row = f.closest('tr');
      var cell = row ? row.querySelector('[data-status-cell]') : null;
      var live = d.querySelector('[data-quick-live]');
      btn.classList.add('is-busy');
      api(f.getAttribute('data-quick'), { id: parseInt(f.elements.id.value, 10), to: f.elements.to.value }).then(function (res) {
        btn.classList.remove('is-busy');
        if (!res.ok) { f.submit(); return; }
        $all('.quick-btn', f.parentNode).forEach(function (b) { b.classList.remove('is-on'); b.setAttribute('aria-pressed', 'false'); });
        btn.classList.add('is-on');
        btn.setAttribute('aria-pressed', 'true');
        if (cell) cell.innerHTML = res.data.chip + (res.data.effective_chip ? '<span class="ed-sub">Shows ' + res.data.effective_chip + '</span>' : '');
        if (live) live.textContent = res.data.message || 'Saved.';
      }).catch(function () { f.submit(); });
    });
  });

  /* ---- Drag to reorder table rows (the arrows are the keyboard and touch way) ---- */
  $all('table[data-sortable]').forEach(function (table) {
    var tbody = table.tBodies[0];
    var dragging = null;
    $all('.handle', table).forEach(function (h) {
      var tr = h.closest('tr');
      h.addEventListener('mousedown', function () { tr.draggable = true; });
      tr.addEventListener('dragstart', function (e) { dragging = tr; tr.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', tr.getAttribute('data-id')); } catch (x) { } });
      tr.addEventListener('dragend', function () {
        tr.classList.remove('is-dragging'); tr.draggable = false;
        $all('tr', tbody).forEach(function (r) { r.classList.remove('is-drop-before', 'is-drop-after'); });
        if (!dragging) return;
        dragging = null;
        var ids = $all('tr[data-id]', tbody).map(function (r) { return parseInt(r.getAttribute('data-id'), 10); });
        api(table.getAttribute('data-sortable'), { ids: ids }).then(function (res) { if (!res.ok) window.location.reload(); }).catch(function () { window.location.reload(); });
      });
    });
    tbody.addEventListener('dragover', function (e) {
      if (!dragging) return;
      var tr = e.target.closest('tr');
      if (!tr || tr === dragging) return;
      e.preventDefault();
      var rect = tr.getBoundingClientRect();
      var after = (e.clientY - rect.top) > rect.height / 2;
      tbody.insertBefore(dragging, after ? tr.nextSibling : tr);
    });
  });

  /* ---- Gallery: one photo per request (spec 2.5) ---- */
  $all('[data-gallery]').forEach(function (box) {
    var input = box.querySelector('[data-gallery-input]');
    var rows = box.querySelector('[data-rep-rows]');
    var tpl = box.querySelector('template[data-rep-template]');
    var status = box.querySelector('[data-gallery-status]');
    if (!input || !rows || !tpl || !window.fetch || !window.FormData) return;
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) return;
      var i = 0, okCount = 0;
      function next() {
        if (i >= files.length) {
          input.value = '';
          status.classList.toggle('is-bad', okCount < files.length);
          if (okCount === files.length) status.textContent = okCount === 1 ? 'Added 1 photo. Add alt text, then save the post.' : 'Added ' + okCount + ' photos. Add alt text, then save the post.';
          return;
        }
        var f = files[i++];
        status.textContent = 'Uploading ' + i + ' of ' + files.length + '...';
        var fd = new FormData();
        fd.append('file', f);
        fd.append('name', box.getAttribute('data-name') || 'news');
        fd.append('_token', token());
        api(box.getAttribute('data-upload-url'), fd).then(function (res) {
          if (res.ok && res.data.id) {
            var row = cloneTemplate(tpl, String(res.data.id));
            var img = row.querySelector('[data-gallery-thumb]');
            if (img) img.src = res.data.thumb;
            var alt = row.querySelector('input[name$="[alt]"]');
            if (alt && res.data.alt) alt.value = res.data.alt;
            rows.appendChild(row);
            okCount++;
          } else {
            status.textContent = f.name + ': ' + ((res.data && (res.data.error || res.data.message)) || 'not added.');
            status.classList.add('is-bad');
          }
          next();
        }).catch(function () { status.textContent = f.name + ': the upload did not finish.'; status.classList.add('is-bad'); next(); });
      }
      next();
    });
  });

  /* ---- Focal point: tap the photo (arrow keys move it by 5%) ---- */
  $all('[data-w7b-focal]').forEach(function (f) {
    var field = f.closest('[data-w7b-image]');
    var dot = f.querySelector('.focal__dot');
    var fx = field.querySelector('[data-focal-x]'), fy = field.querySelector('[data-focal-y]');
    var og = field.querySelector('[data-w7b-ogcrop]');
    f.tabIndex = 0;
    function set(x, y) {
      x = Math.max(0, Math.min(1, x)); y = Math.max(0, Math.min(1, y));
      fx.value = x.toFixed(3); fy.value = y.toFixed(3);
      dot.style.left = (x * 100).toFixed(1) + '%'; dot.style.top = (y * 100).toFixed(1) + '%';
      if (og) og.style.backgroundPosition = (x * 100).toFixed(1) + '% ' + (y * 100).toFixed(1) + '%';
      f.setAttribute('aria-label', 'Focal point at ' + Math.round(x * 100) + '% across, ' + Math.round(y * 100) + '% down. Use the arrow keys to move it.');
    }
    f.addEventListener('click', function (e) {
      var r = f.getBoundingClientRect();
      set((e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
    });
    f.addEventListener('keydown', function (e) {
      var x = parseFloat(fx.value), y = parseFloat(fy.value), s = 0.05;
      if (e.key === 'ArrowLeft') x -= s; else if (e.key === 'ArrowRight') x += s; else if (e.key === 'ArrowUp') y -= s; else if (e.key === 'ArrowDown') y += s; else return;
      e.preventDefault(); set(x, y);
    });
    set(parseFloat(fx.value), parseFloat(fy.value));
  });

  /* ---- Small helpers ---- */
  d.addEventListener('change', function (e) {
    var el = e.target;
    if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) {
      if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit();
    }
  });
  $all('[data-dialog-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dlg = d.getElementById(b.getAttribute('data-dialog-open'));
      if (!dlg) return;
      var frame = dlg.querySelector('iframe[data-src]');
      if (frame && frame.getAttribute('src') === 'about:blank') frame.src = frame.getAttribute('data-src');
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    });
  });
  $all('[data-dialog-close]').forEach(function (b) {
    b.addEventListener('click', function () { var dlg = b.closest('dialog'); if (dlg) { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); } });
  });
  $all('[data-jf-test]').forEach(function (a) {
    var id = d.querySelector('[data-jf-id]'), host = d.querySelector('[data-jf-host]');
    function upd() {
      var m = String(id ? id.value : '').match(/(\d{12,16})/);
      if (!m) { a.setAttribute('aria-disabled', 'true'); a.removeAttribute('href'); return; }
      a.removeAttribute('aria-disabled');
      a.href = host && host.value === 'pci' ? 'https://pci.jotform.com/form/' + m[1] : 'https://form.jotform.com/' + m[1];
    }
    if (id) id.addEventListener('input', upd);
    if (host) host.addEventListener('change', upd);
  });
  $all('[data-next-number]').forEach(function (b) {
    b.addEventListener('click', function () {
      var target = d.getElementById(b.getAttribute('data-target'));
      fetch(b.getAttribute('data-next-number'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (data && data.number && target) { target.value = data.number; target.focus(); } })
        .catch(function () { });
    });
  });
  $all('[data-add-date]').forEach(function (b) {
    b.addEventListener('click', function () {
      var list = d.getElementById(b.getAttribute('data-add-date'));
      if (!list) return;
      var last = list.querySelector('input[type=date]:last-of-type') || list.querySelector('input[type=date]');
      var copy = last.cloneNode(true);
      copy.value = '';
      copy.id = last.id.replace(/-r\d+$/, '') + '-r' + (++seq);
      list.appendChild(copy);
      copy.focus();
    });
  });
}());
