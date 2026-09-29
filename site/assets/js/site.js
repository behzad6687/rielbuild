/* RIELBUILD: site-wide behaviour. Plain JS, no dependencies. */
(function () {
  'use strict';
  var d = document, w = window, root = d.documentElement, body = d.body;
  var reduce = w.matchMedia('(prefers-reduced-motion: reduce)');
  var $ = function (s, c) { return (c || d).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); };

  function loadLazy(scope) {
    $$('img[data-src]', scope).forEach(function (img) { img.src = img.getAttribute('data-src'); img.removeAttribute('data-src'); });
  }

  /* header state */
  var lastY = -1;
  function onScrollHeader() {
    var y = w.scrollY || 0;
    if ((y > 40) !== (lastY > 40)) body.classList.toggle('is-scrolled', y > 40);
    lastY = y;
  }
  w.addEventListener('scroll', onScrollHeader, { passive: true });
  onScrollHeader();

  /* desktop menus: hover with intent, click and keyboard (same system as esolutify / NHF / ICM) */
  var nav = $('#nav');
  if (nav) {
    var items = $$('.nav__item', nav), pill = $('.nav__pill', nav), openItem = null, closeT = null, openT = null;
    var setOpen = function (item, on) {
      var btn = $('.nav__btn', item);
      item.classList.toggle('is-open', on);
      if (btn) btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) {
        loadLazy(item);
        if (!item._pre) { item._pre = true; $$('[data-img]', item).forEach(function (a) { var im = new Image(); im.src = a.getAttribute('data-img'); }); }
      }
    };
    var open = function (item) {
      clearTimeout(closeT);
      if (openItem && openItem !== item) setOpen(openItem, false);
      openItem = item; setOpen(item, true);
    };
    var closeAll = function () { if (openItem) setOpen(openItem, false); openItem = null; };
    var movePill = function (el) {
      if (!pill || !el) return;
      var nr = (pill.offsetParent || nav).getBoundingClientRect(), r = el.getBoundingClientRect();
      pill.style.width = r.width + 'px';
      pill.style.transform = 'translate(' + (r.left - nr.left) + 'px,' + (r.top - nr.top) + 'px)';
      nav.classList.add('has-pill');
    };
    items.forEach(function (item) {
      var trigger = $('.nav__btn, .nav__link', item);
      item.addEventListener('mouseenter', function () {
        movePill(trigger);
        if (!item.classList.contains('has-menu')) { clearTimeout(openT); closeT = setTimeout(closeAll, 120); return; }
        clearTimeout(openT);
        openT = setTimeout(function () { open(item); }, openItem ? 0 : 90);
      });
      item.addEventListener('mouseleave', function () { clearTimeout(openT); });
      var btn = $('.nav__btn', item);
      if (btn) btn.addEventListener('click', function () {
        if (item.classList.contains('is-open')) closeAll(); else open(item);
      });
    });
    nav.addEventListener('mouseleave', function () {
      nav.classList.remove('has-pill');
      closeT = setTimeout(closeAll, 260);
    });
    nav.addEventListener('mouseenter', function () { clearTimeout(closeT); });
    d.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && openItem) { var b = $('.nav__btn', openItem); closeAll(); if (b) b.focus(); }
    });
    d.addEventListener('click', function (e) { if (openItem && !nav.contains(e.target)) closeAll(); });
    nav.addEventListener('focusout', function (e) { if (openItem && !nav.contains(e.relatedTarget)) closeAll(); });

    /* service preview in the mega menu */
    var prev = $('.mega__preview', nav);
    if (prev) {
      var pImg = $('img', prev), pName = $('.mp__name', prev), pTag = $('.mp__tag', prev), swapT;
      $$('.mitem', nav).forEach(function (a) {
        var show = function () {
          pName.textContent = a.getAttribute('data-name');
          pTag.textContent = a.getAttribute('data-tag');
          var src = a.getAttribute('data-img');
          if (pImg.getAttribute('src') !== src) {
            prev.classList.add('is-swap');
            clearTimeout(swapT);
            swapT = setTimeout(function () { pImg.onload = function () { prev.classList.remove('is-swap'); }; pImg.src = src; }, 160);
          }
        };
        a.addEventListener('mouseenter', show);
        a.addEventListener('focus', show);
      });
    }
  }

  /* full-screen menu sheet (tablet + phone) */
  $$('[data-sheet-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dlg = d.getElementById(b.getAttribute('data-sheet-open'));
      if (!dlg) return;
      loadLazy(dlg);
      if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
      root.style.overflow = 'hidden';
    });
  });
  $$('dialog.sheet').forEach(function (dlg) {
    var done = function () { root.style.overflow = ''; };
    dlg.addEventListener('close', done);
    $$('[data-sheet-close]', dlg).forEach(function (x) { x.addEventListener('click', function () { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); done(); }); });
    $$('a', dlg).forEach(function (a) { a.addEventListener('click', function () { if (dlg.close) dlg.close(); done(); }); });
  });

  /* entrances */
  var io = 'IntersectionObserver' in w ? new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      var el = en.target;
      el.classList.add('in');
      if (el.classList.contains('stagger')) setTimeout(function () { el.classList.add('done'); }, 1500);
      io.unobserve(el);
    });
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0.06 }) : null;
  $$('.reveal, .stagger').forEach(function (el) { if (io) io.observe(el); else el.classList.add('in'); });

  /* scroll-drawn lines (blueprint dimension lines, process line) */
  var draws = $$('[data-draw]');
  var drawTick = false;
  function drawUpdate() {
    drawTick = false;
    var vh = w.innerHeight;
    draws.forEach(function (el) {
      var r = el.getBoundingClientRect();
      var p = reduce.matches ? 1 : Math.max(0, Math.min(1, (vh * 0.95 - r.top) / (vh * 0.5)));
      var v = Math.round(p * 100) / 100;
      if (el._d !== v) { el._d = v; el.style.setProperty('--d', v); }
    });
  }
  if (draws.length) {
    w.addEventListener('scroll', function () { if (!drawTick) { drawTick = true; requestAnimationFrame(drawUpdate); } }, { passive: true });
    w.addEventListener('resize', drawUpdate);
    drawUpdate();
  }

  /* signature: the brass plumb line. It lengthens with the page and swings when you scroll fast,
     then settles back to true. Rests (no rAF) once settled. */
  var plumb = $('.plumb');
  if (plumb) {
    var ang = 0, vel = 0, lastSY = w.scrollY, pRaf = null, pLast = 0, lenCache = -1;
    var pTick = function (t) {
      var dt = Math.min(48, t - (pLast || t)); pLast = t;
      var k = dt / 16.667;
      vel += (-ang * 0.06 - vel * 0.11) * k;      /* damped spring toward true vertical */
      ang += vel * k;
      plumb.style.setProperty('--ang', ang.toFixed(2) + 'deg');
      if (Math.abs(ang) < 0.02 && Math.abs(vel) < 0.02) { ang = 0; vel = 0; plumb.style.setProperty('--ang', '0deg'); pRaf = null; pLast = 0; return; }
      pRaf = requestAnimationFrame(pTick);
    };
    var onPlumb = function () {
      var y = w.scrollY, dy = y - lastSY; lastSY = y;
      var max = Math.max(1, d.documentElement.scrollHeight - w.innerHeight);
      var len = Math.round((0.12 + 0.7 * (y / max)) * 1000) / 1000;
      if (len !== lenCache) { lenCache = len; plumb.style.setProperty('--len', len); }
      if (reduce.matches) return;
      vel += Math.max(-2.2, Math.min(2.2, -dy * 0.018));
      if (pRaf === null) pRaf = requestAnimationFrame(pTick);
    };
    w.addEventListener('scroll', onPlumb, { passive: true });
    onPlumb();
  }

  /* the one interactive moment: hold to set it plumb */
  var hold = $('[data-hold]');
  if (hold) {
    var list = $$('.pl li'), label = $('.hold__label', hold), btn = $('.hold__btn', hold);
    var hp = 0, holding = false, hRaf = null, hLast = 0, doneFlag = false, swingT = 0;
    var paint = function () {
      hold.style.setProperty('--p', hp.toFixed(3));
      /* the bob swings wide at rest and settles as progress builds */
      var amp = (1 - hp) * 14;
      hold.style.setProperty('--sw', (Math.sin(swingT) * amp).toFixed(2));
      var lit = Math.floor(hp * 4 + 0.001);
      list.forEach(function (li, i) { var on = i < lit || doneFlag; if (li._on !== on) { li._on = on; li.classList.toggle('is-lit', on); } });
    };
    var finish = function () {
      hp = 1; doneFlag = true; hold.classList.add('is-done');
      if (label) label.textContent = 'Plumb. Every promise, in writing.';
      if (btn) btn.setAttribute('aria-pressed', 'true');
      paint();
    };
    var loop = function (t) {
      var dt = Math.min(64, t - (hLast || t)); hLast = t;
      swingT += dt / 420;
      if (holding) hp = Math.min(1, hp + dt / 2400);
      else if (!doneFlag) hp = Math.max(0, hp - dt / 1600);
      if (hp >= 1 && !doneFlag) finish();
      paint();
      if (!doneFlag && hold._vis) hRaf = requestAnimationFrame(loop); else { hRaf = null; hLast = 0; }
    };
    var kick = function () { if (!hRaf && !doneFlag) hRaf = requestAnimationFrame(loop); };
    var start = function (e) { if (doneFlag) return; if (e && e.cancelable) e.preventDefault(); holding = true; kick(); };
    var stop = function () { holding = false; kick(); };
    hold.addEventListener('pointerdown', start);
    hold.addEventListener('pointerup', stop);
    hold.addEventListener('pointerleave', stop);
    hold.addEventListener('pointercancel', stop);
    hold.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    if (btn) {
      btn.addEventListener('keydown', function (e) { if ((e.key === ' ' || e.key === 'Enter') && !e.repeat) start(e); });
      btn.addEventListener('keyup', function (e) { if (e.key === ' ' || e.key === 'Enter') stop(); });
    }
    /* swing only while on screen */
    if ('IntersectionObserver' in w) {
      new IntersectionObserver(function (en) { hold._vis = en[0].isIntersecting; if (hold._vis) kick(); }, { threshold: 0.05 }).observe(hold);
    } else { hold._vis = true; kick(); }
    if (reduce.matches) finish();
    if (reduce.addEventListener) reduce.addEventListener('change', function (e) { if (e.matches) finish(); });
    paint();
  }

  /* project filters */
  $$('[data-filter-group]').forEach(function (group) {
    var target = d.getElementById(group.getAttribute('data-filter-group'));
    if (!target) return;
    var btns = $$('[data-filter]', group);
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        var f = b.getAttribute('data-filter');
        btns.forEach(function (x) { var on = x === b; x.setAttribute('aria-pressed', on ? 'true' : 'false'); x.classList.toggle('is-on', on); });
        $$('[data-tags]', target).forEach(function (card) {
          var show = f === 'all' || (' ' + card.getAttribute('data-tags') + ' ').indexOf(' ' + f + ' ') > -1;
          card.classList.toggle('is-hidden', !show);
        });
      });
    });
  });

  /* forms: validate here, submit with fetch, show the real result */
  $$('form[data-ajax]').forEach(function (form) {
    var wrap = form.closest('.form-wrap');
    var svc = $('select[name="service"]', form);
    var q = new URLSearchParams(w.location.search);
    if (svc && q.get('service')) svc.value = q.get('service');
    var check = function (field) {
      var input = $('input,select,textarea', field); if (!input) return true;
      var ok = input.checkValidity();
      field.classList.toggle('is-bad', !ok);
      var err = $('.err', field);
      if (err) err.textContent = ok ? '' : (input.validity.valueMissing ? 'Please fill this in.' : input.type === 'email' ? 'Please enter a valid email.' : input.type === 'tel' ? 'Please enter a phone number we can call.' : 'Please check this field.');
      return ok;
    };
    $$('.field', form).forEach(function (f) { var i = $('input,select,textarea', f); if (i) i.addEventListener('blur', function () { if (i.value) check(f); }); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ok = true, first = null;
      $$('.field', form).forEach(function (f) { if (!check(f)) { ok = false; if (!first) first = f; } });
      if (!ok) { var fi = $('input,select,textarea', first); if (fi) fi.focus(); return; }
      var sb = $('button[type="submit"]', form), txt = sb.innerHTML;
      sb.disabled = true; sb.textContent = 'Sending...';
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok) { wrap.classList.add('is-sent'); wrap.scrollIntoView({ block: 'center', behavior: reduce.matches ? 'auto' : 'smooth' }); var h = $('.sent h3', wrap); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); } }
          else { sb.disabled = false; sb.innerHTML = txt; w.alert((res && res.error) || 'Something went wrong. Please call us.'); }
        })
        .catch(function () { sb.disabled = false; sb.innerHTML = txt; w.alert('Could not send right now. Please call or email us.'); });
    });
  });

  /* pause looping decoration on hidden tabs */
  d.addEventListener('visibilitychange', function () { body.classList.toggle('paused', d.hidden); });
})();
