<?php
require dirname(__DIR__) . '/inc/boot.php';
$page = array(
    'title'  => 'Renovation Projects: Kitchens, Bathrooms, Basements | RIELBUILD',
    'desc'   => 'See the kind of kitchens, bathrooms, basements and full home renovations RIELBUILD builds across Toronto and the GTA.',
    'path'   => 'projects/',
    'crumbs' => array(array('Projects', 'projects/')),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
$filters = array('all' => 'All work', 'kitchen' => 'Kitchens', 'bathroom' => 'Bathrooms', 'basement' => 'Basements', 'home' => 'Full home', 'construction' => 'Construction');
?>
<main id="main" tabindex="-1">
<?= page_hero('Projects', 'Rooms people <span class="brass">actually live in.</span>', 'Warm materials, honest details and clean lines. A look at the kind of spaces we build across the GTA.', 'stills/kitchen-2', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap">
    <div class="filters" data-filter-group="gallery" role="group" aria-label="Filter projects">
      <?php foreach ($filters as $k => $label): ?><button type="button" data-filter="<?= e($k) ?>" aria-pressed="<?= $k === 'all' ? 'true' : 'false' ?>" class="<?= $k === 'all' ? 'is-on' : '' ?>"><?= e($label) ?></button><?php endforeach; ?>
    </div>
    <div class="gal gal--all stagger" id="gallery">
      <?php foreach ($PROJECTS as $pj): ?>
      <figure class="gal__item" data-tags="<?= e($pj[3]) ?>"><?= pic($pj[0], $pj[1], 1200, 900, true, '(max-width: 520px) 100vw, (max-width: 860px) 50vw, 33vw') ?><figcaption><small><?= e($pj[2]) ?></small><b><?= e($pj[1]) ?></b></figcaption></figure>
      <?php endforeach; ?>
    </div>
    <p class="note" style="margin-top:30px"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-star"/></svg><span>Project imagery on this site is illustrative. Ask us for photos and references from recent jobs near you.</span></p>
  </div>
</section>

<?= cta_band('stills/basement') ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
