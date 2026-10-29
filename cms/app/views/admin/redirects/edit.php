<?php
/**
 * Add or edit a redirect (spec 4.2.12). Vars: $row, $values, $errors, $codes, $from404, $pages.
 */
defined('ICM') or exit;

$v = $values;
echo AdminUi::head($row ? 'Edit redirect' : ($from404 !== '' ? 'Redirect a missing address' : 'Add a redirect'), 'Addresses are stored in lowercase with a trailing slash, so /Coaches and /coaches/ match the same rule.',
    AdminUi::link('Back', AdminUi::url($from404 !== '' ? 'redirects/404' : 'redirects')));
echo AdminUi::errors($errors);
?>
<form method="post" class="ed-form form" action="<?= e(AdminUi::url('redirects/edit', $row ? ['id' => $row['id']] : [])) ?>">
  <?= AdminUi::csrf() ?>
  <?php if ($from404 !== ''): ?><input type="hidden" name="from404" value="<?= e($from404) ?>"><?php endif; ?>
  <div class="card">
    <?= AdminUi::input('from_path', 'Old address', $v['from_path'], ['max' => 500, 'required' => true, 'placeholder' => '/old-page/', 'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
    <?= AdminUi::input('to_path', 'Send visitors to', $v['to_path'], ['max' => 255, 'list' => 'redirect-pages', 'placeholder' => '/all-online-registrations/',
        'hint' => 'Pick a page or type an address. It must not be redirected itself, so visitors take one step.', 'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
    <?= AdminUi::datalist('redirect-pages', $pages) ?>
    <?= AdminUi::select('code', 'Kind', $codes, (int)$v['code'], ['hint' => '301 for pages that moved for good (search engines follow it). 410 tells them the page is gone.']) ?>
    <?= AdminUi::input('note', 'Note (optional)', $v['note'], ['max' => 255]) ?>
  </div>
  <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Save redirect</button>') ?>
</form>
<?php if ($row): ?>
  <p class="muted small">Source: <?= e($row['source']) ?>. Used <?= (int)$row['hits'] ?> times<?= $row['last_hit_at'] ? ', last ' . e(AdminKit::ago((string)$row['last_hit_at'])) : '' ?>.</p>
  <div class="ed-danger"><?= AdminUi::postButton('redirects/delete', [], ['id' => $row['id']], 'Delete this redirect', ['class' => 'btn btn--danger btn--sm', 'confirm' => 'Delete the redirect from ' . $row['from_path'] . '?']) ?></div>
<?php endif; ?>
