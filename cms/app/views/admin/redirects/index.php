<?php
/**
 * Redirects tab (spec 4.2.12). Vars: $pg, $q, $source, $codes, $sources, $test, $n404.
 */
defined('ICM') or exit;

echo AdminUi::head('Redirects', 'Old addresses that send visitors to the right page in one step.',
    AdminUi::link('Add a redirect', AdminUi::url('redirects/edit'), 'btn btn--primary')
    . AdminUi::link('Import CSV', AdminUi::url('redirects/import')) . AdminUi::link('Export CSV', AdminUi::url('redirects/export')));
echo AdminUi::tabs([
    ['label' => 'Redirects', 'url' => AdminUi::url('redirects'), 'on' => true, 'count' => (int)$pg['total']],
    ['label' => '404 log', 'url' => AdminUi::url('redirects/404'), 'count' => $n404 ?: null],
], 'Redirects and 404s');
?>
<section class="card" aria-labelledby="test-h">
  <h2 id="test-h">Test a URL</h2>
  <form class="form form--inline" method="get" action="<?= e(AdminUi::url('redirects')) ?>">
    <?= AdminUi::input('test', 'Address on the site', $test['path'] ?? '', ['max' => 500, 'placeholder' => '/online-registration/', 'class' => 'field--grow']) ?>
    <div class="field"><button class="btn btn--line" type="submit">Test</button></div>
  </form>
  <?php if ($test): ?>
    <ol class="plain-list">
      <?php if (!$test['hops']): ?><li><span class="mono"><?= e($test['path']) ?></span> is not redirected.</li><?php endif; ?>
      <?php foreach ($test['hops'] as $h): ?>
        <li><span class="mono"><?= e($h['from']) ?></span> goes to <span class="mono"><?= e($h['to'] !== '' ? $h['to'] : '(nothing)') ?></span>
          <span class="tag"><?= (int)$h['code'] ?></span> <span class="muted small"><?= e(['table' => 'by a redirect below', 'rule' => 'by a built-in rule', 'canonical' => 'to the standard form of the address'][$h['via']] ?? $h['via']) ?></span></li>
      <?php endforeach; ?>
      <li>It lands on: <strong><?= e($test['landing']) ?></strong><?= count($test['hops']) > 1 ? ' <span class="tag tag--warn">' . count($test['hops']) . ' steps: fix the chain</span>' : '' ?></li>
    </ol>
  <?php endif; ?>
</section>

<form class="form form--inline ed-filters" method="get" action="<?= e(AdminUi::url('redirects')) ?>" role="search">
  <?= AdminUi::input('q', 'Search addresses', $q, ['type' => 'search', 'max' => 190]) ?>
  <?= AdminUi::select('source', 'Source', $sources, $source, ['blank' => 'Any source', 'attrs' => ['data-autosubmit' => true]]) ?>
  <div class="field"><button class="btn btn--line" type="submit">Search</button></div>
</form>

<?php if (!$pg['rows']): ?>
  <p class="ed-empty">No redirects<?= $q !== '' ? ' match that search' : ' yet' ?>.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--cards">
      <thead><tr><th scope="col">From</th><th scope="col">To</th><th scope="col">Code</th><th scope="col">Source</th><th scope="col">Hits</th><th scope="col">Last hit</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($pg['rows'] as $r): ?>
          <tr>
            <td data-label="From" class="mono wrap-any"><?= e($r['from_path']) ?></td>
            <td data-label="To" class="mono wrap-any"><?= (int)$r['code'] === 410 ? '<span class="muted">Removed</span>' : e($r['to_path']) ?></td>
            <td data-label="Code"><?= (int)$r['code'] ?></td>
            <td data-label="Source"><?= e($sources[$r['source']] ?? $r['source']) ?></td>
            <td data-label="Hits"><?= (int)$r['hits'] ?></td>
            <td data-label="Last hit" class="ed-nowrap"><?= $r['last_hit_at'] ? e(AdminKit::ago((string)$r['last_hit_at'])) : '<span class="muted">Never</span>' ?></td>
            <td class="actions" data-label="Actions">
              <a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('redirects/edit', ['id' => $r['id']])) ?>">Edit</a>
              <?= AdminUi::postButton('redirects/delete', [], ['id' => $r['id']], 'Delete', ['class' => 'btn btn--danger btn--sm', 'confirm' => 'Delete the redirect from ' . $r['from_path'] . '?']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= AdminUi::pager($pg, 'redirects', array_filter(['q' => $q, 'source' => $source])) ?>
<?php endif; ?>
