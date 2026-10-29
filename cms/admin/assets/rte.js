/* Rich-text editor (from the ICM admin). Vanilla contenteditable, no library.
 * Enhances every <textarea data-rte>. Options (data attributes on the textarea):
 *   data-rte-source="1"   the HTML source view (owner only; the server decides)
 *   data-rte-images="0"   hide the Image button;  data-rte-tables="0" hide Table
 * Toolbar: Paragraph, H2, H3, Bold, Italic, lists, Link (page picker or URL), Quote,
 * Image (upload or library, alt required, caption), Table, Undo, Redo, Fix dashes.
 * Paste keeps structure and drops Word and Google Docs styling. The server runs
 * Sanitizer::html() on every save, so this cleaning is a convenience, not the guard.
 * Needs admin.js (window.ICMAdmin) loaded first. */
(function () {
  'use strict';
  var d = document;
  var A = window.ICMAdmin;
  var KEEP = { P: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, LI: 1, STRONG: 1, EM: 1, A: 1, BLOCKQUOTE: 1, BR: 1, FIGURE: 1, FIGCAPTION: 1, IMG: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1 };
  var DROP = { SCRIPT: 1, STYLE: 1, META: 1, LINK: 1, TITLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, FORM: 1, INPUT: 1, BUTTON: 1, SELECT: 1, TEXTAREA: 1, SVG: 1, MATH: 1, NOSCRIPT: 1, TEMPLATE: 1, VIDEO: 1, AUDIO: 1, CANVAS: 1, HEAD: 1 };
  var RENAME = { B: 'STRONG', I: 'EM', H1: 'H2', H5: 'H4', H6: 'H4', DIV: 'P' };
  var BLOCK = { P: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, BLOCKQUOTE: 1, FIGURE: 1, TABLE: 1 };

  function safeHref(h) {
    h = (h || '').trim();
    if (/^(https?:|mailto:|tel:|sms:)/i.test(h) || /^\/(?!\/)/.test(h) || /^#/.test(h)) return h;
    return '';
  }
  function safeSrc(s) {
    s = (s || '').trim();
    try { var u = new URL(s, location.href); if (u.origin === location.origin) s = u.pathname; } catch (e) { return ''; }
    return /^\/(uploads|assets)\/[A-Za-z0-9._\/-]+$/.test(s) && s.indexOf('..') < 0 ? s : '';
  }

  /** Clean HTML to the site's allowlist (same shape as Sanitizer::html). */
  function clean(html) {
    var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
    var out = d.createElement('div');
    walk(doc.body, out);
    // Wrap loose text and inline runs at the top level in paragraphs.
    var fixed = d.createElement('div'); var p = null;
    Array.prototype.slice.call(out.childNodes).forEach(function (n) {
      var block = n.nodeType === 1 && BLOCK[n.tagName];
      if (block) { p = null; fixed.appendChild(n); return; }
      if (n.nodeType === 3 && !n.nodeValue.trim() && !p) return;
      if (n.nodeType === 1 && n.tagName === 'BR' && !p) return;
      if (!p) { p = d.createElement('p'); fixed.appendChild(p); }
      p.appendChild(n);
    });
    Array.prototype.slice.call(fixed.querySelectorAll('p, h2, h3, h4, li, strong, em, a')).forEach(function (el) {
      if (!el.textContent.trim() && !el.querySelector('img, br') && el.tagName !== 'A') el.parentNode.removeChild(el);
    });
    return fixed.innerHTML.replace(/(<p><br><\/p>\s*)+$/, '').trim();
  }
  function walk(src, dst) {
    Array.prototype.slice.call(src.childNodes).forEach(function (n) {
      if (n.nodeType === 3) { dst.appendChild(d.createTextNode(n.nodeValue.replace(/\u00a0/g, ' '))); return; }
      if (n.nodeType !== 1) return;
      var tag = n.tagName.toUpperCase().replace(/^O:P$/, 'SPAN');
      if (DROP[tag] || /:/.test(n.tagName)) return;
      if (tag === 'SPAN' || tag === 'FONT' || (tag === 'B' && /font-weight:\s*(normal|400)/i.test(n.getAttribute('style') || ''))) {
        var st = n.getAttribute('style') || '';
        var bold = /font-weight:\s*(bold|[6-9]00)/i.test(st); var ital = /font-style:\s*italic/i.test(st);
        if (bold || ital) {
          var outer = d.createElement(bold ? 'strong' : 'em'); var inner = outer;
          if (bold && ital) { inner = d.createElement('em'); outer.appendChild(inner); }
          walk(n, inner); dst.appendChild(outer); return;
        }
        walk(n, dst); return;
      }
      tag = RENAME[tag] || tag;
      if (!KEEP[tag]) { walk(n, dst); return; }
      var el = d.createElement(tag.toLowerCase());
      if (tag === 'A') { var h = safeHref(n.getAttribute('href')); if (!h) { walk(n, dst); return; } el.setAttribute('href', h); if (n.getAttribute('title')) el.setAttribute('title', n.getAttribute('title').slice(0, 200)); }
      if (tag === 'IMG') {
        var s = safeSrc(n.getAttribute('src')); if (!s) return;
        el.setAttribute('src', s); el.setAttribute('alt', n.getAttribute('alt') || '');
        ['width', 'height'].forEach(function (k) { var v = n.getAttribute(k); if (/^\d{1,4}$/.test(v || '')) el.setAttribute(k, v); });
      }
      if (tag === 'TH') { var sc = n.getAttribute('scope'); if (/^(row|col)$/.test(sc || '')) el.setAttribute('scope', sc); }
      if (tag === 'TD') ['colspan', 'rowspan'].forEach(function (k) { var v = n.getAttribute(k); if (/^[1-9]\d?$/.test(v || '')) el.setAttribute(k, v); });
      walk(n, el);
      dst.appendChild(el);
    });
  }

  function fixDashes(root) {
    var tw = d.createTreeWalker(root, NodeFilter.SHOW_TEXT, null); var n; var changed = 0;
    while ((n = tw.nextNode())) {
      var v = n.nodeValue;
      var w = v.replace(/(\d)\s*[\u2013\u2014]\s*(?=[\d$])/g, '$1-').replace(/\s*[\u2013\u2014]\s*/g, ', ').replace(/,\s*([.!?;:])/g, '$1').replace(/^,\s*/, '');
      if (w !== v) { n.nodeValue = w; changed++; }
    }
    return changed;
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function Editor(ta) {
    var self = this;
    var wrap = d.createElement('div'); wrap.className = 'rte';
    var bar = d.createElement('div'); bar.className = 'rte__bar'; bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', 'Formatting');
    var area = d.createElement('div'); area.className = 'rte__area'; area.contentEditable = 'true';
    area.setAttribute('role', 'textbox'); area.setAttribute('aria-multiline', 'true');
    var label = ta.id ? d.querySelector('label[for="' + ta.id + '"]') : null;
    if (label) { if (!label.id) label.id = ta.id + '-label'; area.setAttribute('aria-labelledby', label.id); label.addEventListener('click', function () { area.focus(); }); }
    area.setAttribute('data-placeholder', ta.getAttribute('placeholder') || 'Start writing...');
    var src = d.createElement('textarea'); src.className = 'rte__src'; src.hidden = true; src.setAttribute('aria-label', 'HTML source');
    var foot = d.createElement('div'); foot.className = 'rte__foot';
    var count = d.createElement('span'); var lintOut = d.createElement('span'); lintOut.setAttribute('aria-live', 'polite');
    foot.appendChild(count); foot.appendChild(lintOut);
    area.innerHTML = clean(ta.value);
    ta.hidden = true;
    ta.parentNode.insertBefore(wrap, ta.nextSibling);
    wrap.appendChild(bar); wrap.appendChild(area); wrap.appendChild(src); wrap.appendChild(foot);
    try { d.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers */ }

    var saved = null;
    var keep = function () { var s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange(); };
    var restore = function () { area.focus(); if (saved) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(saved); } };
    var cmd = function (c, v) { restore(); d.execCommand(c, false, v || null); sync(); };
    var insert = function (html) { restore(); d.execCommand('insertHTML', false, html); sync(); };

    var t;
    var sync = function () {
      ta.value = src.hidden ? clean(area.innerHTML) : clean(src.value);
      var words = (area.textContent.trim().match(/\S+/g) || []).length;
      count.textContent = words + (words === 1 ? ' word' : ' words');
      clearTimeout(t);
      t = setTimeout(function () { var p = A && A.lint ? A.lint(area.textContent) : []; lintOut.textContent = p.length ? p.length + ' house style ' + (p.length === 1 ? 'note' : 'notes') + ': ' + p.join(' ') : ''; }, 600);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    };

    var btns = [];
    var add = function (label, title, fn, state) {
      var b = d.createElement('button'); b.type = 'button'; b.className = 'rte__btn'; b.textContent = label;
      b.setAttribute('aria-label', title); b.title = title;
      if (state) b.setAttribute('aria-pressed', 'false');
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the selection
      b.addEventListener('click', function () { keep(); fn(); });
      bar.appendChild(b); btns.push([b, state]); return b;
    };
    var sep = function () { var s = d.createElement('span'); s.className = 'rte__sep'; s.setAttribute('aria-hidden', 'true'); bar.appendChild(s); };

    add('P', 'Paragraph', function () { cmd('formatBlock', '<p>'); });
    add('H2', 'Heading', function () { cmd('formatBlock', '<h2>'); }, 'h2');
    add('H3', 'Subheading', function () { cmd('formatBlock', '<h3>'); }, 'h3');
    sep();
    add('B', 'Bold', function () { cmd('bold'); }, 'bold');
    add('I', 'Italic', function () { cmd('italic'); }, 'italic');
    add('List', 'Bulleted list', function () { cmd('insertUnorderedList'); }, 'insertUnorderedList');
    add('1. List', 'Numbered list', function () { cmd('insertOrderedList'); }, 'insertOrderedList');
    add('Quote', 'Quote', function () { cmd('formatBlock', '<blockquote>'); });
    sep();
    add('Link', 'Link', function () { linkDialog(); });
    // data-rte="full" (default) | "email" (no tables: the email template owns its layout) | "basic" (text only)
    var mode = ta.getAttribute('data-rte') || 'full';
    if (ta.getAttribute('data-rte-images') !== '0' && mode !== 'basic') add('Image', 'Image', function () { imageDialog(); });
    if (ta.getAttribute('data-rte-tables') !== '0' && mode === 'full') add('Table', 'Table', function () { tableDialog(); });
    sep();
    add('Undo', 'Undo', function () { cmd('undo'); });
    add('Redo', 'Redo', function () { cmd('redo'); });
    add('Fix dashes', 'Replace em and en dashes with commas', function () {
      var n = fixDashes(area); sync(); if (A) A.toast(n ? 'Dashes replaced in ' + n + (n === 1 ? ' place.' : ' places.') : 'No dashes found.');
    });
    // The HTML view is the owner's (spec 4.2.2); the server sanitises every save either way.
    if (ta.getAttribute('data-rte-source') === '1' || (d.body.getAttribute('data-role') === 'owner' && ta.getAttribute('data-rte-source') !== '0')) {
      var srcBtn = add('HTML', 'HTML source view', function () {
        if (src.hidden) { src.value = clean(area.innerHTML); src.hidden = false; area.hidden = true; srcBtn.setAttribute('aria-pressed', 'true'); src.focus(); }
        else { area.innerHTML = clean(src.value); src.hidden = true; area.hidden = false; srcBtn.setAttribute('aria-pressed', 'false'); area.focus(); sync(); }
      });
      srcBtn.setAttribute('aria-pressed', 'false');
    }

    var states = function () {
      btns.forEach(function (pair) {
        var s = pair[1]; if (!s) return; var on = false;
        try { on = (s === 'h2' || s === 'h3') ? (d.queryCommandValue('formatBlock') || '').toLowerCase().replace(/[<>]/g, '') === s : d.queryCommandState(s); } catch (e) { on = false; }
        pair[0].setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    };
    d.addEventListener('selectionchange', function () { if (area.contains(d.activeElement) || d.activeElement === area) { keep(); states(); } });

    area.addEventListener('input', sync);
    src.addEventListener('input', sync);
    area.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); keep(); linkDialog(); }
    });
    area.addEventListener('paste', function (e) {
      var cd = e.clipboardData; if (!cd) return;
      e.preventDefault();
      var html = cd.getData('text/html');
      if (html) { d.execCommand('insertHTML', false, clean(html)); }
      else {
        var text = cd.getData('text/plain') || '';
        var paras = text.replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; });
        d.execCommand('insertHTML', false, paras.length > 1 ? paras.join('') : esc(text));
      }
      sync();
    });
    area.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); if (A) A.toast('Use the Image button to add photos.'); } });
    if (ta.form) ta.form.addEventListener('submit', function () { ta.value = src.hidden ? clean(area.innerHTML) : clean(src.value); });

    // ---- dialogs ----
    function field(labelText, input) {
      var f = d.createElement('div'); f.className = 'field'; var l = d.createElement('label'); l.className = 'field__label';
      var id = 'rte-' + Math.random().toString(36).slice(2); l.htmlFor = id; input.id = id; l.textContent = labelText;
      f.appendChild(l); f.appendChild(input); return f;
    }
    function linkDialog() {
      if (!A) { var u = window.prompt('Link address'); if (u && safeHref(u)) cmd('createLink', safeHref(u)); return; }
      var body = d.createElement('div');
      var url = d.createElement('input'); url.type = 'text'; url.placeholder = '/calendar/ or https://...'; url.autocapitalize = 'none'; url.spellcheck = false;
      var cur = saved && saved.startContainer.parentElement ? saved.startContainer.parentElement.closest('a') : null;
      if (cur) url.value = cur.getAttribute('href');
      var find = d.createElement('input'); find.type = 'search'; find.placeholder = 'Search pages and news';
      var list = d.createElement('ul'); list.className = 'picker-list';
      body.appendChild(field('Link address', url)); body.appendChild(field('Or find a page', find)); body.appendChild(list);
      var ok = A.button('Add the link', 'btn--primary'); var rm = A.button('Remove the link');
      var dlg = A.dialog('Link', body, cur ? [rm, ok] : [ok]);
      var load = function () {
        A.api('/admin/api/rte/links?q=' + encodeURIComponent(find.value)).then(function (j) {
          list.textContent = '';
          j.items.forEach(function (it) {
            var li = d.createElement('li'); var b = d.createElement('button'); b.type = 'button';
            b.textContent = it.title; var s = d.createElement('small'); s.textContent = it.kind + ': ' + it.path; b.appendChild(s);
            b.addEventListener('click', function () { url.value = it.path; url.focus(); });
            li.appendChild(b); list.appendChild(li);
          });
        }).catch(function () { /* the URL field still works */ });
      };
      var tm; find.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(load, 250); }); load();
      ok.addEventListener('click', function () {
        var h = safeHref(url.value); if (!h) { url.setCustomValidity('Use a page path such as /calendar/ or a full https:// address.'); url.reportValidity(); return; }
        dlg.close();
        if (saved && saved.collapsed && !cur) insert('<a href="' + esc(h) + '">' + esc(h) + '</a>'); else cmd('createLink', h);
      });
      rm.addEventListener('click', function () { dlg.close(); cmd('unlink'); });
      setTimeout(function () { url.focus(); }, 50);
    }
    function imageDialog() {
      if (!A) return;
      A.pickMedia({ title: 'Add an image' }).then(function (m) {
        if (!m) return;
        var body = d.createElement('div');
        var alt = d.createElement('input'); alt.type = 'text'; alt.maxLength = 300; alt.value = m.alt || ''; alt.required = true;
        var cap = d.createElement('input'); cap.type = 'text'; cap.maxLength = 300; cap.value = m.caption || '';
        body.appendChild(field('Alt text (required: what the photo shows)', alt)); body.appendChild(field('Caption (optional)', cap));
        var ok = A.button('Insert the image', 'btn--primary');
        var dlg = A.dialog('Image details', body, [ok]);
        ok.addEventListener('click', function () {
          if (!alt.value.trim()) { alt.setCustomValidity('Alt text is required.'); alt.reportValidity(); return; }
          dlg.close();
          var img = '<img src="' + esc(m.src) + '" alt="' + esc(alt.value.trim()) + '"' + (m.w ? ' width="' + m.w + '" height="' + m.h + '"' : '') + '>';
          insert('<figure>' + img + (cap.value.trim() ? '<figcaption>' + esc(cap.value.trim()) + '</figcaption>' : '') + '</figure><p><br></p>');
          if (!m.alt && alt.value.trim()) A.api('/admin/api/media/update', { body: { id: m.id, alt: alt.value.trim() } }).catch(function () {});
        });
        setTimeout(function () { alt.focus(); }, 50);
      });
    }
    function tableDialog() {
      var body = d.createElement('div');
      var r = d.createElement('input'); r.type = 'number'; r.min = 1; r.max = 60; r.value = 4; r.inputMode = 'numeric';
      var c = d.createElement('input'); c.type = 'number'; c.min = 1; c.max = 8; c.value = 3; c.inputMode = 'numeric';
      body.appendChild(field('Rows (plus a header row)', r)); body.appendChild(field('Columns', c));
      var ok = A ? A.button('Insert the table', 'btn--primary') : null;
      if (!A) return;
      var dlg = A.dialog('Table', body, [ok]);
      ok.addEventListener('click', function () {
        var rows = Math.min(60, Math.max(1, +r.value || 1)); var cols = Math.min(8, Math.max(1, +c.value || 1));
        var h = '<table><thead><tr>'; for (var i = 0; i < cols; i++) h += '<th scope="col">Heading</th>'; h += '</tr></thead><tbody>';
        for (var j = 0; j < rows; j++) { h += '<tr>'; for (var k = 0; k < cols; k++) h += '<td>&nbsp;</td>'; h += '</tr>'; }
        dlg.close(); insert(h + '</tbody></table><p><br></p>');
      });
    }
    sync();
    self.area = area; self.sync = sync;
  }

  window.ICMRte = { clean: clean, fixDashes: fixDashes, init: function (ta) { return ta.__rte || (ta.__rte = new Editor(ta)); } };
  Array.prototype.forEach.call(d.querySelectorAll('textarea[data-rte]'), function (ta) { window.ICMRte.init(ta); });
})();
