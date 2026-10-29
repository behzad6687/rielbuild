/* Admin: the Menus screen (from the ICM admin).
   Hooks: form[data-menu-item] (the "Links to" panels, the automatic list options, the live preview through
   data-preview), ol[data-menu-sort] (drag and drop within one group, saved through data-sort-url).
   Everything works without it: every panel shows, the arrows and forms post to the server. */
(function () {
  'use strict';

  function token() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  // ---------------- The item form ----------------
  var form = document.querySelector('form[data-menu-item]');
  if (form) {
    var kind = form.querySelector('[name="kind"]');
    var source = form.querySelector('[name="auto_source"]');
    var out = form.querySelector('[data-menu-preview]');
    var timer = null;
    var seq = 0;

    var sync = function () {
      var k = kind ? kind.value : '';
      Array.prototype.forEach.call(form.querySelectorAll('[data-kind-panel]'), function (p) {
        p.hidden = p.getAttribute('data-kind-panel') !== k;
      });
      var s = source ? source.value : '';
      Array.prototype.forEach.call(form.querySelectorAll('[data-auto-for]'), function (f) {
        f.hidden = (' ' + f.getAttribute('data-auto-for') + ' ').indexOf(' ' + s + ' ') < 0;
      });
    };

    var preview = function () {
      var url = form.getAttribute('data-preview');
      if (!url || !out || !window.fetch || !window.FormData) return;
      var fd = new FormData(form);
      fd.delete('image_file');   // never upload a photo just to preview
      var mine = ++seq;
      fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': token() } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { if (j && j.text && mine === seq) out.textContent = j.text; })
        .catch(function () { /* the saved preview stays */ });
    };

    if (kind) kind.addEventListener('change', sync);
    if (source) source.addEventListener('change', sync);
    sync();
    form.addEventListener('change', function (e) {
      if (e.target && e.target.type === 'file') return;
      clearTimeout(timer);
      timer = setTimeout(preview, 250);
    });
    form.addEventListener('input', function (e) {
      if (!e.target || (e.target.name !== 'label' && e.target.name !== 'path')) return;
      clearTimeout(timer);
      timer = setTimeout(preview, 400);
    });
  }

  // ---------------- Drag and drop within a group ----------------
  var msg = document.querySelector('[data-menu-sortmsg]');
  var say = function (t) { if (msg) msg.textContent = t; };
  var dragged = null;

  Array.prototype.forEach.call(document.querySelectorAll('ol[data-menu-sort]'), function (list) {
    var rows = function () {
      return Array.prototype.filter.call(list.children, function (c) { return c.matches && c.matches('li[data-id]'); });
    };
    rows().forEach(function (li) {
      var card = li.querySelector(':scope > .menu-row__card');
      if (!card) return;
      li.setAttribute('draggable', 'true');
      li.addEventListener('dragstart', function (e) {
        if (e.target !== li) return;
        dragged = li;
        li.classList.add('is-dragging');
        if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', li.getAttribute('data-id')); }
        e.stopPropagation();
      });
      li.addEventListener('dragend', function () {
        li.classList.remove('is-dragging');
        dragged = null;
      });
      li.addEventListener('dragover', function (e) {
        if (!dragged || dragged === li || dragged.parentNode !== list) return;
        e.preventDefault();
        e.stopPropagation();
        var box = li.getBoundingClientRect();
        var after = (e.clientY - box.top) > box.height / 2;
        list.insertBefore(dragged, after ? li.nextSibling : li);
      });
      li.addEventListener('drop', function (e) {
        if (!dragged || dragged.parentNode !== list) return;
        e.preventDefault();
        e.stopPropagation();
        var ids = rows().map(function (r) { return parseInt(r.getAttribute('data-id'), 10); });
        var url = list.getAttribute('data-sort-url');
        if (!url || !window.fetch) return;
        say('Saving the new order.');
        fetch(url, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
          body: JSON.stringify({ menu: list.getAttribute('data-menu'), parent_id: parseInt(list.getAttribute('data-parent'), 10) || null, ids: ids })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j && j.ok, j: j }; }); })
          .then(function (res) { say(res.ok ? 'Saved the new order.' : ((res.j && res.j.error) || 'The order could not be saved. Reload the page and try again.')); })
          .catch(function () { say('The order could not be saved. Reload the page and try again.'); });
      });
    });
  });
})();
