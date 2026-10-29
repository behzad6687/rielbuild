<?php
/** The communities band (the list is the Service areas page's). Vars: $kicker, $title, $lede, $tight (bool, default true). */
defined('ICM') or exit;

$areas = Content::areas();
if (!$areas) return;
?>
<section class="sec sec--tight on-dark" id="areas">
  <div class="wrap areas-band">
    <div class="reveal">
      <?php if (trim((string)($kicker ?? '')) !== ''): ?><p class="kicker"><?= e($kicker) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent((string)($title ?? 'Where we work')) ?></h2>
      <?= Content::dimline() ?>
      <?php if (trim((string)($lede ?? '')) !== ''): ?><p class="lede" style="margin-top:18px"><?= e($lede) ?> <a class="tlink" href="/areas/">Service areas <?= Content::ico('arrow') ?></a></p><?php endif; ?>
    </div>
    <ul class="areas stagger">
      <?php foreach ($areas as $a): ?><li><?= e($a) ?></li><?php endforeach; ?>
    </ul>
  </div>
</section>
