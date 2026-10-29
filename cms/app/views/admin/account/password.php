<?php
/**
 * Change password (spec 7.2: 12+ characters, not common, not the email). Owner: W7.
 * @var array $errors
 * @var bool $forced
 */
defined('ICM') or exit;
?>
<div class="page-head">
  <h1>Change password</h1>
<?php if ($forced): ?>
  <p>You signed in with a one-time password. Choose your own password to continue.</p>
<?php endif; ?>
</div>
<section class="card card--narrow">
  <form method="post" action="/admin/account/password" class="form">
    <?= csrfField() ?>
    <?php admin_partial('field', ['f' => ['key' => 'current', 'label' => $forced ? 'One-time password' : 'Current password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password'], 'value' => '', 'error' => $errors['current'] ?? null]); ?>
    <?php admin_partial('field', ['f' => ['key' => 'new', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'At least 12 characters. A short sentence you will remember works well.'], 'value' => '', 'error' => $errors['new'] ?? null]); ?>
    <?php admin_partial('field', ['f' => ['key' => 'confirm', 'label' => 'New password again', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password'], 'value' => '', 'error' => $errors['confirm'] ?? null]); ?>
    <p class="muted small">Changing the password signs out your other devices.</p>
    <div class="actions"><button class="btn btn--primary" type="submit">Change password</button></div>
  </form>
</section>
