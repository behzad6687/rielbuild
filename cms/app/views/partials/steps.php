<?php
/** The four steps with their photos (How we work page). */
defined('ICM') or exit;

$steps = Content::steps();
if (!$steps) return;
?>
<div class="steps">
  <svg class="steps__line" viewBox="0 0 1000 2" preserveAspectRatio="none" aria-hidden="true" data-draw><path d="M0 1h1000" stroke="currentColor" stroke-width="2" pathLength="1" fill="none"/></svg>
  <?php foreach ($steps as [$n, $t, $x, $m, $still]): ?>
  <div class="step reveal">
    <span class="step__n"><?= e($n) ?></span>
    <div class="step__media"><?= Content::img($m, $still, $t, ['w' => 800, 'h' => 600, 'sizes' => '(max-width: 560px) 100vw, (max-width: 980px) 50vw, 25vw']) ?></div>
    <h3 class="h3"><?= e($t) ?></h3>
    <p><?= e($x) ?></p>
  </div>
  <?php endforeach; ?>
</div>
