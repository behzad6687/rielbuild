<?php
/**
 * Generic list screen for SimpleCrud modules (FAQs, Jobs, Staff, Testimonials, Pathways).
 * Vars: $cfg, $rows, $q, $active.
 */
defined('ICM') or exit;

$r = (string)$cfg['route'];
$sortable = $q === '' && !$active && count($rows) > 1;
$cell = static function (array $cfg, string $col, array $row): string {
    if (isset($cfg['format'][$col])) return (string)($cfg['format'][$col])($row);
    $v = $row[$col] ?? '';
    if ($col === $cfg['title_col']) {
        return '<a href="' . e(AdminUi::url($cfg['route'] . '/edit', ['id' => $row['id']])) . '">' . e(mb_strimwidth(Sanitizer::plain((string)$v), 0, 90, '...')) . '</a>';
    }
    return e(mb_strimwidth(Sanitizer::plain((string)$v), 0, 60, '...'));
};

echo AdminUi::head(
    (string)$cfg['plural'],
    (string)($cfg['lede'] ?? ''),
    AdminUi::link('Add ' . ($cfg['add_label'] ?? strtolower((string)$cfg['label'])), AdminUi::url($r . '/edit'), 'btn btn--primary')
);
if (!empty($cfg['banner'])) echo AdminUi::alert((string)$cfg['banner'], 'info');
?>
<form class="form form--inline ed-filters" method="get" action="<?= e(AdminUi::url($r)) ?>" role="search">
  <?= AdminUi::input('q', 'Search', $q, ['type' => 'search', 'max' => 100]) ?>
  <?php foreach ($cfg['filters'] ?? [] as $col => $opts): ?>
    <?= AdminUi::select($col, (string)($cfg['filter_labels'][$col] ?? ucfirst(str_replace('_', ' ', $col))), $opts, $active[$col] ?? '', ['blank' => 'All', 'attrs' => ['data-autosubmit' => true]]) ?>
  <?php endforeach; ?>
  <div class="field field--button"><button class="btn btn--line" type="submit">Show</button></div>
</form>

<?php if (!$rows): ?>
  <p class="ed-empty"><?= $q !== '' || $active ? 'Nothing matches. Clear the search to see everything.' : 'Nothing here yet.' ?></p>
<?php else: ?>
  <p class="ed-count"><?= count($rows) ?> <?= count($rows) === 1 ? 'item' : 'items' ?><?= $sortable ? '. Drag a row, or use the arrows, to change the order on the site.' : '' ?></p>
  <div class="table-wrap">
    <table class="table table--cards"<?= $sortable ? ' data-sortable="' . e(AdminUi::url('api/' . $r . '/sort')) . '"' : '' ?>>
      <thead>
        <tr>
          <?php if ($sortable): ?><th scope="col"><span class="sr-only">Order</span></th><?php endif; ?>
          <?php foreach ($cfg['list'] as $col => $heading): ?><th scope="col"><?= e($heading) ?></th><?php endforeach; ?>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr id="row-<?= (int)$row['id'] ?>" data-id="<?= (int)$row['id'] ?>">
            <?php if ($sortable): ?>
              <td class="order" data-label="Order">
                <span class="handle" aria-hidden="true" title="Drag to reorder"></span>
                <?= AdminUi::postButton($r . '/move', [], ['id' => $row['id'], 'dir' => 'up'], '↑', ['class' => 'btn btn--line btn--sm btn--icon', 'aria' => 'Move up']) ?>
                <?= AdminUi::postButton($r . '/move', [], ['id' => $row['id'], 'dir' => 'down'], '↓', ['class' => 'btn btn--line btn--sm btn--icon', 'aria' => 'Move down']) ?>
              </td>
            <?php endif; ?>
            <?php foreach ($cfg['list'] as $col => $heading): ?>
              <td data-label="<?= e($heading) ?>"><?= $cell($cfg, $col, $row) ?></td>
            <?php endforeach; ?>
            <td class="actions"><a class="btn btn--line btn--sm" href="<?= e(AdminUi::url($r . '/edit', ['id' => $row['id']])) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
