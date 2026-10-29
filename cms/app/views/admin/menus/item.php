<?php
/**
 * Edit or add one menu item (V2-PLAN 4 WP-B task 4; ADMIN-AUDIT 4.12). Vars: $menu, $menuRow, $row, $v, $errors,
 * $warnings, $preview, $pages, $parents.
 * Without JavaScript every "Links to" panel shows and the chosen one is used; menus.js shows only the chosen
 * panel and refreshes the preview as fields change (api/menus/preview).
 */
defined('ICM') or exit;

$view->script(admin_asset('menus.js'));
$o = is_array($v['opts'] ?? null) ? $v['opts'] : [];
$kinds = Menus::KINDS;
echo AdminUi::head($row ? 'Edit a menu item' : 'Add a menu item', 'In the ' . mb_strtolower((string)$menuRow['label']) . '. ' . MenusAdmin::whereText($menuRow),
    AdminUi::link('Back to the menu', AdminUi::url('menus', ['menu' => $menu])));
echo AdminUi::errors($errors);
if ($warnings) echo AdminUi::alert(implode(' ', $warnings), 'info');
$sel = static fn(string $k): string => (string)($o[$k] ?? '');
?>
<form method="post" enctype="multipart/form-data" class="ed-form menu-form" action="<?= e(AdminUi::url('menus/item', $row ? ['id' => $row['id']] : [])) ?>"
      data-menu-item data-preview="<?= e(AdminUi::url('api/menus/preview')) ?>">
  <?= AdminUi::csrf() ?>
  <?= $row ? AdminKit::lockField($row) : '' ?>
  <input type="hidden" name="menu" value="<?= e($menu) ?>">
  <?php if ($row): ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><?php endif; ?>
  <div class="ed-split">
    <div class="ed-main">
      <section class="card" aria-labelledby="mi-link-h">
        <h2 id="mi-link-h">What it links to</h2>
        <?= AdminUi::select('kind', 'Links to', $kinds, $v['kind'], ['hint' => 'A page follows the page: when its address changes or it becomes a draft, the menu follows.']) ?>
        <?= AdminUi::input('label', 'Label', $v['label'], ['max' => 120,
            'hint' => 'Leave it empty for a page to use its own name. A web address, a heading and an automatic list need one.']) ?>

        <fieldset class="menu-panel" data-kind-panel="page"><legend>Page</legend>
          <?= AdminUi::select('page_id', 'Page', $pages, $v['page_id'] ?? '', ['blank' => 'Choose a page']) ?>
        </fieldset>
        <fieldset class="menu-panel" data-kind-panel="link"><legend>Web address</legend>
          <?= AdminUi::input('path', 'Address', $v['path'], ['max' => 255, 'placeholder' => '/process/#promise or https://',
              'hint' => 'A page of this site starts with /. Another site needs the full https:// address.']) ?>
        </fieldset>
        <fieldset class="menu-panel" data-kind-panel="auto"><legend>Automatic list</legend>
          <?php $src = []; foreach (Menus::AUTO_SOURCES as $k => [$lab, $what]) $src[$k] = $lab . ': ' . $what; ?>
          <?= AdminUi::select('auto_source', 'It lists', $src, $v['auto_source'], ['blank' => 'Choose a list']) ?>
          <div class="menu-opts">
            <div data-auto-for="pages_children"><?= AdminUi::select('opts[parent]', 'Pages under', $pages, $sel('parent'), ['blank' => 'Choose a page']) ?></div>
            <div><?= AdminUi::input('opts[limit]', 'At most', $sel('limit'), ['type' => 'number', 'min' => 1, 'maxv' => 24, 'class' => 'field--small', 'hint' => 'Empty: every item.']) ?></div>
            <div><?= AdminUi::check('opts[keep_heading]', 'Keep this item as a heading above the list', !empty($o['keep_heading'])) ?></div>
          </div>
        </fieldset>
        <p class="menu-preview" data-menu-preview role="status" aria-live="polite"><?= e($preview) ?></p>
      </section>

      <section class="card" aria-labelledby="mi-photo-h">
        <h2 id="mi-photo-h">Photo and words</h2>
        <p class="muted small">Used by the big services panel and the phone menu. Left empty, a service page uses its own short line, icon and photo.</p>
        <?= AdminUi::input('description', 'Short description', $v['description'], ['max' => 200, 'count' => '0,120', 'hint' => 'One line under the label.']) ?>
        <?= AdminUi::imageField('image', $v['media_id'] ?? null, null, ['label' => 'Photo',
            'hint' => 'JPEG, PNG or WebP, 12 MB at most. Menus show small copies of it, loaded only when a menu opens.']) ?>
        <?= AdminUi::select('icon', 'Icon', Menus::ICONS, $v['icon'], ['blank' => 'No icon', 'hint' => 'Shown beside the label in the services panel and the phone menu.']) ?>
      </section>
    </div>

    <aside class="ed-side">
      <section class="card" aria-labelledby="mi-where-h">
        <h2 id="mi-where-h">Where and when</h2>
        <?= AdminUi::select('parent_id', 'Sits under', $parents, $v['parent_id'] ?? '', ['blank' => 'Top level']) ?>
        <?= AdminUi::select('layout', 'Panel style (top items with sub-items)', Menus::LAYOUTS, $v['layout'], ['blank' => 'A plain list',
            'hint' => 'The big panel shows each sub-item with its icon and a photo preview.']) ?>
        <div class="ed-row">
          <?= AdminUi::input('visible_from', 'Show from', $v['visible_from'], ['type' => 'date']) ?>
          <?= AdminUi::input('visible_until', 'Show until', $v['visible_until'], ['type' => 'date']) ?>
        </div>
        <?= AdminUi::check('is_visible', 'Shown on the site', (int)$v['is_visible'] === 1) ?>
        <?= AdminUi::check('opens_new_tab', 'Opens in a new tab', (int)$v['opens_new_tab'] === 1) ?>
      </section>
    </aside>
  </div>
  <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Save the item</button>' . AdminUi::link('Cancel', AdminUi::url('menus', ['menu' => $menu]), 'btn btn--line')) ?>
</form>
