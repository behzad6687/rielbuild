<?php
/**
 * Invite someone, or edit an account (spec 4.2.14). Owner: W7.
 * @var ?array $u      null when inviting
 * @var ?array $old
 * @var array $errors
 * @var array $logins
 * @var int $devices
 */
defined('ICM') or exit;
$new = $u === null;
$v = $old ?? $u ?? [];
$self = !empty($self);
$roleOptions = Auth::ROLES;
if ((Auth::user()['role'] ?? '') !== Auth::MASTER) unset($roleOptions[Auth::MASTER]);   // v3: only a master gives the master role
$locked = !$new && !empty($u['locked_until']) && $u['locked_until'] > Time::nowUtc();
?>
<div class="page-head">
  <p class="crumbs"><a href="/admin/users">Users</a></p>
  <h1><?= $new ? 'Invite someone' : e($u['name']) ?></h1>
<?php if ($new): ?>
  <p class="muted">The account gets a one-time password, shown once on the next screen. Give it to the person yourself; they choose their own password at the first sign-in.</p>
<?php endif; ?>
</div>
<?php if (!empty($errors['_form'])): ?>
<div class="alert alert--bad" role="alert"><?= e($errors['_form']) ?></div>
<?php endif; ?>

<section class="card">
  <form method="post" action="<?= $new ? '/admin/users/new' : '/admin/users/edit?id=' . (int)$u['id'] ?>" class="form">
    <?= csrfField() ?>
<?php if (!$new): ?>
    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <input type="hidden" name="updated_at" value="<?= e((string)$u['updated_at']) ?>">
<?php endif; ?>
    <?php admin_partial('field', ['f' => ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'max' => 120, 'required' => true, 'autocomplete' => 'off'], 'value' => $v['name'] ?? '', 'error' => $errors['name'] ?? null]); ?>
    <?php admin_partial('field', ['f' => ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'max' => 191, 'required' => true, 'autocomplete' => 'off'], 'value' => $v['email'] ?? '', 'error' => $errors['email'] ?? null]); ?>
<?php if (!$new): ?>
    <?php admin_partial('field', ['f' => ['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'max' => 40], 'value' => $v['phone'] ?? '', 'error' => $errors['phone'] ?? null]); ?>
<?php endif; ?>
    <?php admin_partial('field', ['f' => ['key' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => $roleOptions, 'required' => true, 'disabled' => $self, 'hint' => $self ? 'You cannot change your own role.' : 'Owners can do everything, including users and settings. Two-step sign-in is required for ' . implode(' and ', array_map(fn($r) => strtolower(Auth::ROLES[$r]) . 's', Auth::twoFactorRoles())) . '.'], 'value' => $v['role'] ?? 'coach', 'error' => $errors['role'] ?? null]); ?>
<?php if ($self): ?>
    <input type="hidden" name="role" value="<?= e($u['role']) ?>">
<?php endif; ?>
<?php if (!$new): ?>
    <?php admin_partial('field', ['f' => ['key' => 'is_active', 'label' => 'Active (can sign in)', 'type' => 'bool', 'disabled' => $self], 'value' => (int)($v['is_active'] ?? 1), 'error' => $errors['is_active'] ?? null]); ?>
<?php if ($self): ?>
    <input type="hidden" name="is_active" value="1">
<?php endif; ?>
<?php endif; ?>
    <div class="actions actions--sticky">
      <button class="btn btn--primary" type="submit"><?= $new ? 'Create the account' : 'Save' ?></button>
      <a class="btn btn--line" href="/admin/users">Cancel</a>
    </div>
  </form>
</section>

<?php if (!$new): ?>
<section class="card" aria-labelledby="acc-h">
  <h2 id="acc-h">Sign-in</h2>
  <dl class="kv">
    <dt>Two-step sign-in</dt><dd><?= (int)$u['totp_enabled'] === 1 ? 'On' : 'Off' ?></dd>
    <dt>Remembered devices</dt><dd><?= (int)$devices ?></dd>
    <dt>Last sign-in</dt><dd><?= $u['last_login_at'] ? e(Time::long((string)$u['last_login_at'], true)) : 'Never' ?></dd>
<?php if ($locked): ?>
    <dt>Status</dt><dd><span class="tag tag--bad">Paused until <?= e(Time::long((string)$u['locked_until'], true)) ?></span></dd>
<?php endif; ?>
  </dl>
  <div class="btn-row">
    <form method="post" action="/admin/users/password" data-confirm="Reset the password of <?= e($u['name']) ?>? They are signed out everywhere and get a one-time password."><?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--line" type="submit">Reset password</button></form>
    <form method="post" action="/admin/users/signout" data-confirm="Sign <?= e($u['name']) ?> out on every device?"><?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--line" type="submit">Sign out all devices</button></form>
<?php if ((int)$u['totp_enabled'] === 1): ?>
    <form method="post" action="/admin/users/twofa" data-confirm="Reset two-step sign-in for <?= e($u['name']) ?>? Use this when they lost their phone."><?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--line" type="submit">Reset two-step sign-in</button></form>
<?php endif; ?>
<?php if ($locked): ?>
    <form method="post" action="/admin/users/clear-lock"><?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--line" type="submit">Let them sign in again</button></form>
<?php endif; ?>
  </div>
</section>

<section class="card" aria-labelledby="hist-h">
  <h2 id="hist-h">The last 20 sign-ins</h2>
<?php if (!$logins): ?>
  <p class="muted">None recorded.</p>
<?php else: ?>
  <ul class="rows">
<?php foreach ($logins as $l): ?>
    <li><span class="tag<?= $l['action'] === 'login_fail' ? ' tag--bad' : '' ?>"><?= e(['login' => 'Signed in', 'login_fail' => 'Failed', 'logout' => 'Signed out'][$l['action']] ?? $l['action']) ?></span> <span><?= e($l['summary']) ?></span> <span class="muted small"><?= e(Time::long((string)$l['created_at'], true)) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>
