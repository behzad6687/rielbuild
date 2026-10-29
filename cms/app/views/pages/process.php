<?php
/**
 * How we work: the four steps, the promises (press-and-hold dial) and the worries we take off your plate.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$steps = Content::steps();
if ($steps) {
    $view->schema(['@type' => 'HowTo', 'name' => 'How a ' . siteName() . ' renovation works',
        'step' => array_map(static fn($s) => ['@type' => 'HowToStep', 'name' => $s[1], 'text' => $s[2]], $steps)]);
}
$view->partial('page-hero', ['heroStill' => 'stills/step-build']);
?>
<section class="sec">
  <div class="wrap">
    <div class="sec__head reveal">
      <?php if (($k = Content::field($d, 'steps_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'steps_title', 'How it works')) ?></h2>
      <?= Content::dimline() ?>
    </div>
    <?php $view->partial('steps'); ?>
  </div>
</section>

<?php $view->partial('promise', ['wayTitle' => Content::field($d, 'way_title', 'The way we work'), 'holdLabel' => Content::field($d, 'hold_label')]); ?>

<?php $worries = Content::rows($d, 'worries', 2); if ($worries): ?>
<section class="sec">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <?php if (($k = Content::field($d, 'worries_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
        <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'worries_title', 'The worries we take off your plate.')) ?></h2>
        <?= Content::dimline() ?>
      </div>
      <?php if (($l = Content::field($d, 'worries_lede')) !== ''): ?><p class="lede reveal"><?= e($l) ?></p><?php endif; ?>
    </div>
    <div class="cards stagger">
      <?php $icons = ['doc', 'phone', 'clock', 'shield', 'house', 'house']; foreach ($worries as $i => [$q, $a]): ?>
      <div class="card"><span class="card__ico"><?= Content::ico($icons[$i % 6]) ?></span><h3 class="h3"><?= e($q) ?></h3><p><?= e($a) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($page['body_html']): ?>
<section class="sec"><div class="wrap"><div class="prose reveal"><?= Content::prose((string)$page['body_html']) ?></div></div></section>
<?php endif; ?>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta', ['ctaImg' => '/assets/img/stills/step-walk.webp']); ?>
