<?php
require dirname(__DIR__) . '/inc/boot.php';
$page = array(
    'title'  => 'How We Work: Our Renovation Process & Promise | RIELBUILD',
    'desc'   => 'How RIELBUILD runs a renovation: a free visit, a fixed written quote, one project lead, payments tied to finished work and a clean site every day.',
    'path'   => 'process/',
    'crumbs' => array(array('How we work', 'process/')),
    'schema' => array(array(
        '@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => 'How a RIELBUILD renovation works',
        'step' => array_map(function ($st) { return array('@type' => 'HowToStep', 'name' => $st[1], 'text' => $st[2]); }, $PROCESS),
    )),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
?>
<main id="main" tabindex="-1">
<?= page_hero('How we work', 'Straight lines. <span class="brass">Straight talk.</span>', 'The part of a renovation people dread is not the dust. It is not knowing what is happening, what it costs, or who to call. So we built our whole process around that.', 'stills/step-build', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap">
    <div class="sec__head reveal">
      <p class="kicker">Four steps</p>
      <h2 class="h2" style="margin-top:16px">From first call to final walkthrough.</h2>
      <?= dimline() ?>
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
      </div>
      <ol class="pl" style="margin-top:30px"><?php foreach ($PROMISES as $pr): ?><li><b><?= e($pr[0]) ?></b><span><?= e($pr[1]) ?></span></li><?php endforeach; ?></ol>
    </div>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <p class="kicker">What changes for you</p>
        <h2 class="h2" style="margin-top:16px">The worries we take off your plate.</h2>
        <?= dimline() ?>
      </div>
      <p class="lede reveal">We hear the same fears at almost every first visit. Here is how we answer each one.</p>
    </div>
    <div class="cards stagger">
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-doc"/></svg></span><h3 class="h3">"What if it goes over budget?"</h3><p>You get a fixed, written quote. Any change is priced and agreed in writing before it happens, never after.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg></span><h3 class="h3">"What if they disappear?"</h3><p>You pay as each stage is finished, so we are never ahead of the work. And your project lead picks up the phone.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-clock"/></svg></span><h3 class="h3">"How long will we live in a mess?"</h3><p>You get a written timeline up front. The site is sealed, covered and cleaned at the end of every working day.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-shield"/></svg></span><h3 class="h3">"Who fixes it if something's wrong?"</h3><p>We walk the finished space with you, fix everything on the list, and back our work with a written warranty.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-house"/></svg></span><h3 class="h3">"Can we live at home during the work?"</h3><p>For one room or a bathroom, usually yes. We plan the order of work around your family and seal off the work area.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-house"/></svg></span><h3 class="h3">"What about permits?"</h3><p>We prepare the drawings, apply for the permits and book every inspection. You never deal with the city.</p></div>
    </div>
  </div>
</section>

<?= cta_band('stills/step-walk') ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
