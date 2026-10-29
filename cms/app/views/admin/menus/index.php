<?php
/**
 * The Menus screen (V2-PLAN 4 WP-B task 4; ADMIN-AUDIT 4.12). Vars: $menu, $menus, $menuRow, $tree, $count,
 * $problems, $counts, $candidates, $programsReady, $hasDefault, $thumb.
 * Works without JavaScript (every action is a small form); menus.js adds drag and drop to each group.
 */
defined('ICM') or exit;

$view->script(admin_asset('menus.js'));
echo AdminUi::head('Menus', 'The header, the phone menu and the footer columns. Items link to pages, web addresses or automatic lists: a new service page joins the Services lists by itself.',
    AdminUi::link('Add an item', AdminUi::url('menus/item', ['menu' => $menu]), 'btn btn--primary'));
$tabs = [];
foreach ($menus as $k => $m) $tabs[] = ['label' => (string)$m['label'], 'url' => AdminUi::url('menus', ['menu' => $k]), 'on' => $k === $menu, 'count' => (int)($counts[$k] ?? 0)];
echo AdminUi::tabs($tabs, 'Menus');
$isThumb = $menu === 'thumb_bar';
?>
<p class="menu-where"><?= e(MenusAdmin::whereText($menuRow)) ?></p>
<?php if ($count === 0): ?>
  <?= AdminUi::alert('This menu is empty. The site shows no links here.', 'warn') ?>
<?php elseif ($count > MenusAdmin::WARN_ITEMS): ?>
  <?= AdminUi::alert('This menu has ' . $count . ' items. Long menus are hard to use on a phone, so consider moving some to another menu.', 'warn') ?>
<?php endif; ?>
<?php if ($problems): ?>
  <?= AdminUi::alert(($problems === 1 ? 'One item points' : $problems . ' items point') . ' at something that is gone or hidden. ' . ($problems === 1 ? 'It is' : 'They are') . ' marked below and stay hidden on the site until fixed or deleted.', 'warn') ?>
<?php endif; ?>

<?php if ($programsReady): ?>
  <section class="card card--notice menu-ready" aria-labelledby="menu-ready-h">
    <h2 id="menu-ready-h">Programs is ready</h2>
    <p>An automatic list of every active program, each with its photo, next to Online Registration. It is hidden until you show it.</p>
    <?= AdminUi::postButton('menus/visible', [], ['id' => (int)$programsReady['id'], 'on' => 1], 'Show Programs in the header', ['class' => 'btn btn--primary btn--sm']) ?>
  </section>
<?php endif; ?>

<?php if ($candidates): ?>
  <section class="card menu-cands" aria-labelledby="menu-cands-h">
    <h2 id="menu-cands-h">Switch <?= count($candidates) === 1 ? 'this list' : 'these lists' ?> to automatic</h2>
    <p class="muted">These lists match a list the site can fill by itself. Once switched, a new page appears on its own, and a hidden one leaves. Nothing changes until you confirm.</p>
    <ul class="plain-list">
      <?php foreach ($candidates as $c): ?>
        <li><strong><?= e(($c['parent_label'] !== '' ? $c['parent_label'] . ': ' : '') . (Menus::AUTO_SOURCES[$c['source']][0] ?? $c['source'])) ?></strong>
          <span class="muted small">now <?= e(implode(', ', $c['before'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p><a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('menus/auto', ['menu' => $menu])) ?>">See the change, then switch</a></p>
  </section>
<?php endif; ?>

<?php if ($isThumb): ?>
  <section class="card" aria-labelledby="menu-thumb-h">
    <h2 id="menu-thumb-h">Phone preview</h2>
    <?php if ($thumb): ?>
      <ol class="menu-thumbprev" aria-label="The thumb bar as phones show it">
        <?php foreach ($thumb as $t): ?><li><span class="menu-thumbprev__icon" aria-hidden="true"><?= e(mb_substr((string)$t['label'], 0, 1)) ?></span><?= e($t['label']) ?></li><?php endforeach; ?>
      </ol>
    <?php else: ?>
      <p class="muted">The bar shows once it holds <?= Menus::THUMB_MIN ?> to <?= Menus::THUMB_MAX ?> items: the level finder, the call sheet, the menu, the search, a page, a web address or a program.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if (in_array((string)$menuRow['area'], ['phone', 'footer'], true)): ?>
  <details class="card menu-settings"<?= $menuRow['area'] === 'phone' ? ' open' : '' ?>>
    <summary><h2>Menu settings</h2></summary>
    <form method="post" enctype="multipart/form-data" action="<?= e(AdminUi::url('menus/settings')) ?>">
      <?= AdminUi::csrf() ?>
      <input type="hidden" name="menu" value="<?= e($menu) ?>">
      <?php if ($menuRow['area'] === 'phone'): ?>
        <?= AdminUi::check('same_as', 'Same as the header menu', trim((string)($menuRow['same_as'] ?? '')) !== '',
            ['hint' => 'Untick it to give phones their own, shorter menu: the header menu is copied here once, ready to edit.']) ?>
        <?= AdminUi::imageField('menu_image', !empty($menuRow['media_id']) ? (int)$menuRow['media_id'] : null, null,
            ['label' => 'Photo at the top of the phone menu', 'hint' => 'A wide photo of a class or a court. JPEG, PNG or WebP, 12 MB at most.']) ?>
      <?php else: ?>
        <div class="ed-row">
          <?= AdminUi::input('heading', 'Column heading', $menuRow['heading'], ['max' => 80, 'hint' => 'For example: Registration.']) ?>
        </div>
        <?= AdminUi::check('show_heading', 'Show the heading', (int)$menuRow['show_heading'] === 1) ?>
      <?php endif; ?>
      <p><button class="btn btn--primary btn--sm" type="submit">Save the settings</button></p>
    </form>
  </details>
<?php endif; ?>

<?php
$render = static function (array $rows, int $parentId) use (&$render, $menu, $isThumb): void {
    ?>
  <ol class="menu-tree" data-menu-sort data-menu="<?= e($menu) ?>" data-parent="<?= $parentId ?>" data-sort-url="<?= e(AdminUi::url('api/menus/sort')) ?>">
    <?php foreach ($rows as $r):
        $id = (int)$r['id'];
        $info = $r['info'];
        $label = $info['label'] !== '' ? $info['label'] : '(no label)';
        $shown = (int)$r['is_visible'] === 1; ?>
    <li class="menu-row<?= $shown ? '' : ' is-hidden' ?>" id="item-<?= $id ?>" data-id="<?= $id ?>">
      <div class="menu-row__card">
        <span class="menu-row__grip" aria-hidden="true"></span>
        <?php if ($info['thumb'] !== ''): ?>
          <img class="menu-row__thumb" src="<?= e($info['thumb']) ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
        <?php else: ?>
          <span class="menu-row__thumb menu-row__thumb--none" aria-hidden="true"></span>
        <?php endif; ?>
        <div class="menu-row__text">
          <a class="menu-row__label" href="<?= e(AdminUi::url('menus/item', ['id' => $id])) ?>"><?= e($label) ?></a>
          <span class="tag"><?= e(Menus::KINDS[$r['kind']] ?? (string)$r['kind']) ?></span>
          <?php if (!$shown): ?><span class="tag">Hidden</span><?php endif; ?>
          <?php if ($r['visible_from'] || $r['visible_until']): ?><span class="tag"><?= e(trim(($r['visible_from'] ? 'From ' . $r['visible_from'] : '') . ($r['visible_until'] ? ' until ' . $r['visible_until'] : ''))) ?></span><?php endif; ?>
          <?php if ($info['target'] !== ''): ?><span class="menu-row__target"><?= e($info['target']) ?></span><?php endif; ?>
          <?php if ($info['problem']): ?><p class="menu-row__problem"><span class="tag tag--bad">Needs attention</span> <?= e($info['problem']) ?></p><?php endif; ?>
          <?php if ($info['preview']): ?><p class="menu-row__preview"><?= e($info['preview']) ?></p><?php endif; ?>
        </div>
        <div class="menu-row__tools">
          <?= AdminUi::postButton('menus/move', [], ['id' => $id, 'dir' => 'up'], '↑', ['class' => 'btn btn--line btn--sm btn--icon', 'aria' => 'Move ' . $label . ' up']) ?>
          <?= AdminUi::postButton('menus/move', [], ['id' => $id, 'dir' => 'down'], '↓', ['class' => 'btn btn--line btn--sm btn--icon', 'aria' => 'Move ' . $label . ' down']) ?>
          <?= AdminUi::postButton('menus/visible', [], ['id' => $id, 'on' => $shown ? 0 : 1], $shown ? 'Hide' : 'Show', ['aria' => ($shown ? 'Hide ' : 'Show ') . $label]) ?>
          <?php if (!$isThumb && $r['kind'] !== 'auto' && (int)$r['depth'] < MenusAdmin::MAX_LEVEL): ?>
            <a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('menus/item', ['menu' => $menu, 'parent' => $id])) ?>" aria-label="Add an item under <?= e($label) ?>">Add under</a>
          <?php endif; ?>
          <?= AdminUi::postButton('menus/delete', [], ['id' => $id], 'Delete', ['class' => 'btn btn--danger btn--sm', 'aria' => 'Delete ' . $label,
              'confirm' => 'Delete "' . $label . '"?' . ($r['children'] ? ' Its sub-items are deleted too.' : '')]) ?>
        </div>
      </div>
      <?php if ($r['children']) $render($r['children'], $id); ?>
    </li>
    <?php endforeach; ?>
  </ol>
    <?php
};
?>
<?php if ($tree): ?>
  <p class="muted small menu-help">Use the arrows, or drag an item within its group, to change the order. Open an item to change what it links to, its photo and where it sits.</p>
  <p class="menu-sortmsg" data-menu-sortmsg role="status" aria-live="polite"></p>
  <?php $render($tree, 0); ?>
<?php else: ?>
  <p class="ed-empty">No items yet. <a href="<?= e(AdminUi::url('menus/item', ['menu' => $menu])) ?>">Add the first item</a>.</p>
<?php endif; ?>

<?php if ($hasDefault): ?>
  <p class="menu-reset"><a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('menus/reset', ['menu' => $menu])) ?>">Reset this menu to the default</a></p>
<?php endif; ?>
