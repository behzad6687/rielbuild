<?php
require dirname(__DIR__) . '/inc/boot.php';
$all = $FAQ;
foreach ($SERVICES as $s) foreach ($s['faq'] as $q) $all[] = $q;
$page = array(
    'title'  => 'Renovation FAQ: Deposits, Timelines, Permits & Cost | RIELBUILD',
    'desc'   => 'Straight answers to the questions GTA homeowners ask about renovations: deposits, written quotes, timelines, permits, cost, dust and living at home during the work.',
    'path'   => 'faq/',
    'crumbs' => array(array('FAQ', 'faq/')),
    'schema' => array(array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($q) {
        return array('@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $q[1]));
    }, $all))),
);
include dirname(__DIR__) . '/inc/header.php';
echo plumb_line();
?>
<main id="main" tabindex="-1">
<?= page_hero('Straight answers', 'Ask us <span class="brass">anything.</span>', 'The questions we hear at almost every first visit, answered plainly. Do not see yours? Call us, we like questions.', 'stills/step-talk', $page['crumbs']) ?>

<section class="sec">
  <div class="wrap faq-split">
    <div class="sec__head reveal">
      <p class="kicker">Before you start</p>
      <h2 class="h2">Money, time and trust.</h2>
      <?= dimline() ?>
      <p class="lede">Still unsure? <a class="tlink" href="tel:<?= e(PHONE_TEL) ?>"><?= e(PHONE) ?></a></p>
    </div>
    <div class="faq stagger">
      <?php foreach ($FAQ as $i => $q): ?><details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($q[0]) ?></summary><p class="faq__a"><?= e($q[1]) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal">
      <p class="kicker">By project</p>
      <h2 class="h2" style="margin-top:16px">Questions about specific jobs.</h2>
      <?= dimline() ?>
    </div>
    <?php foreach ($SERVICES as $slug => $s): ?>
    <div class="faq-split" style="margin-bottom:44px">
      <div class="reveal"><h3 class="h3"><a href="<?= e(url('services/' . $slug . '/')) ?>"><?= e($s['name']) ?></a></h3></div>
      <div class="faq"><?php foreach ($s['faq'] as $q): ?><details><summary><?= e($q[0]) ?></summary><p class="faq__a"><?= e($q[1]) ?></p></details><?php endforeach; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<?= cta_band() ?>
</main>
<?php include dirname(__DIR__) . '/inc/footer.php'; ?>
