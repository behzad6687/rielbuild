<?php
require dirname(__DIR__) . '/inc/boot.php';
$page = array(
    'title'  => 'About RIELBUILD | Honest Renovation Contractor in Toronto & the GTA',
    'desc'   => 'RIELBUILD is a GTA renovation and construction company built on honesty: fixed written quotes, one project lead, payments tied to finished work.',
    'path'   => 'about/',
    'crumbs' => array(array('About', 'about/')),
    'schema' => array(array('@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About RIELBUILD', 'url' => abs_url('about/'), 'about' => array('@id' => abs_url('') . '#business'))),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
?>
<main id="main" tabindex="-1">
<?= page_hero('About us', 'A builder that <span class="brass">works plumb.</span>', 'Plumb is a builder\'s word for dead straight. It is how we frame a wall, and it is how we deal with people.', 'stills/design-build', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap split">
    <div class="prose reveal">
      <p class="kicker">Who we are</p>
      <h2 class="h2">Honesty is not a slogan here. It is the system.</h2>
      <?= dimline() ?>
      <p class="lede">RIELBUILD is a renovation and construction company serving Toronto and the Greater Toronto Area. We do full and partial home renovations, kitchens, bathrooms, basements, design-build projects and custom homes.</p>
      <p>We take honesty and integrity seriously, so we built them into how every job runs. You get a fixed, written quote. You pay as each stage is finished. You have one project lead with a direct number. And the site is cleaned up at the end of every day, because it is still your home.</p>
      <p>Every home is different. That is why we give every client a plan built for their house, their family and their budget, and then we stick to it.</p>
    </div>
    <div class="split__media reveal"><?= pic('stills/step-design', 'Plans, material samples and a level on a white oak work table', 1200, 990) ?><span class="split__tag">Measure twice. Quote once.</span></div>
  </div>
</section>

<section class="sec on-dark">
  <div class="wrap">
    <div class="sec__head reveal">
      <p class="kicker">What we stand for</p>
      <h2 class="h2" style="margin-top:16px">Three things we will not bend on.</h2>
      <?= dimline() ?>
    </div>
    <div class="cards stagger">
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-level"/></svg></span><h3 class="h3">Straight talk</h3><p>If something will cost more, take longer or not work, we tell you before it happens. No surprises in the final bill.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-frame"/></svg></span><h3 class="h3">Built true</h3><p>Proper waterproofing, proper framing, proper permits. The parts you never see are built as carefully as the parts you do.</p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-house"/></svg></span><h3 class="h3">Respect for your home</h3><p>Floors covered, dust sealed off, and a tidy site every evening. We work like guests, because we are.</p></div>
    </div>
    <div class="stat-row stagger">
      <div class="stat"><b>6</b><span>Services under one roof</span></div>
      <div class="stat"><b>1</b><span>Project lead per job</span></div>
      <div class="stat"><b>16+</b><span>GTA communities served</span></div>
      <div class="stat"><b>$0</b><span>For your first consultation</span></div>
    </div>
  </div>
</section>

<?= cta_band('stills/kitchen') ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
