<?php
/**
 * Notification settings for one form (spec 4.2.9, form_types). Vars: $type, $values, $errors.
 */
defined('ICM') or exit;

$v = $values;
echo AdminUi::head('Form settings: ' . $v['label'], 'Who gets the email when this form is sent, and how long messages are kept.', AdminUi::link('Back to the inbox', AdminUi::url('inbox', ['type' => $type])));
echo AdminUi::errors($errors);
?>
<form method="post" class="form" action="<?= e(AdminUi::url('inbox/settings')) ?>">
  <?= AdminUi::csrf() ?>
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="card">
    <?= AdminUi::input('label', 'Name in the inbox', $v['label'], ['max' => 80]) ?>
    <?= AdminUi::check('is_enabled', 'Show this form on the site', (int)$v['is_enabled'] === 1, ['hint' => 'Untick to take the form off every page.']) ?>
    <?= AdminUi::textarea('recipients', 'Send notifications to', $v['recipients'], ['rows' => 3, 'max' => 600,
        'hint' => 'Email addresses separated by commas. Replies go straight to the person who sent the form.']) ?>
    <?= AdminUi::input('retention_days', 'Keep messages for (days)', $v['retention_days'], ['type' => 'number', 'min' => 30, 'maxv' => 3650, 'class' => 'field--small',
        'hint' => 'Older messages and their files are deleted automatically at night.']) ?>
  </div>
  <div class="card">
    <h2>Automatic reply</h2>
    <?= AdminUi::check('send_autoreply', 'Send an automatic reply', (int)$v['send_autoreply'] === 1, ['hint' => 'Transactional only: a thank-you, never news or offers.']) ?>
    <?= AdminUi::input('autoreply_subject', 'Subject', $v['autoreply_subject'], ['max' => 200]) ?>
    <?= AdminUi::textarea('autoreply_body', 'Message', $v['autoreply_body'], ['rows' => 6, 'max' => 5000]) ?>
  </div>
  <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Save settings</button>') ?>
</form>
<div class="card">
  <h2>Check the email</h2>
  <p class="muted">Sends a test notification to the addresses above. Save your changes first.</p>
  <?= AdminUi::postButton('inbox/test-notify', [], ['type' => $type], 'Send a test notification', ['class' => 'btn btn--line']) ?>
</div>
