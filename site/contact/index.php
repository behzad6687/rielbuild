<?php
require dirname(__DIR__) . '/inc/boot.php';
$page = array(
    'title'  => 'Contact RIELBUILD | Book a Free Renovation Consultation in the GTA',
    'desc'   => 'Book a free in-home consultation with RIELBUILD. Call 647-895-4555 or send the form. A project lead calls back within one business day.',
    'path'   => 'contact/',
    'crumbs' => array(array('Contact', 'contact/')),
    'schema' => array(array('@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact RIELBUILD', 'url' => abs_url('contact/'))),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
$sentFlag = isset($_GET['sent']);
?>
<main id="main" tabindex="-1">
<?= page_hero('Contact', 'Let\'s talk about <span class="brass">your home.</span>', 'Book a free consultation. We visit, listen, measure and give you an honest price range before we leave.', 'stills/step-talk', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap contact-grid">
    <div class="reveal">
      <p class="kicker">Talk to a project lead</p>
      <h2 class="h2" style="margin-top:16px">Straight to the person who runs the job.</h2>
      <?= dimline() ?>
      <div class="contact-list">
        <a href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><span><b><?= e(PHONE) ?></b><small>Mon to Sat, 8am to 6pm</small></span></a>
        <a href="mailto:<?= e(EMAIL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-mail"/></svg><span><b><?= e(EMAIL) ?></b><small>We reply within one business day</small></span></a>
        <div><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-pin"/></svg><span><b>Toronto and the GTA</b><small>We come to you</small></span></div>
      </div>
      <div class="note" style="margin-top:24px"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-level"/></svg><p><b>What happens next.</b> We call to book a time, visit your home, and give you an honest range on the spot. A fixed written quote follows once the plan is set.</p></div>
    </div>
    <div class="reveal">
      <?php if ($sentFlag): ?>
      <div class="form-wrap is-sent"><div class="sent" role="status"><span class="sent__mark"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-check"/></svg></span><h3 class="h3">Thanks. We've got it.</h3><p>A project lead will call you within one business day.</p></div></div>
      <?php else: echo consult_form('contact-form'); endif; ?>
    </div>
  </div>
</section>

<section class="sec sec--tight on-dark">
  <div class="wrap areas-band">
    <div class="reveal">
      <p class="kicker">Where we work</p>
      <h2 class="h2" style="margin-top:16px">Toronto and the whole GTA.</h2>
      <?= dimline() ?>
    </div>
    <ul class="areas stagger"><?php foreach ($AREAS as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
  </div>
</section>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
