<?php
/**
 * Redirect CSV import with a preview (spec 4.2.12). Vars: $errors, $preview, $batch.
 */
defined('ICM') or exit;

echo AdminUi::head('Import redirects', 'A CSV with the columns from_path and to_path (code and note optional). Nothing is saved until you press Import.',
    AdminUi::link('Back to redirects', AdminUi::url('redirects')));
echo AdminUi::errors($errors);
?>
<?php if (!$preview): ?>
  <form method="post" enctype="multipart/form-data" class="card form" action="<?= e(AdminUi::url('redirects/import')) ?>">
    <?= AdminUi::csrf() ?>
    <?= AdminUi::input('csv', 'CSV file', '', ['type' => 'file', 'accept' => '.csv,text/csv', 'required' => true, 'hint' => 'The export from this screen can be edited and imported again.']) ?>
    <button class="btn btn--primary" type="submit">Preview</button>
  </form>
<?php else:
    $counts = ['new' => 0, 'update' => 0, 'error' => 0];
    foreach ($preview as $p) $counts[$p['action']]++; ?>
  <div class="grid grid--tiles">
    <div class="stat"><span class="stat__n"><?= $counts['new'] ?></span><span class="stat__l">new</span></div>
    <div class="stat"><span class="stat__n"><?= $counts['update'] ?></span><span class="stat__l">updated</span></div>
    <div class="stat"><span class="stat__n"><?= $counts['error'] ?></span><span class="stat__l">rows with problems (skipped)</span></div>
  </div>
  <div class="table-wrap">
    <table class="table table--cards">
      <thead><tr><th scope="col">Line</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Code</th><th scope="col">Result</th></tr></thead>
      <tbody>
        <?php foreach ($preview as $p): ?>
          <tr<?= $p['action'] === 'error' ? ' class="is-muted"' : '' ?>>
            <td data-label="Line"><?= (int)$p['line'] ?></td>
            <td data-label="From" class="mono wrap-any"><?= e($p['from']) ?></td>
            <td data-label="To" class="mono wrap-any"><?= e($p['to']) ?></td>
            <td data-label="Code"><?= (int)$p['code'] ?></td>
            <td data-label="Result"><?= ['new' => '<span class="tag tag--ok">New</span>', 'update' => '<span class="tag tag--warn">Update</span>', 'error' => '<span class="tag tag--bad">Problem</span>'][$p['action']] ?>
              <?= $p['error'] ? '<span class="ed-sub">' . e($p['error']) . '</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" class="card" action="<?= e(AdminUi::url('redirects/import-apply')) ?>">
    <?= AdminUi::csrf() ?><input type="hidden" name="batch" value="<?= e($batch) ?>">
    <div class="btn-row"><button class="btn btn--primary" type="submit"<?= $counts['new'] + $counts['update'] === 0 ? ' disabled' : '' ?>>Import <?= $counts['new'] + $counts['update'] ?> redirects</button>
      <?= AdminUi::link('Start again', AdminUi::url('redirects/import')) ?></div>
  </form>
<?php endif; ?>
