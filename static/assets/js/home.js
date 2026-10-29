/* RIELBUILD home: the scroll-scrubbed film. Plain JS.
   Streamed Blob fetch + ring, dt-normalized lerp, gated seeks, delta-gated writes,
   caption bands paced in scroll distance, portrait cut for phones, live reduced-motion gate. */
(function () {
  'use strict';
  var d = document, w = window, body = d.body;
  var film = d.getElementById('film');
  if (!film) return;
  var stage = film.querySelector('.film__stage');
  var video = film.querySelector('.film__video');
  var posterLayer = film.querySelector('.film__poster');
  var ring = film.querySelector('.film__ring');
  var cue = film.querySelector('.film__cue');
  var chip = film.querySelector('.film__progress span');
  var VIDEO_URL = film.getAttribute('data-video');
  var VIDEO_BYTES = +film.getAttribute('data-bytes') || 12000000;
  var POSTER_URL = film.getAttribute('data-poster');
  var bands = Array.prototype.slice.call(film.querySelectorAll('.band'));
  var chapters = [[0, '01 / Outside'], [0.44, '02 / Kitchen'], [0.74, '03 / Downstairs']];

  var clamp = function (v, lo, hi) { return Math.min(hi, Math.max(lo, v)); };
  var smooth = function (p, e0, e1) { var t = clamp((p - e0) / (e1 - e0), 0, 1); return t * t * (3 - 2 * t); };
  function rng(seed) { var s = seed >>> 0; return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }

  /* ---- split headlines once, seeded so every load is identical ---- */
  bands.forEach(function (b, bi) {
    b.a = +b.getAttribute('data-a'); b.b = +b.getAttribute('data-b');
    b.ramp = +b.getAttribute('data-ramp') || 0;
    b.op = -1; b.k = -1;
    var fx = b.getAttribute('data-fx');
    var r = rng(97 + bi * 31);
    Array.prototype.slice.call(b.querySelectorAll('[data-split]')).forEach(function (el) {
      var text = el.textContent.trim();
      var sr = d.createElement('span'); sr.className = 'sr'; sr.textContent = text;
      var vis = d.createElement('span'); vis.setAttribute('aria-hidden', 'true');
      var words = text.split(/\s+/), total = text.replace(/\s+/g, '').length, ci = 0;
      words.forEach(function (word, wi) {
        var ws = d.createElement('span'); ws.className = 'w';
        ws.style.setProperty('--th', (wi / Math.max(1, words.length) * 0.5 + r() * 0.04).toFixed(3));
        if (fx === 'snap') {
          word.split('').forEach(function (ch) {
            var cs = d.createElement('span'); cs.className = 'c'; cs.textContent = ch;
            cs.style.setProperty('--th', (ci / total * 0.55 + r() * 0.06).toFixed(3));
            cs.style.setProperty('--jx', ((r() * 30 + 18) * -1).toFixed(1) + 'px');
            ci++; ws.appendChild(cs);
          });
        } else { ws.textContent = word; }
        vis.appendChild(ws);
        if (wi < words.length - 1) vis.appendChild(d.createTextNode(' '));
      });
      el.textContent = ''; el.appendChild(sr); el.appendChild(vis);
    });
  });

  /* ---- progress through the pinned film ---- */
  var filmTop = 0, filmRange = 1;
  function measure() {
    var r = film.getBoundingClientRect();
    filmTop = r.top + w.scrollY;
    filmRange = Math.max(1, film.offsetHeight - w.innerHeight);
  }
  function heroProgress() { return clamp((w.scrollY - filmTop) / filmRange, 0, 1); }

  /* ---- caption bands ---- */
  var loadK = 0, loadStart = 0;
  var lastChip = '', lastChipAt = 0, cueHidden = false, pastFilm = null;
  function updateCaptions(p, now) {
    var n = bands.length;
    bands.forEach(function (b, i) {
      var a = b.a, bb = b.b, f = Math.min(0.02, (bb - a) / 3);
      var inO = i === 0 ? 1 : smooth(p, a, a + f);
      var outO = i === n - 1 ? 1 : 1 - smooth(p, bb - f, bb);
      var op = (p < a && i !== 0) || (p > bb && i !== n - 1) ? 0 : inO * outO;
      var ramp = b.ramp || Math.min(0.025, (bb - a) * 0.35);
      var k = clamp((p - a) / ramp, 0, 1);
      if (i === 0) k = Math.max(k, loadK);
      op = Math.round(op * 1000) / 1000;
      if (Math.abs(op - b.op) > 0.002 || (op === 0 && b.op !== 0) || (op === 1 && b.op !== 1)) { b.op = op; b.style.opacity = op; b.style.visibility = op <= 0 ? 'hidden' : 'visible'; }
      if (Math.abs(k - b.k) > 0.008 || (k === 1 && b.k !== 1) || (k === 0 && b.k !== 0)) { b.k = k; b.style.setProperty('--k', k.toFixed(3)); }
    });
    /* chapter chip at ~10Hz, only on change */
    if (chip && now - lastChipAt > 100) {
      var label = chapters[0][1];
      chapters.forEach(function (c) { if (p >= c[0]) label = c[1]; });
      if (label !== lastChip) { lastChip = label; chip.textContent = label; }
      lastChipAt = now;
    }
    var hideCue = p > 0.02;
    if (cue && hideCue !== cueHidden) { cueHidden = hideCue; cue.style.opacity = hideCue ? '0' : '1'; }
  }
  function setPastFilm() {
    var past = w.scrollY > filmTop + filmRange + w.innerHeight * 0.3;
    if (past !== pastFilm) { pastFilm = past; body.classList.toggle('past-film', past); }
  }

  /* ---- gated seeks (deadlock-safe) ---- */
  var seekBusy = false, pendingTime = null;
  function requestSeek(t) {
    if (!video.duration || !isFinite(video.duration)) return;
    t = clamp(t, 0, video.duration - 0.04);
    if (seekBusy) { pendingTime = t; return; }
    if (Math.abs(video.currentTime - t) < 0.01) return;
    seekBusy = true;
    video.currentTime = t;
  }
  video.addEventListener('seeked', function () {
    seekBusy = false;
    if (pendingTime !== null) { var t = pendingTime; pendingTime = null; requestSeek(t); }
  });
  video.addEventListener('error', function () { seekBusy = false; pendingTime = null; failVideo(); });

  /* ---- the lerp loop that rests ---- */
  var target = 0, shown = 0, rafId = null, lastTick = 0, heroOnScreen = true;
  function tick(now) {
    var dt = Math.min(100, now - (lastTick || now));
    lastTick = now;
    if (loadK < 1) { loadK = clamp((now - loadStart) / 1300, 0, 1); loadK = 1 - Math.pow(1 - loadK, 3); }
    shown += (target - shown) * (1 - Math.pow(1 - 0.14, dt / 16.667));
    var converged = Math.abs(target - shown) < 0.0005;
    if (converged) shown = target;
    requestSeek(shown * (video.duration || 0));
    updateCaptions(shown, now);
    if (converged && loadK >= 1) { rafId = null; lastTick = 0; }
    else rafId = requestAnimationFrame(tick);
  }
  function kick() { if (rafId === null && heroOnScreen) rafId = requestAnimationFrame(tick); }
  function onScroll() { target = heroProgress(); setPastFilm(); kick(); }
  function onResize() { measure(); onScroll(); }
  if ('IntersectionObserver' in w) {
    new IntersectionObserver(function (en) { heroOnScreen = en[0].isIntersecting; if (heroOnScreen) kick(); }, { threshold: 0 }).observe(film);
  }

  /* ---- streamed Blob with honest ring. Two cuts of the same film:
         'd' widescreen for landscape screens, 'm' portrait crop for phones and portrait tablets ---- */
  var SRC = {
    d: { url: VIDEO_URL, bytes: VIDEO_BYTES, poster: POSTER_URL },
    m: { url: film.getAttribute('data-video-m') || VIDEO_URL, bytes: +film.getAttribute('data-bytes-m') || VIDEO_BYTES, poster: film.getAttribute('data-poster-m') || POSTER_URL }
  };
  var PORTRAIT = w.matchMedia('(orientation: portrait)');
  function wantVariant() { return (PORTRAIT.matches || w.innerWidth <= 720) ? 'm' : 'd'; }
  var blobs = {}, loading = {}, current = null, posterFor = null;

  function initHero() {
    var v = wantVariant();
    if (v === current) return;
    if (blobs[v]) { useVariant(v); return; }
    if (loading[v]) return;
    loading[v] = true;
    var poster = SRC[v].poster;
    if (!current && posterFor !== v) { posterFor = v; posterLayer.style.backgroundImage = "url('" + poster + "')"; }
    var go = false;
    var start = function () { if (go) return; go = true; loadBlob(v).catch(function () { loading[v] = false; if (!current) failVideo(); }); };
    var img = new Image();
    img.onload = start; img.onerror = start; img.src = poster;
    setTimeout(start, 4000);
  }
  function setRing(frac) { if (ring) ring.style.setProperty('--ld', Math.round(126 * (1 - frac))); }
  function loadBlob(v) {
    if (!w.fetch || !w.ReadableStream || location.protocol === 'file:') return Promise.reject(new Error('no fetch'));
    var ctrl = w.AbortController ? new AbortController() : null;
    var watchdog = setTimeout(function () { if (ctrl) ctrl.abort(); }, 20000);
    var opts = ctrl ? { signal: ctrl.signal } : {};
    try { opts.priority = 'low'; } catch (e) { /* older browsers */ }
    if (!current) setRing(0);
    return fetch(SRC[v].url, opts).then(function (res) {
      if (!res.ok || !res.body) throw new Error('bad response');
      var total = Number(res.headers.get('Content-Length')) || SRC[v].bytes;
      var reader = res.body.getReader(), chunks = [], got = 0, lastRing = 0;
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) return;
          clearTimeout(watchdog);
          watchdog = setTimeout(function () { if (ctrl) ctrl.abort(); }, 20000);
          chunks.push(r.value); got += r.value.length;
          var frac = Math.min(1, got / total), now = performance.now();
          if (!current && (now - lastRing > 100 || frac === 1)) { lastRing = now; setRing(frac); }
          return pump();
        });
      }
      return pump().then(function () {
        clearTimeout(watchdog);
        if (!current) setRing(1);
        blobs[v] = URL.createObjectURL(new Blob(chunks, { type: 'video/mp4' }));
        loading[v] = false;
        if (wantVariant() === v || !current) useVariant(v);
      });
    });
  }
  function useVariant(v) {
    current = v;
    seekBusy = false; pendingTime = null;
    video.preload = 'auto';
    video.muted = true;
    video.src = blobs[v];
    video.load();
    video.addEventListener('loadeddata', function () {
      measure();
      /* iOS Safari only paints seeked frames once the element has played:
         a muted inline play-then-pause primes the decoder (allowed without a tap). */
      var pr = video.play();
      var settle = function () { video.pause(); requestSeek(heroProgress() * video.duration); film.classList.add('video-ready'); kick(); };
      if (pr && pr.then) pr.then(settle).catch(settle); else settle();
    }, { once: true });
  }
  /* belt and braces for iOS: prime again on the first touch */
  d.addEventListener('touchstart', function () {
    if (!video.src) return;
    var pr = video.play();
    if (pr && pr.then) pr.then(function () { video.pause(); requestSeek(shown * video.duration); }).catch(function () {});
  }, { passive: true, once: true });
  function failVideo() {
    film.classList.add('video-failed');
    if (ring) ring.style.display = 'none';
  }

  /* ---- the static-hero gate. The film now plays on phones too; the still hero is kept for
         reduced motion (same query as the CSS, decided live) and for Data Saver visitors. ---- */
  var GATES = ['(prefers-reduced-motion: reduce)'];
  var MQLS = GATES.map(function (q) { return w.matchMedia(q); });
  var saveData = !!(navigator.connection && navigator.connection.saveData);
  var scrubOn = false;
  function enableScrub() {
    if (scrubOn) return; scrubOn = true;
    film.classList.remove('is-static');
    initHero();
    measure();
    w.addEventListener('scroll', onScroll, { passive: true });
    w.addEventListener('resize', onResize);
    bands.forEach(function (b) { b.op = -1; b.k = -1; });
    loadStart = performance.now();
    target = shown = heroProgress();
    updateCaptions(shown, performance.now());
    onScroll();
  }
  function disableScrub() {
    film.classList.add('is-static');
    if (!scrubOn) return;
    scrubOn = false;
    w.removeEventListener('scroll', onScroll);
    w.removeEventListener('resize', onResize);
    if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
  }
  function applyHeroMode() {
    if (saveData || MQLS.some(function (m) { return m.matches; })) disableScrub(); else enableScrub();
  }
  MQLS.forEach(function (m) {
    if (m.addEventListener) m.addEventListener('change', applyHeroMode); else if (m.addListener) m.addListener(applyHeroMode);
  });
  /* rotating a phone or tablet swaps to the matching cut, keeping the scroll position */
  var onOrient = function () { if (scrubOn) { measure(); initHero(); } };
  if (PORTRAIT.addEventListener) PORTRAIT.addEventListener('change', onOrient); else if (PORTRAIT.addListener) PORTRAIT.addListener(onOrient);
  /* past-film flag still matters for the plumb line in static mode */
  w.addEventListener('scroll', function () { if (!scrubOn) { measure(); setPastFilm(); } }, { passive: true });
  applyHeroMode();
})();
