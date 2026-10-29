<?php
/**
 * 404 log (spec 4.2.12). Vars: $pg, $days, $ignored, $nRedirects.
 */
defined('ICM') or exit;

echo AdminUi::head('404 log', 'Addresses visitors asked for that do not exist. Redirect the useful ones; ignore the noise.');
echo AdminUi::tabs([
    ['label' => 'Redirects', 'url' => AdminUi::url('redirects'), 'count' => $nRedirects],
    ['label' => '404 log', 'url' => AdminUi::url('redirects/404'), 'on' => true, 'count' => (int)$pg['total']],
], 'Redirects and 404s');
?>
<form class="form form--inline ed-filters" method="get" action="<?= e(AdminUi::url('redirects/404')) ?>">
  <?= AdminUi::select('days', 'Seen in', ['7' => 'The last 7 days', '30' => 'The last 30 days', 'all' => 'Any time'], $days, ['attrs' => ['data-autosubmit' => true]]) ?>
  <?= AdminUi::check('ignored', 'Show ignored', (bool)$ignored, ['attrs' => ['data-autosubmit' => true]]) ?>
  <noscript><div class="field"><button class="btn btn--line" type="submit">Show</button></div></noscript>
</form>
<?php if (!$pg['rows']): ?>
  <p class="ed-empty">No missing addresses in this period.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--cards">
      <thead><tr><th scope="col">Address</th><th scope="col">Hits</th><th scope="col">Last seen</th><th scope="col">Came from</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($pg['rows'] as $r): ?>
          <tr>
            <td data-label="Address" class="mono wrap-any"><?= e($r['path']) ?></td>
            <td data-label="Hits"><?= (int)$r['hits'] ?></td>
            <td data-label="Last seen" class="ed-nowrap"><?= e(AdminKit::ago((string)$r['last_seen'])) ?></td>
            <td data-label="Came from" class="wrap-any small"><?= $r['last_referrer'] ? e(mb_strimwidth((string)$r['last_referrer'], 0, 80, '...')) : '<span class="muted">Direct or unknown</span>' ?></td>
            <td class="actions" data-label="Actions">
              <?php if (mb_strlen((string)$r['path']) <= 191): ?>
                <a class="btn btn--primary btn--sm" href="<?= e(AdminUi::url('redirects/edit', ['from' => $r['path'], 'from404' => $r['path_hash']])) ?>">Redirect to...</a>
              <?php endif; ?>
              <?= AdminUi::postButton('redirects/404-ignore', [], ['hash' => $r['path_hash']] + ($ignored ? ['undo' => 1] : []), $ignored ? 'Show again' : 'Ignore', ['class' => 'btn btn--line btn--sm']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= AdminUi::pager($pg, 'redirects/404', array_filter(['days' => $days, 'ignored' => $ignored ?: null])) ?>
<?php endif; ?>
