<?php
/**
 * Copy linter warnings (spec 4.1.3). Saving still works. Owner: W7.
 *   admin_partial('lint', ['problems' => Plain::problems($html)]);
 * @var list<string> $problems
 */
defined('ICM') or exit;
if (empty($problems)) return;
?>
<div class="lint" role="status">
  <p class="lint__title">House style <?= count($problems) === 1 ? 'note' : 'notes' ?> (saving still works):</p>
  <ul>
<?php foreach ($problems as $p): ?>
    <li><?= e($p) ?></li>
<?php endforeach; ?>
  </ul>
</div>
