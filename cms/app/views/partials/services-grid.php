<?php
/** The service cards. Vars: $services (Content::services() rows), $two (bool: two columns), $h ('h2'|'h3'), $useLead (bool), $go (link text). */
defined('ICM') or exit;
?>
<div class="svc-grid<?= !empty($two) ? ' svc-grid--2' : '' ?> stagger">
  <?php foreach ($services as $s): ?>
  <a class="svc" href="<?= e($s['path']) ?>">
    <div class="svc__media"><?= Content::img($s['media'], $s['still'], $s['name'] . ' by ' . siteName(), ['w' => 800, 'h' => 600, 'sizes' => !empty($two) ? '(max-width: 600px) 100vw, 50vw' : '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 25vw']) ?><span class="svc__icon"><?= Content::ico($s['icon']) ?></span></div>
    <div class="svc__body">
      <<?= e($h ?? 'h3') ?> class="h3"><?= e($s['name']) ?></<?= e($h ?? 'h3') ?>>
      <p><?= e(!empty($useLead) ? ($s['lead'] ?: $s['tag']) : $s['tag']) ?></p>
      <span class="svc__go"><?= e($go ?? 'See how we do it') ?> <?= Content::ico('arrow') ?></span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
