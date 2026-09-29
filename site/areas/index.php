<?php
require dirname(__DIR__) . '/inc/boot.php';
$page = array(
    'title'  => 'Service Areas: Renovations in Toronto, Vaughan, Markham & the GTA | RIELBUILD',
    'desc'   => 'RIELBUILD renovates homes across Toronto, North York, Etobicoke, Scarborough, Vaughan, Richmond Hill, Markham, Mississauga, Oakville and the rest of the GTA.',
    'path'   => 'areas/',
    'crumbs' => array(array('Service areas', 'areas/')),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
?>
<main id="main" tabindex="-1">
<?= page_hero('Where we work', 'Toronto and <span class="brass">the whole GTA.</span>', 'Kitchens, bathrooms, basements, full renovations and custom builds, from downtown Toronto to York, Peel and Durham.', 'hero-start', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <p class="kicker">Communities we serve</p>
        <h2 class="h2" style="margin-top:16px">If it's in the GTA, we'll come see it.</h2>
        <?= dimline() ?>
      </div>
      <p class="lede reveal">Every first visit is free. Not on the list? Call anyway. If we can do the job right, we will.</p>
    </div>
    <ul class="areas stagger"><?php foreach ($AREAS as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
  </div>
</section>

<section class="sec sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal">
      <p class="kicker">Popular in your area</p>
      <h2 class="h2" style="margin-top:16px">What GTA homeowners ask us for most.</h2>
      <?= dimline() ?>
    </div>
    <div class="cards stagger">
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-basement"/></svg></span><h3 class="h3">Basements in Vaughan, Markham and Richmond Hill</h3><p>Family rooms, guest suites and home gyms, with moisture checks and permits handled. <a class="tlink" href="<?= e(url('services/basement-finishing/')) ?>">Basement finishing</a></p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-kitchen"/></svg></span><h3 class="h3">Kitchens across Toronto and Etobicoke</h3><p>Open-concept layouts, custom cabinetry and new lighting in older homes. <a class="tlink" href="<?= e(url('services/kitchen-renovation/')) ?>">Kitchen renovation</a></p></div>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-bath"/></svg></span><h3 class="h3">Bathrooms in North York and Mississauga</h3><p>Walk-in showers, heated floors and proper waterproofing, done in weeks. <a class="tlink" href="<?= e(url('services/bathroom-renovation/')) ?>">Bathroom renovation</a></p></div>
    </div>
  </div>
</section>

<?= cta_band() ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
