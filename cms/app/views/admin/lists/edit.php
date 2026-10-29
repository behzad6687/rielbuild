<?php
/**
 * Generic add and edit screen for SimpleCrud modules.
 * Vars: $cfg, $row (null when adding), $values, $errors, $warnings.
 */
defined('ICM') or exit;

$r = (string)$cfg['route'];
$isNew = !$row;
$title = $isNew ? 'Add ' . ($cfg['add_label'] ?? strtolower((string)$cfg['label'])) : 'Edit ' . strtolower((string)$cfg['label']);
$multipart = (bool)array_filter($cfg['fields'], static fn($f) => $f['type'] === 'image');

echo AdminUi::head($title, $isNew ? '' : mb_strimwidth(Sanitizer::plain((string)($row[$cfg['title_col']] ?? '')), 0, 120, '...'), AdminUi::link('Back to ' . strtolower((string)$cfg['plural']), AdminUi::url($r)));
if (!empty($cfg['banner'])) echo AdminUi::alert((string)$cfg['banner'], 'info');
echo AdminUi::errors($errors);
?>
<form method="post" class="ed-form" action="<?= e(AdminUi::url($r . '/edit', $isNew ? [] : ['id' => $row['id']])) ?>"<?= $multipart ? ' enctype="multipart/form-data"' : '' ?>>
  <?= AdminUi::csrf() ?>
  <?= $row ? AdminKit::lockField($row) : '' ?>
  <?= AdminUi::lint($warnings) ?>
  <div class="card">
    <?php foreach ($cfg['fields'] as $f):
        $k = $f['key'];
        $v = $values[$k] ?? ($f['default'] ?? null);
        $o = ['required' => !empty($f['required']), 'hint' => $f['hint'] ?? '', 'max' => $f['max'] ?? null];
        switch ($f['type']):
            case 'text':
            case 'slug':
                echo AdminUi::input($k, $f['label'], $v, $o + ($f['type'] === 'slug' ? ['hint' => $f['hint'] ?? 'Leave empty to build it from the title.', 'pattern' => '[a-z0-9-]*'] : []));
                break;
            case 'email':
                echo AdminUi::input($k, $f['label'], $v, $o + ['type' => 'email', 'autocomplete' => 'off']);
                break;
            case 'url':
                echo AdminUi::input($k, $f['label'], $v, $o + ['type' => 'url', 'placeholder' => 'https://']);
                break;
            case 'date':
                echo AdminUi::input($k, $f['label'], $v, $o + ['type' => 'date']);
                break;
            case 'number':
                echo AdminUi::input($k, $f['label'], $v, $o + ['type' => 'number', 'min' => $f['min'] ?? null, 'maxv' => $f['maxv'] ?? null, 'class' => 'field--small']);
                break;
            case 'textarea':
                echo AdminUi::textarea($k, $f['label'], $v, $o + ['rows' => $f['rows'] ?? 4]);
                break;
            case 'rich':
                echo AdminUi::textarea($k, $f['label'], $v, $o + ['rich' => true, 'max' => null]);
                break;
            case 'select':
                echo AdminUi::select($k, $f['label'], $f['options'], $v ?? '', $o);
                break;
            case 'check':
                echo AdminUi::check($k, $f['label'], (bool)($v ?? 0), ['hint' => $f['hint'] ?? '']);
                break;
            case 'image':
                echo AdminUi::imageField($k, $v ? (int)$v : null, null, ['label' => $f['label'], 'hint' => $f['hint'] ?? null]);
                break;
            case 'json':
                $list = json_decode((string)($v ?? ''), true);
                $text = is_array($list) && array_is_list($list) && !array_filter($list, 'is_array')
                    ? implode("\n", array_map('strval', $list))
                    : ((string)($v ?? '') !== '' ? json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
                echo AdminUi::textarea($k, $f['label'], $text, ['rows' => 6, 'hint' => $f['hint'] ?? 'One item per line.']);
                break;
            case 'pairs':
                $items = json_decode((string)($v ?? ''), true);
                $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
                ?>
                <fieldset class="repeater" data-repeater>
                  <legend><?= e($f['label']) ?></legend>
                  <?php if (!empty($f['hint'])): ?><p class="field__hint"><?= e($f['hint']) ?></p><?php endif; ?>
                  <div class="rep-rows" data-rep-rows>
                    <?php foreach ($items as $i => $item): ?>
                      <div class="rep-row" data-rep-row>
                        <?php foreach ($f['sub'] as $sk => $sl): ?>
                          <?= AdminUi::input($k . '[' . $i . '][' . $sk . ']', $sl, $item[$sk] ?? '', ['max' => 200]) ?>
                        <?php endforeach; ?>
                        <div class="rep-tools">
                          <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-up aria-label="Move up">↑</button>
                          <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-down aria-label="Move down">↓</button>
                          <button type="button" class="btn btn--danger btn--sm" data-rep-remove>Remove</button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <template data-rep-template>
                    <div class="rep-row" data-rep-row>
                      <?php foreach ($f['sub'] as $sk => $sl): ?>
                        <div class="field"><label><?= e($sl) ?><input type="text" name="<?= e($k) ?>[__i__][<?= e($sk) ?>]" maxlength="200"></label></div>
                      <?php endforeach; ?>
                      <div class="rep-tools">
                        <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-up aria-label="Move up">↑</button>
                        <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-down aria-label="Move down">↓</button>
                        <button type="button" class="btn btn--danger btn--sm" data-rep-remove>Remove</button>
                      </div>
                    </div>
                  </template>
                  <button type="button" class="btn btn--line btn--sm" data-rep-add><?= e($f['add'] ?? 'Add a row') ?></button>
                </fieldset>
                <?php
                break;
        endswitch;
    endforeach; ?>
  </div>
  <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">' . ($isNew ? 'Add' : 'Save') . '</button>' . AdminUi::link('Cancel', AdminUi::url($r), 'btn btn--line')) ?>
</form>

<?php if (!$isNew): ?>
  <div class="ed-danger">
    <?= AdminUi::postButton($r . '/delete', [], ['id' => $row['id']], 'Delete this ' . strtolower((string)$cfg['label']), [
        'class' => 'btn btn--danger btn--sm', 'confirm' => 'Delete this ' . strtolower((string)$cfg['label']) . ' for good? This cannot be undone.',
    ]) ?>
  </div>
<?php endif; ?>
