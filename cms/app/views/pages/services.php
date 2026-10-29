<?php
/**
 * Services overview: every published service page as a card, then "Not sure where to start?".
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$services = Content::services();
$view->schema(Seo::itemList('Home renovation services', array_map(static fn($s) => ['name' => $s['name'], 'url' => $s['path']], $services)));
$view->partial('page-hero', ['heroStill' => 'stills/home-renovation']);
?>
<section class="sec">
  <div class="wrap">
    <?php $view->partial('services-grid', ['services' => $services, 'two' => true, 'h' => 'h2', 'useLead' => true, 'go' => 'See the details']); ?>
  </div>
</section>

<?php if (($t = Content::field($d, 'help_title')) !== ''): ?>
<section class="sec on-dark">
  <div class="wrap split">
    <div class="prose reveal">
      <?php if (($k = Content::field($d, 'help_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2"><?= Content::accent($t) ?></h2>
      <?= Content::dimline() ?>
      <?php if (($x = Content::field($d, 'help_text')) !== ''): ?><p class="lede"><?= e($x) ?></p><?php endif; ?>
      <div class="btn-row" style="margin-top:10px"><a class="btn btn--brass" href="/contact/"><?= e(site('form.heading', 'Book a free estimate')) ?></a><a class="tlink" href="/process/">How we work <?= Content::ico('arrow') ?></a></div>
    </div>
    <div class="split__media reveal"><?= Content::img(Content::pageMedia($d, 'help_image'), 'stills/step-talk', 'A first visit at the kitchen table, plans and samples laid out', ['w' => 1200, 'h' => 990, 'sizes' => '(max-width: 900px) 100vw, 50vw']) ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($page['body_html']): ?>
<section class="sec"><div class="wrap"><div class="prose reveal"><?= Content::prose((string)$page['body_html']) ?></div></div></section>
<?php endif; ?>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta'); ?>
