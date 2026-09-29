<?php
/* Template for one service page. Each services/<slug>/index.php sets $slug and includes this. */
if (!isset($slug)) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/boot.php';
if (!isset($SERVICES[$slug])) { http_response_code(404); exit; }
$s = $SERVICES[$slug];
$page = array(
    'title'  => $s['title'] . ' | RIELBUILD',
    'desc'   => $s['desc'],
    'path'   => 'services/' . $slug . '/',
    'og'     => 'img/' . $s['img'] . '.jpg',
    'crumbs' => array(array('Services', 'services/'), array($s['name'], 'services/' . $slug . '/')),
    'schema' => array(
        array(
            '@context' => 'https://schema.org', '@type' => 'Service',
            'name' => $s['name'], 'serviceType' => $s['name'], 'description' => $s['desc'],
            'url' => abs_url('services/' . $slug . '/'), 'image' => abs_url('assets/img/' . $s['img'] . '.jpg'),
            'provider' => array('@id' => abs_url('') . '#business'),
            'areaServed' => array('@type' => 'AdministrativeArea', 'name' => 'Greater Toronto Area, Ontario'),
        ),
        array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($q) {
            return array('@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $q[1]));
        }, $s['faq'])),
    ),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
$facts = '<div class="page-hero__facts"><span class="fact"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-doc"/></svg>Fixed, written quote</span><span class="fact"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-clock"/></svg>Written timeline</span><span class="fact"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-shield"/></svg>Written warranty</span></div>'
    . '<div class="btn-row"><a class="btn btn--brass" href="' . e(url('contact/?service=' . $slug)) . '">Book a free consultation</a><a class="btn btn--ghost" href="tel:' . e(PHONE_TEL) . '"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg>' . e(PHONE) . '</a></div>';
?>
<main id="main" tabindex="-1">
<?= page_hero($s['short'] . ' in the GTA', e($s['name']) . '<span class="brass">.</span>', $s['tag'], $s['img'], $page['crumbs'], $facts) ?>

<section class="sec">
  <div class="wrap split">
    <div class="prose reveal">
      <p class="kicker">How we do it</p>
      <h2 class="h2">Done right, the first time.</h2>
      <?= dimline() ?>
      <p class="lede"><?= e($s['lead']) ?></p>
      <ul class="checks">
        <?php foreach ($s['includes'] as $inc): ?><li><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-check"/></svg><?= e($inc) ?></li><?php endforeach; ?>
      </ul>
      <div class="note" style="margin-top:10px"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-clock"/></svg><p><b>How long it takes.</b> <?= e($s['time']) ?></p></div>
    </div>
    <div class="split__media reveal"><?= pic($s['img'], $s['name'] . ' project, finished', 1200, 990) ?><span class="split__tag"><?= e($s['short']) ?></span></div>
  </div>
</section>

<section class="sec on-dark">
  <div class="wrap">
    <div class="sec__head reveal">
      <p class="kicker">The plumb promise</p>
      <h2 class="h2" style="margin-top:16px">What you get on every <?= e(strtolower($s['short'])) ?> job.</h2>
      <?= dimline() ?>
    </div>
    <div class="cards stagger" style="grid-template-columns:repeat(2,1fr)">
      <?php foreach ($PROMISES as $i => $pr): ?>
      <div class="card"><span class="card__ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-<?= array('doc', 'check', 'phone', 'shield')[$i] ?>"/></svg></span><h3 class="h3"><?= e($pr[0]) ?></h3><p><?= e($pr[1]) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec">
  <div class="wrap faq-split">
    <div class="sec__head reveal">
      <p class="kicker">Straight answers</p>
      <h2 class="h2"><?= e($s['short']) ?> questions.</h2>
      <?= dimline() ?>
    </div>
    <div class="faq stagger">
      <?php foreach ($s['faq'] as $i => $q): ?><details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($q[0]) ?></summary><p class="faq__a"><?= e($q[1]) ?></p></details><?php endforeach; ?>
      <?php foreach (array_slice($FAQ, 0, 3) as $q): ?><details><summary><?= e($q[0]) ?></summary><p class="faq__a"><?= e($q[1]) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec sec--tight sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal"><p class="kicker">More ways we build</p></div>
    <div class="related stagger">
      <?php $n = 0; foreach ($SERVICES as $k => $o): if ($k === $slug || $n >= 3) continue; $n++; ?>
      <a class="svc" href="<?= e(url('services/' . $k . '/')) ?>">
        <div class="svc__media"><?= pic($o['img'], $o['name'], 800, 600, true, '(max-width: 860px) 100vw, 33vw') ?><span class="svc__icon"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-<?= e($o['icon']) ?>"/></svg></span></div>
        <div class="svc__body"><h3 class="h3"><?= e($o['name']) ?></h3><p><?= e($o['tag']) ?></p><span class="svc__go">Learn more <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></span></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?= cta_band($s['img']) ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
