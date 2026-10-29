<?php
/**
 * One inbox message (spec 4.2.9). Vars: $r, $rows, $files, $notes, $users, $statuses, $readOnly, $canDelete, $label.
 */
defined('ICM') or exit;

$subject = 'Re: ' . ($r['subject'] ?: $label);
$back = AdminUi::url('inbox', ['type' => $r['status'] === 'spam' ? 'spam' : ($r['form_type'] === 'unsubscribe' ? 'newsletter' : $r['form_type'])]);
echo AdminUi::head((string)($r['name'] ?: ($r['email'] ?: 'Message')), $label . ', received ' . Time::long((string)$r['created_at'], true) . '.', AdminUi::link('Back to the inbox', $back));
?>
<div class="ed-split">
  <div class="ed-main">
    <section class="card" aria-labelledby="sent-h">
      <h2 id="sent-h">What they sent</h2>
      <?php if ($r['email'] || $r['phone']): ?>
        <div class="btn-row">
          <?php if ($r['phone']): ?><a class="btn btn--line btn--sm" href="<?= e(telHref((string)$r['phone'])) ?>">Call <?= e($r['phone']) ?></a><?php endif; ?>
          <?php if ($r['email']): ?><a class="btn btn--line btn--sm" href="mailto:<?= e($r['email']) ?>?subject=<?= e(rawurlencode($subject)) ?>">Reply by email</a><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (!$rows): ?>
        <p class="muted">No fields were stored.</p>
      <?php else: ?>
        <dl class="kv wrap-any">
          <?php foreach ($rows as [$l, $v]): ?>
            <dt><?= e($l) ?></dt>
            <dd><?php
                if (filter_var($v, FILTER_VALIDATE_EMAIL)) echo '<a href="mailto:' . e($v) . '">' . e($v) . '</a>';
                elseif (preg_match('/^\+?[\d\s().-]{10,20}$/', $v)) echo '<a href="' . e(telHref($v)) . '">' . e($v) . '</a>';
                else echo nl2br(e($v)); ?></dd>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </section>

    <?php if ($files): ?>
      <section class="card" aria-labelledby="files-h">
        <h2 id="files-h">Files</h2>
        <ul class="plain-list">
          <?php foreach ($files as $f): ?>
            <li><a href="<?= e(AdminUi::url('inbox/file', ['id' => $f['id']])) ?>"><?= e($f['original_name']) ?></a>
              <span class="muted small">(<?= e(number_format(((int)$f['bytes']) / 1024, 0)) ?> KB, downloaded through the admin only)</span></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <section class="card" id="notes" aria-labelledby="notes-h">
      <h2 id="notes-h">Notes</h2>
      <?php if (!$notes): ?><p class="muted">No notes yet.</p><?php endif; ?>
      <ol class="ed-notes">
        <?php foreach ($notes as $n): ?>
          <li><p class="ed-notes__meta muted small"><?= e($n['author'] ?: 'Someone') ?>, <?= e(AdminKit::stamp((string)$n['created_at'])) ?></p><p><?= nl2br(e($n['note'])) ?></p></li>
        <?php endforeach; ?>
      </ol>
      <?php if (!$readOnly && admin_can('inbox.edit')): ?>
        <form method="post" action="<?= e(AdminUi::url('inbox/note')) ?>">
          <?= AdminUi::csrf() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?= AdminUi::textarea('note', 'Add a note', '', ['rows' => 3, 'max' => 4000, 'hint' => 'Notes are kept with the message and cannot be edited later.']) ?>
          <button class="btn btn--line" type="submit">Add note</button>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <aside class="ed-side">
    <?php if (!$readOnly && admin_can('inbox.edit')): ?>
      <section class="card" aria-labelledby="st-h">
        <h2 id="st-h">Status</h2>
        <form method="post" action="<?= e(AdminUi::url('inbox/update')) ?>">
          <?= AdminUi::csrf() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?= AdminUi::select('status', 'Status', $statuses, $r['status']) ?>
          <?= AdminUi::select('assigned_to', 'With', $users, $r['assigned_to'] ?? '', ['blank' => 'Nobody yet']) ?>
          <button class="btn btn--primary" type="submit">Save</button>
        </form>
      </section>
    <?php else: ?>
      <section class="card"><p><?= AdminKit::chip((string)$r['status']) ?></p></section>
    <?php endif; ?>
    <section class="card" aria-labelledby="det-h">
      <h2 id="det-h">Details</h2>
      <dl class="kv small">
        <dt>Received</dt><dd><?= e(Time::long((string)$r['created_at'], true)) ?></dd>
        <?php if ($r['source_path']): ?><dt>Sent from</dt><dd class="mono wrap-any"><?= e($r['source_path']) ?></dd><?php endif; ?>
        <dt>Email to staff</dt><dd><?= $r['notified_ok'] === null ? 'Not needed' : ((int)$r['notified_ok'] === 1 ? 'Sent' : '<strong>Failed</strong>. Check Settings > Email.') ?></dd>
        <?php if ((int)$r['spam_score'] > 0): ?><dt>Spam score</dt><dd><?= (int)$r['spam_score'] ?><?= $r['spam_reason'] ? ': ' . e($r['spam_reason']) : '' ?></dd><?php endif; ?>
        <?php if ($r['handled_at']): ?><dt>Handled</dt><dd><?= e(Time::long((string)$r['handled_at'], true)) ?></dd><?php endif; ?>
      </dl>
    </section>
    <?php if ($canDelete): ?>
      <div class="ed-danger">
        <?= AdminUi::postButton('inbox/delete', [], ['id' => $r['id']], 'Delete this message', ['class' => 'btn btn--danger btn--sm',
            'confirm' => 'Delete this message and its files for good? This cannot be undone.']) ?>
      </div>
    <?php endif; ?>
  </aside>
</div>
