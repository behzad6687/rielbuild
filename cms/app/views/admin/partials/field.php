<?php
/**
 * One labelled form field for admin screens. Owner: W7; any module may use it:
 *   admin_partial('field', ['f' => $def, 'value' => $v, 'error' => $msg, 'name' => 's[org.name]']);
 * $f keys: key, label, type (text email url tel textarea number date bool select secret
 *          password emails path asset roles), hint, required, max, min, rows, options,
 *          autocomplete, disabled, count ([min, max] shows a live counter), lint (bool).
 * Secret fields: $hasSecret shows "a value is stored"; empty keeps it; $clearName names the
 * "Clear" checkbox.
 */
defined('ICM') or exit;

$type = (string)($f['type'] ?? 'text');
$key = (string)$f['key'];
$name = (string)($name ?? $key);
$id = 'f-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($key));
$value = $value ?? '';
$error = $error ?? null;
$hint = (string)($f['hint'] ?? '');
$describedBy = trim(($hint !== '' ? $id . '-hint ' : '') . ($error ? $id . '-err' : ''));
$common = [
    'id'               => $id,
    'name'             => $name,
    'required'         => !empty($f['required']) && $type !== 'bool',
    'disabled'         => !empty($f['disabled']),
    'aria-invalid'     => $error ? 'true' : null,
    'aria-describedby' => $describedBy !== '' ? $describedBy : null,
];
$max = isset($f['max']) && in_array($type, ['text', 'email', 'url', 'tel', 'textarea', 'secret', 'password', 'emails', 'path', 'asset'], true) ? (int)$f['max'] : null;
?>
<div class="field<?= $error ? ' field--error' : '' ?><?= $type === 'bool' ? ' field--check' : '' ?>">
<?php if ($type === 'bool'): ?>
  <input type="hidden" name="<?= e($name) ?>" value="0">
  <label class="check" for="<?= e($id) ?>">
    <input type="checkbox"<?= attrs($common) ?> value="1"<?= (string)$value === '1' ? ' checked' : '' ?>>
    <span><?= e($f['label']) ?></span>
  </label>
<?php elseif ($type === 'roles'): $chosen = array_map('trim', explode(',', (string)$value)); ?>
  <fieldset>
    <legend class="field__label"><?= e($f['label']) ?></legend>
<?php foreach (Auth::ROLES as $r => $label): ?>
    <label class="check"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($r) ?>"<?= in_array($r, $chosen, true) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
  </fieldset>
<?php else: ?>
  <label class="field__label" for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></label>
<?php if ($type === 'textarea'): ?>
  <textarea<?= attrs($common + ['rows' => (string)($f['rows'] ?? 4), 'maxlength' => $max, 'data-count' => !empty($f['count']) ? implode(',', $f['count']) : null, 'data-lint' => !empty($f['lint']) ? '1' : null]) ?>><?= e($value) ?></textarea>
<?php elseif ($type === 'select'): ?>
  <select<?= attrs($common) ?>>
<?php if (empty($f['required']) && !array_key_exists('', (array)$f['options']) && !array_key_exists((string)$value, (array)$f['options'])): ?>
    <option value="">Choose...</option>
<?php endif; ?>
<?php foreach ((array)$f['options'] as $ov => $ol): ?>
    <option value="<?= e((string)$ov) ?>"<?= (string)$ov === (string)$value ? ' selected' : '' ?>><?= e($ol) ?></option>
<?php endforeach; ?>
  </select>
<?php elseif ($type === 'secret'): ?>
  <div class="reveal">
    <input type="password"<?= attrs($common + ['maxlength' => $max, 'autocomplete' => 'new-password', 'placeholder' => !empty($hasSecret) ? 'Stored. Leave empty to keep it.' : 'Not set']) ?> value="">
    <button type="button" class="reveal__btn" data-reveal="<?= e($id) ?>" aria-pressed="false">Show</button>
  </div>
<?php if (!empty($hasSecret)): ?>
  <label class="check check--small"><input type="checkbox" name="<?= e((string)($clearName ?? 'clear_' . $key)) ?>" value="1"><span>Clear the stored value</span></label>
<?php endif; ?>
<?php else:
    $inputType = match ($type) {
        'email' => 'email', 'url' => 'url', 'tel' => 'tel', 'number' => 'number', 'date' => 'date', 'password' => 'password', default => 'text',
    };
    $extra = [
        'type'         => $inputType,
        'value'        => (string)$value,
        'maxlength'    => $max,
        'min'          => $type === 'number' && isset($f['min']) ? (string)$f['min'] : null,
        'max'          => $type === 'number' && isset($f['max']) ? (string)$f['max'] : null,
        'inputmode'    => match ($type) { 'number' => 'numeric', 'email', 'emails' => 'email', 'url' => 'url', 'tel' => 'tel', default => null },
        'pattern'      => $type === 'number' ? '[0-9]*' : null,
        'autocomplete' => $f['autocomplete'] ?? ($type === 'password' ? 'off' : null),
        'autocapitalize' => in_array($type, ['email', 'emails', 'url', 'path', 'asset'], true) ? 'none' : null,
        'spellcheck'   => in_array($type, ['email', 'emails', 'url', 'path', 'asset'], true) ? 'false' : null,
        'data-count'   => !empty($f['count']) ? implode(',', $f['count']) : null,
        'data-lint'    => !empty($f['lint']) ? '1' : null,
    ];
?>
<?php if ($type === 'password'): ?>
  <div class="reveal">
    <input<?= attrs($common + $extra) ?>>
    <button type="button" class="reveal__btn" data-reveal="<?= e($id) ?>" aria-pressed="false">Show</button>
  </div>
<?php else: ?>
  <input<?= attrs($common + $extra) ?>>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($hint !== ''): ?>
  <span class="field__hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></span>
<?php endif; ?>
<?php if ($error): ?>
  <span class="field__error" id="<?= e($id) ?>-err"><?= e($error) ?></span>
<?php endif; ?>
<?php if (!empty($f['lint']) && trim((string)$value) !== '' && ($p = Plain::problems((string)$value))): ?>
  <?php admin_partial('lint', ['problems' => $p]); ?>
<?php endif; ?>
</div>
