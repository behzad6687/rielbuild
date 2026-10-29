<?php
/**
 * Service areas: the communities (this page's list is used everywhere), and popular work by area.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$areas = Content::areas();
$popular = Content::rows($d, 'popular', 4);
$view->partial('page-hero', ['heroStill' => 'hero-start']);
?>
<section class="sec">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <?php if (($k = Content::field($d, 'list_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
        <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'list_title', 'Communities we serve')) ?></h2>
        <?= Content::dimline() ?>
      </div>
      <?php if (($l = Content::field($d, 'list_lede')) !== ''): ?><p class="lede reveal"><?= e($l) ?></p><?php endif; ?>
    </div>
    <ul class="areas stagger"><?php foreach ($areas as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
  </div>
</section>

<?php if ($popular): ?>
<section class="sec sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal">
      <?php if (($k = Content::field($d, 'popular_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'popular_title', 'Popular in your area')) ?></h2>
      <?= Content::dimline() ?>
    </div>
    <div class="cards stagger">
      <?php foreach ($popular as [$t, $x, $link, $label]):
          $icon = 'house';
          foreach (Content::services() as $s) if ($link !== '' && str_starts_with($link, $s['path'])) $icon = $s['icon']; ?>
      <div class="card"><span class="card__ico"><?= Content::ico($icon) ?></span><h3 class="h3"><?= e($t) ?></h3><p><?= e($x) ?><?php if ($link !== ''): ?> <a class="tlink" href="<?= e($link) ?>"><?= e($label ?: 'Learn more') ?></a><?php endif; ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php if ($page['body_html']): ?>
<section class="sec"><div class="wrap"><div class="prose reveal"><?= Content::prose((string)$page['body_html']) ?></div></div></section>
<?php endif; ?>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta'); ?>
