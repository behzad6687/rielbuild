<?php
/**
 * A text page (any page added in Pages > Add a page): the hero, the body and the blocks.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$view->partial('page-hero', ['heroStill' => 'stills/planning']);
?>
<?php if (trim((string)$page['body_html']) !== '' || !empty($d['updated'])): ?>
<section class="sec">
  <div class="wrap">
    <div class="prose prose--page">
      <?= Content::prose((string)$page['body_html']) ?>
      <?php if (!empty($d['updated']) && $page['updated_at']): ?><p class="muted"><small>Last updated <?= e(formatDate((string)$page['updated_at'])) ?></small></p><?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta'); ?>
