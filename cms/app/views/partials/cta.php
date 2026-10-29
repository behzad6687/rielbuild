<?php
/**
 * The estimate band near the bottom of most pages: the heading and ticks (Settings > Site-wide wording)
 * beside the estimate form. Vars: $ctaImg (a background URL), $service (a slug to preselect).
 */
defined('ICM') or exit;

$phone = Content::phone();
$points = array_values(array_filter(array_map('trim', preg_split('/\R/', site('cta.points')) ?: []), 'strlen'));
$bg = (string)($ctaImg ?? '/assets/img/hero-ending.webp');
?>
<section class="cta on-dark sec" id="book">
  <div class="cta__bg" style="background-image:url('<?= e($bg) ?>')" aria-hidden="true"></div>
  <div class="wrap cta__in">
    <div class="reveal">
      <?php if (($k = site('cta.kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(site('cta.title', 'Start with a free visit and an honest number.')) ?></h2>
      <?= Content::dimline() ?>
      <?php if ($points): ?>
      <ul class="cta__points">
        <?php foreach ($points as $pt): ?><li><?= Content::ico('check') ?><?= e($pt) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p style="margin-top:26px;color:var(--on-dark-2)"><?= e(site('cta.call', 'Rather talk now?')) ?> <a class="tlink" href="<?= e(telHref($phone)) ?>"><?= e($phone) ?></a></p>
    </div>
    <div class="reveal"><?php $view->partial('estimate-form', ['formId' => 'quote']); ?></div>
  </div>
</section>
