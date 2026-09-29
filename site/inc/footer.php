<?php /* Shared footer, phone thumb bar, menu sheet and scripts. */ ?>
<footer class="foot">
  <div class="wrap">
    <div class="foot__top">
      <div class="foot__brand">
        <a class="brand brand--foot" href="<?= e(url('')) ?>" aria-label="RIELBUILD home">
          <svg class="brand__mark" width="40" height="40" aria-hidden="true" focusable="false"><use href="#i-mark"/></svg>
          <span class="brand__text"><b>RIEL<span>BUILD</span></b><small>Construction &amp; Renovation</small></span>
        </a>
        <p class="foot__line">Renovations and custom builds across the Greater Toronto Area. Straight lines, straight talk.</p>
        <a class="btn btn--brass" href="<?= e(url('contact/')) ?>">Book a free consultation</a>
      </div>
      <div class="foot__col">
        <p class="foot__h">Services</p>
        <?php foreach ($SERVICES as $mslug => $ms): ?><a href="<?= e(url('services/' . $mslug . '/')) ?>"><?= e($ms['name']) ?></a><?php endforeach; ?>
      </div>
      <div class="foot__col">
        <p class="foot__h">Company</p>
        <a href="<?= e(url('about/')) ?>">About us</a>
        <a href="<?= e(url('process/')) ?>">How we work</a>
        <a href="<?= e(url('projects/')) ?>">Projects</a>
        <a href="<?= e(url('areas/')) ?>">Service areas</a>
        <a href="<?= e(url('faq/')) ?>">FAQ</a>
        <a href="<?= e(url('contact/')) ?>">Contact</a>
      </div>
      <div class="foot__col foot__contact">
        <p class="foot__h">Talk to us</p>
        <a href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><?= e(PHONE) ?></a>
        <a href="mailto:<?= e(EMAIL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-mail"/></svg><?= e(EMAIL) ?></a>
        <span><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-pin"/></svg>Serving Toronto and the GTA</span>
        <span><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-clock"/></svg>Mon to Sat, 8am to 6pm</span>
      </div>
    </div>
    <div class="foot__plumb" aria-hidden="true"><span></span></div>
    <div class="foot__bottom">
      <p>&copy; <?= date('Y') ?> <?= e(SITE_LEGAL) ?>. All rights reserved.</p>
      <p class="foot__note">Project imagery on this site is illustrative.</p>
    </div>
  </div>
</footer>

<nav class="thumbbar" aria-label="Quick actions">
  <a class="thumbbar__item" href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><span>Call</span></a>
  <a class="thumbbar__item" href="<?= e(url('services/')) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-house"/></svg><span>Services</span></a>
  <a class="thumbbar__item thumbbar__item--cta" href="<?= e(url('contact/')) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-doc"/></svg><span>Free quote</span></a>
  <button class="thumbbar__item" type="button" data-sheet-open="menu-sheet" aria-haspopup="dialog"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-menu"/></svg><span>Menu</span></button>
</nav>

<dialog class="sheet" id="menu-sheet" aria-label="Menu">
  <div class="sheet__in">
    <div class="sheet__top">
      <a class="brand" href="<?= e(url('')) ?>"><svg class="brand__mark" width="36" height="36" aria-hidden="true" focusable="false"><use href="#i-mark"/></svg><span class="brand__text"><b>RIEL<span>BUILD</span></b><small>Construction &amp; Renovation</small></span></a>
      <button class="icon-btn sheet__x" type="button" data-sheet-close aria-label="Close menu"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-close"/></svg></button>
    </div>
    <div class="sheet__groups">
      <details class="sgroup" open>
        <summary><span class="sgroup__chip"><img data-src="<?= e(url('assets/img/stills/kitchen-sm.webp')) ?>" alt="" width="48" height="48"></span><span>Services</span><svg class="ico ico--chev" aria-hidden="true" focusable="false"><use href="#i-chev"/></svg></summary>
        <div class="sgroup__body">
          <?php foreach ($SERVICES as $mslug => $ms): ?><a href="<?= e(url('services/' . $mslug . '/')) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-<?= e($ms['icon']) ?>"/></svg><?= e($ms['name']) ?></a><?php endforeach; ?>
          <a class="sgroup__all" href="<?= e(url('services/')) ?>">All services <span aria-hidden="true">&rarr;</span></a>
        </div>
      </details>
      <details class="sgroup">
        <summary><span class="sgroup__chip"><img data-src="<?= e(url('assets/img/stills/design-build-sm.webp')) ?>" alt="" width="48" height="48"></span><span>About</span><svg class="ico ico--chev" aria-hidden="true" focusable="false"><use href="#i-chev"/></svg></summary>
        <div class="sgroup__body">
          <a href="<?= e(url('about/')) ?>">Who we are</a>
          <a href="<?= e(url('process/')) ?>#promise">The plumb promise</a>
          <a href="<?= e(url('areas/')) ?>">Where we work</a>
          <a href="<?= e(url('faq/')) ?>">Straight answers</a>
        </div>
      </details>
      <a class="sgroup sgroup--link" href="<?= e(url('process/')) ?>"><span class="sgroup__chip"><img data-src="<?= e(url('assets/img/stills/step-build-sm.webp')) ?>" alt="" width="48" height="48"></span><span>How we work</span><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a>
      <a class="sgroup sgroup--link" href="<?= e(url('projects/')) ?>"><span class="sgroup__chip"><img data-src="<?= e(url('assets/img/stills/basement-sm.webp')) ?>" alt="" width="48" height="48"></span><span>Projects</span><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a>
      <a class="sgroup sgroup--link" href="<?= e(url('contact/')) ?>"><span class="sgroup__chip sgroup__chip--ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-mail"/></svg></span><span>Contact</span><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a>
    </div>
    <div class="sheet__foot">
      <a class="btn btn--brass btn--block" href="<?= e(url('contact/')) ?>">Book a free consultation</a>
      <a class="btn btn--ghost btn--block" href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><?= e(PHONE) ?></a>
    </div>
  </div>
</dialog>

<script src="<?= e(asset('js/site.js')) ?>" defer></script>
<?php if (!empty($page['scripts'])) foreach ($page['scripts'] as $s): ?>
<script src="<?= e(asset($s)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
