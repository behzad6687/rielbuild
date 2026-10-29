<?php
/**
 * Shared blocks tab (spec 4.2.13). Vars: $rows.
 */
defined('ICM') or exit;

echo AdminUi::head('Shared blocks', 'Reusable text such as how to register, the monthly payment plan and the class information. Editing one updates every page that uses it.',
    AdminUi::link('Add a shared block', AdminUi::url('pages/shared-edit'), 'btn btn--primary'));
echo AdminUi::tabs([
    ['label' => 'Pages', 'url' => AdminUi::url('pages')],
    ['label' => 'Shared blocks', 'url' => AdminUi::url('pages/shared'), 'on' => true, 'count' => count($rows)],
    ['label' => 'Notification bar', 'url' => AdminUi::url('settings', ['section' => 'notice'])],
], 'Pages');
?>
<?php if (!$rows): ?>
  <p class="ed-empty">No shared blocks yet.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--cards">
      <thead><tr><th scope="col">Block</th><th scope="col">Starts with</th><th scope="col">Used on</th><th scope="col">Shown</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $b): ?>
          <tr>
            <td data-label="Block"><a href="<?= e(AdminUi::url('pages/shared-edit', ['id' => $b['id']])) ?>"><strong><?= e($b['title'] ?: $b['block_key']) ?></strong></a><span class="ed-sub mono"><?= e($b['block_key']) ?></span></td>
            <td data-label="Starts with"><?= e(excerpt((string)$b['body_html'], 90)) ?></td>
            <td data-label="Used on"><?= $b['usage'] ? e(implode(', ', array_map(static fn($u) => (string)$u['path'], $b['usage']))) : '<span class="muted">No page yet</span>' ?></td>
            <td data-label="Shown"><?= (int)$b['is_visible'] === 1 ? 'Yes' : 'Hidden' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
