<?php
/**
 * "Switch this list to automatic" with a preview (V2-PLAN P4; ADMIN-AUDIT 4.15). Vars: $menu, $menuRow, $candidates.
 */
defined('ICM') or exit;

echo AdminUi::head('Switch lists to automatic', 'In the ' . mb_strtolower((string)$menuRow['label']) . '. Each list below is kept by hand today. As an automatic list it fills itself from the pages, so it changes when they change.',
    AdminUi::link('Back to the menu', AdminUi::url('menus', ['menu' => $menu])));
?>
<?php if (!$candidates): ?>
  <p class="ed-empty">No list of this menu matches an automatic list right now.</p>
<?php else: ?>
  <form method="post" action="<?= e(AdminUi::url('menus/auto')) ?>">
    <?= AdminUi::csrf() ?>
    <input type="hidden" name="menu" value="<?= e($menu) ?>">
    <?php foreach ($candidates as $c): ?>
      <section class="card menu-switch">
        <h2><?= e(($c['parent_label'] !== '' ? $c['parent_label'] . ': ' : '') . (Menus::AUTO_SOURCES[$c['source']][0] ?? $c['source'])) ?></h2>
        <div class="menu-switch__cols">
          <div>
            <h3>Now, kept by hand</h3>
            <ul class="plain-list"><?php foreach ($c['before'] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
          </div>
          <div>
            <h3>Automatic, right now it shows</h3>
            <?php if ($c['after']): ?>
              <ul class="plain-list"><?php foreach ($c['after'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
              <p class="muted">Nothing right now.</p>
            <?php endif; ?>
          </div>
        </div>
        <?= AdminUi::check('keys[]', 'Switch this list', true, ['value' => $c['key']]) ?>
      </section>
    <?php endforeach; ?>
    <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Switch the ticked lists</button>' . AdminUi::link('Cancel', AdminUi::url('menus', ['menu' => $menu]), 'btn btn--line')) ?>
  </form>
<?php endif; ?>
