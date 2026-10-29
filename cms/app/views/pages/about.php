<?php
/**
 * About: who we are (the page body is the longer text), what we stand for, the numbers.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$view->schema(['@type' => 'AboutPage', '@id' => url('/about/') . '#page', 'name' => plainText($page['title']), 'url' => url('/about/'), 'about' => ['@id' => Seo::orgId()]]);
$view->partial('page-hero', ['heroStill' => 'stills/planning']);
$values = Content::rows($d, 'values', 2);
$stats = Content::rows($d, 'stats', 2);
?>
<section class="sec">
  <div class="wrap split">
    <div class="prose reveal">
      <?php if (($k = Content::field($d, 'who_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2"><?= Content::accent(Content::field($d, 'who_title', 'Who we are')) ?></h2>
      <?= Content::dimline() ?>
      <?php if (($l = Content::field($d, 'who_lede')) !== ''): ?><p class="lede"><?= e($l) ?></p><?php endif; ?>
      <?= Content::prose((string)$page['body_html']) ?>
    </div>
    <div class="split__media reveal"><?= Content::img(Content::pageMedia($d, 'who_image'), 'stills/step-design', 'Plans, material samples and a level on a white oak work table', ['w' => 1200, 'h' => 990, 'sizes' => '(max-width: 900px) 100vw, 50vw']) ?><?php if (($tag = Content::field($d, 'who_tag')) !== ''): ?><span class="split__tag"><?= e($tag) ?></span><?php endif; ?></div>
  </div>
</section>

<?php if ($values || $stats): ?>
<section class="sec on-dark">
  <div class="wrap">
    <div class="sec__head reveal">
      <?php if (($k = Content::field($d, 'values_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'values_title', 'What we stand for')) ?></h2>
      <?= Content::dimline() ?>
    </div>
    <?php if ($values): ?>
    <div class="cards stagger">
      <?php $icons = ['level', 'frame', 'house', 'shield', 'check', 'doc']; foreach ($values as $i => [$t, $x]): ?>
      <div class="card"><span class="card__ico"><?= Content::ico($icons[$i % 6]) ?></span><h3 class="h3"><?= e($t) ?></h3><p><?= e($x) ?></p></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($stats): ?>
    <div class="stat-row stagger">
      <?php foreach ($stats as [$n, $l]): ?><div class="stat"><b><?= e($n) ?></b><span><?= e($l) ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta', ['ctaImg' => '/assets/img/stills/kitchen.webp']); ?>
