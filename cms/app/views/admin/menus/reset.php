<?php
/**
 * "Reset this menu to the default", with a confirm step (V2-PLAN 4 WP-B task 4). Vars: $menu, $menuRow, $defaults
 * (list of [depth, label, automatic list name]), $count.
 */
defined('ICM') or exit;

echo AdminUi::head('Reset a menu', 'The ' . mb_strtolower((string)$menuRow['label']) . ' goes back to the items it had on a new site. Its ' . $count . ' items today are replaced, with their photos and descriptions.',
    AdminUi::link('Back to the menu', AdminUi::url('menus', ['menu' => $menu])));
?>
<section class="card" aria-labelledby="reset-h">
  <h2 id="reset-h">The default items</h2>
  <ul class="menu-defaults">
    <?php foreach ($defaults as [$depth, $label, $auto]): ?>
      <li style="--depth: <?= (int)$depth ?>"><?= e($label) ?><?php if ($auto !== ''): ?> <span class="tag">Automatic: <?= e($auto) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<form method="post" class="card" action="<?= e(AdminUi::url('menus/reset')) ?>">
  <?= AdminUi::csrf() ?>
  <input type="hidden" name="menu" value="<?= e($menu) ?>">
  <?= AdminUi::check('confirm', 'Yes, replace every item of this menu with the default items', false) ?>
  <p><button class="btn btn--danger" type="submit">Reset this menu</button> <?= AdminUi::link('Cancel', AdminUi::url('menus', ['menu' => $menu]), 'btn btn--line') ?></p>
</form>
