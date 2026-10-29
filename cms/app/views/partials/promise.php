<?php
/** "The way we work": the press-and-hold plumb dial beside the promises (How we work page). Vars: $wayTitle, $holdLabel. */
defined('ICM') or exit;

$promises = Content::promises();
if (!$promises) return;
$label = trim((string)($holdLabel ?? '')) ?: 'Press and hold to set it plumb';
?>
<section class="sec on-dark promise" id="promise">
  <div class="wrap promise__grid">
    <div class="reveal">
      <div class="hold" data-hold>
        <svg class="hold__ring" viewBox="0 0 100 100" aria-hidden="true"><circle class="bg" cx="50" cy="50" r="48"/><circle class="fg" cx="50" cy="50" r="48" pathLength="1"/></svg>
        <span class="hold__true" aria-hidden="true"></span>
        <span class="hold__plumb" aria-hidden="true"><svg class="hold__bob" viewBox="0 0 30 44"><path d="M15 0v10" stroke="currentColor" stroke-width="2"/><path d="M15 10c-6.4 0-11 5-11 10.2C4 25.3 15 44 15 44s11-18.7 11-23.8C26 15 21.4 10 15 10z" fill="currentColor"/></svg></span>
        <button class="hold__btn" type="button" aria-pressed="false" aria-label="Press and hold to settle the plumb bob and reveal our promises"></button>
        <p class="hold__label" aria-live="polite"><?= e($label) ?></p>
      </div>
    </div>
    <div>
      <div class="reveal">
        <h2 class="h2"><?= Content::accent((string)($wayTitle ?? 'The way we work')) ?></h2>
        <?= Content::dimline() ?>
      </div>
      <ol class="pl" style="margin-top:30px">
        <?php foreach ($promises as [$t, $x]): ?><li><b><?= e($t) ?></b><span><?= e($x) ?></span></li><?php endforeach; ?>
      </ol>
    </div>
  </div>
</section>
