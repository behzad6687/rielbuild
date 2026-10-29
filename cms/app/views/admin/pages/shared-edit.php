<?php
/**
 * Edit a shared block. Vars: $row, $values, $errors, $warnings, $usage.
 */
defined('ICM') or exit;

$v = $values;
echo AdminUi::head($row ? 'Shared block: ' . ($row['title'] ?: $row['block_key']) : 'Add a shared block', $row ? 'Key ' . $row['block_key'] . '.' : '', AdminUi::link('Back to shared blocks', AdminUi::url('pages/shared')));
echo AdminUi::errors($errors);
?>
<div class="ed-split">
  <form method="post" class="ed-form ed-main" action="<?= e(AdminUi::url('pages/shared-edit', $row ? ['id' => $row['id']] : [])) ?>">
    <?= AdminUi::csrf() ?>
    <?= $row ? AdminKit::lockField($row) : '' ?>
    <?= AdminUi::lint($warnings) ?>
    <div class="card">
      <?php if (!$row): ?><?= AdminUi::input('block_key', 'Key', $v['block_key'], ['max' => 80, 'required' => true, 'hint' => 'Starts with "shared.", for example shared.parking. Pages refer to the block by this key.']) ?><?php endif; ?>
      <?= AdminUi::input('title', 'Title', $v['title'], ['max' => 200]) ?>
      <?= AdminUi::textarea('body_html', 'Text', $v['body_html'], ['rich' => true, 'rows' => 12]) ?>
      <?= AdminUi::check('is_visible', 'Shown on the pages that use it', (int)$v['is_visible'] === 1) ?>
    </div>
    <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Save</button>' . AdminUi::link('Cancel', AdminUi::url('pages/shared'), 'btn btn--line')) ?>
  </form>
  <aside class="ed-side">
    <section class="card" aria-labelledby="used-h">
      <h2 id="used-h">Used on</h2>
      <?php if (!$usage): ?><p class="muted">No page uses this block yet.</p><?php else: ?>
        <ul class="plain-list"><?php foreach ($usage as $u): ?><li><a href="<?= e(AdminUi::url('pages/edit', ['id' => $u['id']])) ?>"><?= e($u['title']) ?></a> <span class="mono small"><?= e($u['path']) ?></span></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
    <?php if ($row && !$usage): ?>
      <div class="ed-danger"><?= AdminUi::postButton('pages/shared-delete', [], ['id' => $row['id']], 'Delete this block', ['class' => 'btn btn--danger btn--sm', 'confirm' => 'Delete ' . $row['block_key'] . '?']) ?></div>
    <?php endif; ?>
  </aside>
</div>
