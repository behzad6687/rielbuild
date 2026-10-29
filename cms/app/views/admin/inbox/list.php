<?php
/**
 * Inbox list (spec 4.2.9). Vars: $tabs, $type, $show, $q, $pg, $counts, $statuses, $canExport, $canSettings, $me.
 */
defined('ICM') or exit;

$actions = '';
if ($canSettings && !in_array($type, ['spam', 'newsletter'], true)) {
    $actions .= AdminUi::link('Notification settings', AdminUi::url('inbox/settings', ['type' => $type]));
}
echo AdminUi::head('Inbox', 'Estimate requests from every form on the site, newest first. Each one is also emailed to the addresses in Settings > Form recipients.', $actions);

$chips = [];
foreach ($tabs as $k => $label) {
    $chips[] = ['label' => $label, 'url' => AdminUi::url('inbox', ['type' => $k]), 'on' => $k === $type,
        'count' => ($counts[$k] ?? 0) ?: null];
}
echo AdminUi::tabs($chips, 'Forms');
?>
<form class="form form--inline ed-filters" method="get" action="<?= e(AdminUi::url('inbox')) ?>" role="search">
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <?php if ($type !== 'spam' && $type !== 'newsletter'): ?>
    <?= AdminUi::select('show', 'Show', ['open' => 'Open (new and in progress)', 'handled' => 'Handled', 'all' => 'Everything'], $show, ['attrs' => ['data-autosubmit' => true]]) ?>
  <?php endif; ?>
  <?= AdminUi::input('q', 'Name, email, phone or subject', $q, ['type' => 'search', 'max' => 100]) ?>
  <div class="field"><button class="btn btn--line" type="submit">Search</button></div>
</form>

<?php if ($type === 'newsletter'): ?>
  <p class="muted small">A read-only log of newsletter signups and unsubscribe requests. Manage subscribers in News and newsletter.</p>
<?php endif; ?>

<?php if (!$pg['rows']): ?>
  <p class="ed-empty"><?= $show === 'open' && $type !== 'spam' ? 'Nothing waiting. Everything here is handled.' : 'Nothing here.' ?></p>
<?php else: ?>
  <ul class="ed-cards">
    <?php foreach ($pg['rows'] as $r): $mine = (int)($r['assigned_to'] ?? 0) === (int)($me['id'] ?? -1); ?>
      <li class="card ed-card<?= $r['status'] === 'new' ? ' is-new' : '' ?>">
        <div class="ed-card__head">
          <a class="ed-card__title" href="<?= e(AdminUi::url('inbox/view', ['id' => $r['id']])) ?>"><?= e($r['name'] ?: ($r['email'] ?: 'No name')) ?></a>
          <?= AdminKit::chip((string)$r['status']) ?>
          <?php if ($type === 'spam' || $type === 'newsletter'): ?><span class="tag"><?= e(ucfirst(str_replace('_', ' ', (string)$r['form_type']))) ?></span><?php endif; ?>
        </div>
        <p class="ed-card__summary"><?= e($r['summary']) ?></p>
        <p class="ed-card__meta muted small"><?= e(AdminKit::stamp((string)$r['created_at'])) ?><?= $r['assigned_name'] ? ' · With ' . e($r['assigned_name']) : '' ?><?= (string)$r['notified_ok'] === '0' ? ' · <strong>Email notification failed</strong>' : '' ?></p>
        <div class="btn-row ed-card__actions">
          <a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('inbox/view', ['id' => $r['id']])) ?>">Open</a>
          <?php if (admin_can('inbox.edit') && !in_array($r['form_type'], ['newsletter', 'unsubscribe'], true)): ?>
            <?php if (!in_array($r['status'], ['handled', 'archived', 'spam'], true)): ?>
              <?= AdminUi::postButton('inbox/handled', [], ['id' => $r['id']], 'Mark handled', ['class' => 'btn btn--line btn--sm']) ?>
            <?php endif; ?>
            <?php if (!$mine): ?>
              <?= AdminUi::postButton('inbox/assign', [], ['id' => $r['id']], 'Assign to me', ['class' => 'btn btn--line btn--sm']) ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?= AdminUi::pager($pg, 'inbox', array_filter(['type' => $type, 'show' => $show, 'q' => $q])) ?>
<?php endif; ?>

<?php if ($canExport): ?>
  <details class="details card">
    <summary>Export <?= e(strtolower($tabs[$type] ?? $type)) ?> as a spreadsheet (CSV)</summary>
    <form class="form form--inline" method="get" action="<?= e(AdminUi::url('inbox/export')) ?>">
      <input type="hidden" name="type" value="<?= e($type === 'newsletter' ? 'newsletter' : $type) ?>">
      <?= AdminUi::input('from', 'From', '', ['type' => 'date']) ?>
      <?= AdminUi::input('to', 'To', '', ['type' => 'date']) ?>
      <div class="field"><button class="btn btn--line" type="submit">Download CSV</button></div>
    </form>
    <p class="field__hint">Every field gets a column. The file opens in Excel with accents intact.</p>
  </details>
<?php endif; ?>
