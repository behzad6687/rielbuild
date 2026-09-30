<?php
require __DIR__ . '/inc/boot.php';
$faqHome = array_slice($FAQ, 0, 6);
$page = array(
    'title' => 'RIELBUILD | Home Renovation & Construction Contractor in Toronto & the GTA',
    'desc'  => 'Kitchen, bathroom and basement renovations, full home remodels and custom builds across the GTA. Fixed written quotes, one project lead, payments tied to finished work.',
    'path'  => '',
    'body'  => 'page-home',
    'scripts' => array('js/home.js'),
    'preload' => '<link rel="preload" as="image" href="' . e(url('assets/img/hero-poster.jpg')) . '" media="(orientation: landscape) and (min-width: 721px) and (prefers-reduced-motion: no-preference)">'
        . '<link rel="preload" as="image" href="' . e(url('assets/img/hero-poster-m.jpg')) . '" media="((orientation: portrait) or (max-width: 720px)) and (prefers-reduced-motion: no-preference)">',
    'schema' => array(
        array('@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => SITE_NAME, 'url' => abs_url('')),
        array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($q) {
            return array('@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $q[1]));
        }, $faqHome)),
    ),
);
include __DIR__ . '/inc/header.php';
echo plumb_line();
?>
<main id="main" tabindex="-1">
<h1 class="sr">RIELBUILD: home renovation and construction contractor in Toronto and the GTA</h1>

<!-- The scroll film: street, window, kitchen, stairs, basement -->
<section class="film" id="film" aria-label="Your home, built plumb"
  data-video="<?= e(url('assets/video/hero-scrub.mp4')) ?>"
  data-bytes="<?= (int) @filesize(__DIR__ . '/assets/video/hero-scrub.mp4') ?>"
  data-poster="<?= e(url('assets/img/hero-poster.jpg')) ?>"
  data-video-m="<?= e(url('assets/video/hero-scrub-m.mp4')) ?>"
  data-bytes-m="<?= (int) @filesize(__DIR__ . '/assets/video/hero-scrub-m.mp4') ?>"
  data-poster-m="<?= e(url('assets/img/hero-poster-m.jpg')) ?>">
  <div class="film__stage">
    <div class="film__poster" style="--static-img:url('<?= e(url('assets/img/hero-static.jpg')) ?>')" aria-hidden="true"></div>
    <video class="film__video" preload="none" muted playsinline disablepictureinpicture aria-hidden="true" tabindex="-1"></video>
    <div class="film__scrim" aria-hidden="true"></div>

    <div class="film__bands">
      <div class="band band--l fx-rise" data-a="0" data-b="0.14" data-fx="rise">
        <div class="band__in">
          <p class="kicker">Renovation &amp; construction, GTA</p>
          <p class="band__h" data-split>Your home, built plumb.</p>
          <p class="band__p band__sub">Renovations and custom builds across the GTA. Straight lines, straight talk.</p>
        </div>
      </div>
      <div class="band band--l fx-depth" data-a="0.18" data-b="0.36" data-fx="depth" style="--sa:.84">
        <div class="band__in">
          <p class="kicker">The first promise</p>
          <h2 class="band__h" data-split>No vanishing after the deposit.</h2>
          <p class="band__p band__sub">A written, fixed quote. Payments tied to finished work.</p>
        </div>
      </div>
      <div class="band band--l fx-snap" data-a="0.47" data-b="0.63" data-fx="snap" style="--sa:.86" data-ramp="0.034">
        <div class="band__in">
          <p class="kicker">Kitchens</p>
          <h2 class="band__h" data-split>Kitchens you'll want to cook in.</h2>
          <p class="band__p band__sub">Cabinets, counters, plumbing, lighting. One crew handles all of it.</p>
        </div>
      </div>
      <div class="band band--r fx-blur" data-a="0.67" data-b="0.82" data-fx="blur">
        <div class="band__in">
          <p class="kicker">Built true</p>
          <h2 class="band__h" style="position:relative"><span class="band__sharp">We build what's behind the walls.</span><span class="band__soft" aria-hidden="true">We build what's behind the walls.</span></h2>
          <p class="band__p band__sub">Framing, plumbing, insulation. The parts you never see, done right.</p>
        </div>
      </div>
      <div class="band band--c fx-rise" data-a="0.87" data-b="1" data-fx="rise" style="--sa:.94">
        <div class="band__in">
          <h2 class="band__h" data-split>Let's build it right.</h2>
          <p class="band__p band__sub">Free consultation. A clear, written quote.</p>
          <div class="btn-row band__cta">
            <a class="btn btn--brass" href="<?= e(url('contact/')) ?>">Book a free consultation <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a>
            <a class="btn btn--ghost" href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><?= e(PHONE) ?></a>
          </div>
        </div>
      </div>
    </div>

    <svg class="film__ring" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="20" fill="none" stroke-width="2.5"/><circle cx="24" cy="24" r="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="126" style="stroke-dashoffset:var(--ld,126);transform:rotate(-90deg);transform-origin:center"/></svg>
    <div class="film__cue" aria-hidden="true">Scroll<i></i></div>
    <div class="film__progress" aria-hidden="true"><span>01 / Outside</span></div>

    <!-- static hero for phones, portrait tablets and reduced motion -->
    <div class="film__static">
      <p class="kicker">Renovation &amp; construction, GTA</p>
      <p class="h1" style="margin-top:14px">Your home, built plumb.</p>
      <p class="lede">Renovations, basements, kitchens and custom builds across the GTA. Straight lines, straight talk.</p>
      <div class="btn-row">
        <a class="btn btn--brass" href="<?= e(url('contact/')) ?>">Book a free consultation</a>
        <a class="btn btn--ghost" href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg>Call <?= e(PHONE) ?></a>
      </div>
    </div>
  </div>
</section>

<!-- What we build -->
<section class="sec" id="services">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <p class="kicker">What we build</p>
        <h2 class="h2" style="margin-top:16px">Six ways we build. One way of working.</h2>
        <?= dimline() ?>
      </div>
      <p class="lede reveal">From a single bathroom to a new custom home, every job gets the same fixed written quote, the same project lead and the same clean site.</p>
    </div>
    <div class="svc-grid stagger">
      <?php foreach ($SERVICES as $slug => $s): ?>
      <a class="svc" href="<?= e(url('services/' . $slug . '/')) ?>">
        <div class="svc__media"><?= pic($s['img'], $s['name'] . ' by RIELBUILD', 800, 600, true, '(max-width: 600px) 100vw, (max-width: 980px) 50vw, 33vw') ?><span class="svc__icon"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-<?= e($s['icon']) ?>"/></svg></span></div>
        <div class="svc__body">
          <h3 class="h3"><?= e($s['name']) ?></h3>
          <p><?= e($s['tag']) ?></p>
          <span class="svc__go">See how we do it <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- The plumb promise: the one interactive moment -->
<section class="sec on-dark promise" id="promise">
  <div class="wrap promise__grid">
    <div class="reveal">
      <div class="hold" data-hold>
        <svg class="hold__ring" viewBox="0 0 100 100" aria-hidden="true"><circle class="bg" cx="50" cy="50" r="48"/><circle class="fg" cx="50" cy="50" r="48" pathLength="1"/></svg>
        <span class="hold__true" aria-hidden="true"></span>
        <span class="hold__plumb" aria-hidden="true"><svg class="hold__bob" viewBox="0 0 30 44"><path d="M15 0v10" stroke="currentColor" stroke-width="2"/><path d="M15 10c-6.4 0-11 5-11 10.2C4 25.3 15 44 15 44s11-18.7 11-23.8C26 15 21.4 10 15 10z" fill="currentColor"/></svg></span>
        <button class="hold__btn" type="button" aria-pressed="false" aria-label="Press and hold to settle the plumb bob and reveal our four promises"></button>
        <p class="hold__label" aria-live="polite">Press and hold to set it plumb</p>
      </div>
    </div>
    <div>
      <div class="reveal">
        <p class="kicker">The plumb promise</p>
        <h2 class="h2" style="margin-top:16px">Four things we put in writing.</h2>
        <?= dimline() ?>
        <p class="lede" style="margin-top:18px">A plumb line only settles when it is true. So do we. Hold the bob still and see what every client gets.</p>
      </div>
      <ol class="pl" style="margin-top:30px">
        <?php foreach ($PROMISES as $pr): ?><li><b><?= e($pr[0]) ?></b><span><?= e($pr[1]) ?></span></li><?php endforeach; ?>
      </ol>
    </div>
  </div>
</section>

<!-- How it works -->
<section class="sec" id="process">
  <div class="wrap">
    <div class="sec__head">
      <div class="reveal">
        <p class="kicker">How it works</p>
        <h2 class="h2" style="margin-top:16px">Four steps. No surprises.</h2>
        <?= dimline() ?>
      </div>
    </div>
    <div class="steps">
      <svg class="steps__line" viewBox="0 0 1000 2" preserveAspectRatio="none" aria-hidden="true" data-draw><path d="M0 1h1000" stroke="currentColor" stroke-width="2" pathLength="1" fill="none"/></svg>
      <?php foreach ($PROCESS as $st): ?>
      <div class="step reveal">
        <span class="step__n"><?= e($st[0]) ?></span>
        <div class="step__media"><?= pic($st[3], $st[1], 800, 600, true, '(max-width: 560px) 100vw, (max-width: 980px) 50vw, 25vw') ?></div>
        <h3 class="h3"><?= e($st[1]) ?></h3>
        <p><?= e($st[2]) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Recent work -->
<section class="sec sec--plaster2" id="work">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <p class="kicker">Recent work</p>
        <h2 class="h2" style="margin-top:16px">Rooms people actually live in.</h2>
        <?= dimline() ?>
      </div>
      <p class="lede reveal">Warm materials, honest details, clean lines. <a class="tlink" href="<?= e(url('projects/')) ?>">See all projects <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a></p>
    </div>
    <div class="gal gal--home stagger">
      <?php foreach (array_slice($PROJECTS, 0, 5) as $pj): ?>
      <figure class="gal__item"><?= pic($pj[0], $pj[1], 1200, 900) ?><figcaption><small><?= e($pj[2]) ?></small><b><?= e($pj[1]) ?></b></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Straight answers -->
<section class="sec" id="faq">
  <div class="wrap faq-split">
    <div class="sec__head reveal">
      <p class="kicker">Straight answers</p>
      <h2 class="h2">What homeowners ask us first.</h2>
      <?= dimline() ?>
      <p class="lede">The questions we hear at almost every first visit. <a class="tlink" href="<?= e(url('faq/')) ?>">All questions <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a></p>
    </div>
    <div class="faq stagger">
      <?php foreach ($faqHome as $i => $q): ?>
      <details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($q[0]) ?></summary><p class="faq__a"><?= e($q[1]) ?></p></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Where we work -->
<section class="sec sec--tight on-dark" id="areas">
  <div class="wrap areas-band">
    <div class="reveal">
      <p class="kicker">Where we work</p>
      <h2 class="h2" style="margin-top:16px">Toronto and the whole GTA.</h2>
      <?= dimline() ?>
      <p class="lede" style="margin-top:18px">Not on the list? Call us anyway. If we can do the job right, we will.</p>
    </div>
    <ul class="areas stagger">
      <?php foreach ($AREAS as $a): ?><li><?= e($a) ?></li><?php endforeach; ?>
    </ul>
  </div>
</section>

<?= cta_band() ?>

</main>
<?php include __DIR__ . '/inc/footer.php'; ?>
