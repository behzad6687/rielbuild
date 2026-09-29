<?php
require dirname(__DIR__) . '/inc/boot.php';
$page = array(
    'title'  => 'Renovation & Construction Services in Toronto & the GTA | RIELBUILD',
    'desc'   => 'Full home renovations, kitchens, bathrooms, basements, design-build and custom homes across the GTA. One team, one fixed written quote, one project lead.',
    'path'   => 'services/',
    'crumbs' => array(array('Services', 'services/')),
    'schema' => array(array('@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => array_values(array_map(function ($s, $k, $i) {
        return array('@type' => 'ListItem', 'position' => $i + 1, 'name' => $s['name'], 'url' => abs_url('services/' . $k . '/'));
    }, $SERVICES, array_keys($SERVICES), range(0, count($SERVICES) - 1))))),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
?>
<main id="main" tabindex="-1">
<?= page_hero('Services', 'Everything your home needs, <span class="brass">built plumb.</span>', 'Six services, one way of working: a fixed written quote, one project lead, payments tied to finished work and a clean site every day.', 'stills/home-renovation', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap">
    <div class="svc-grid stagger">
      <?php foreach ($SERVICES as $slug => $s): ?>
      <a class="svc" href="<?= e(url('services/' . $slug . '/')) ?>">
        <div class="svc__media"><?= pic($s['img'], $s['name'] . ' by RIELBUILD', 800, 600, true, '(max-width: 600px) 100vw, (max-width: 980px) 50vw, 33vw') ?><span class="svc__icon"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-<?= e($s['icon']) ?>"/></svg></span></div>
        <div class="svc__body">
          <h2 class="h3"><?= e($s['name']) ?></h2>
          <p><?= e($s['lead']) ?></p>
          <span class="svc__go">See the details <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec on-dark">
  <div class="wrap split">
    <div class="prose reveal">
      <p class="kicker">Not sure where to start?</p>
      <h2 class="h2">Tell us about the space. We'll tell you straight.</h2>
      <?= dimline() ?>
      <p class="lede">Some jobs are one service. Most touch two or three: a kitchen that opens into the dining room, a basement that needs a bathroom. On the first visit we walk the house with you and give you an honest price range before we leave.</p>
      <div class="btn-row" style="margin-top:10px"><a class="btn btn--brass" href="<?= e(url('contact/')) ?>">Book a free consultation</a><a class="tlink" href="<?= e(url('process/')) ?>">How we work <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></a></div>
    </div>
    <div class="split__media reveal"><?= pic('stills/step-talk', 'A first consultation at the kitchen table, plans and samples laid out', 1200, 990) ?></div>
  </div>
</section>

<?= cta_band() ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
