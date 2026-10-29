<?php
/**
 * FAQ: the general questions, then each service's questions under its name (Admin > FAQs).
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$general = Content::faqs('general');
$byService = [];
foreach (Content::services() as $s) {
    $q = Content::faqs($s['slug']);
    if ($q) $byService[] = [$s, $q];
}
$all = $general;
foreach ($byService as [, $q]) $all = array_merge($all, $q);
if ($all) $view->schema(Seo::faqPage($all));
$phone = Content::phone();
$view->partial('page-hero', ['heroStill' => 'stills/step-talk']);
?>
<?php if ($general): ?>
<section class="sec">
  <div class="wrap faq-split">
    <div class="sec__head reveal">
      <?php if (($k = Content::field($d, 'general_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2"><?= Content::accent(Content::field($d, 'general_title', 'General questions')) ?></h2>
      <?= Content::dimline() ?>
      <p class="lede">Still unsure? <a class="tlink" href="<?= e(telHref($phone)) ?>"><?= e($phone) ?></a></p>
    </div>
    <div class="faq stagger"><?php $view->partial('faq-list', ['faqs' => $general, 'openFirst' => true]); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($byService): ?>
<section class="sec sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal">
      <?php if (($k = Content::field($d, 'byservice_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'byservice_title', 'Questions about specific jobs')) ?></h2>
      <?= Content::dimline() ?>
    </div>
    <?php foreach ($byService as [$s, $q]): ?>
    <div class="faq-split" style="margin-bottom:44px">
      <div class="reveal"><h3 class="h3"><a href="<?= e($s['path']) ?>"><?= e($s['name']) ?></a></h3></div>
      <div class="faq"><?php $view->partial('faq-list', ['faqs' => $q]); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta'); ?>
