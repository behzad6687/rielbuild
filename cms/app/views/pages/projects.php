<?php
/**
 * Projects: the gallery from Admin > Projects with its filter buttons.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$projects = Content::projects();
$filters = ['all' => 'All work'] + Content::projectFilters();
$used = array_unique(array_column($projects, 'filter_key'));
$view->partial('page-hero', ['heroStill' => 'stills/kitchen-2']);
?>
<section class="sec">
  <div class="wrap">
    <?php if (count($used) > 1): ?>
    <div class="filters" data-filter-group="gallery" role="group" aria-label="Filter projects">
      <?php foreach ($filters as $k => $label): if ($k !== 'all' && !in_array($k, $used, true)) continue; ?><button type="button" data-filter="<?= e($k) ?>" aria-pressed="<?= $k === 'all' ? 'true' : 'false' ?>" class="<?= $k === 'all' ? 'is-on' : '' ?>"><?= e($label) ?></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="gal gal--all stagger" id="gallery">
      <?php foreach ($projects as $pj): $m = Content::media((int)$pj['media_id']); ?>
      <figure class="gal__item" data-tags="<?= e($pj['filter_key']) ?>"><?= Content::img($m, '', (string)($pj['alt'] ?: $pj['title']), ['sizes' => '(max-width: 520px) 100vw, (max-width: 860px) 50vw, 33vw']) ?><figcaption><?php if ($pj['category']): ?><small><?= e($pj['category']) ?></small><?php endif; ?><b><?= e($pj['title']) ?></b></figcaption></figure>
      <?php endforeach; ?>
    </div>
    <?php if (($note = Content::field($d, 'note')) !== ''): ?>
    <p class="note" style="margin-top:30px"><?= Content::ico('star') ?><span><?= e($note) ?></span></p>
    <?php endif; ?>
    <?php if ($page['body_html']): ?><div class="prose" style="margin-top:40px"><?= Content::prose((string)$page['body_html']) ?></div><?php endif; ?>
  </div>
</section>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta', ['ctaImg' => '/assets/img/stills/basement.webp']); ?>
