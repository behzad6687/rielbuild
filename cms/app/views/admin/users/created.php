<?php
/**
 * The one-time password, shown once (spec 4.2.14). Owner: W7.
 * @var array $once  id, name, email, password, why (invite | reset)
 */
defined('ICM') or exit;
?>
<div class="page-head">
  <p class="crumbs"><a href="/admin/users">Users</a></p>
  <h1><?= $once['why'] === 'invite' ? 'Account created' : 'Password reset' ?></h1>
</div>
<section class="card card--notice">
  <p>Give these details to <strong><?= e($once['name']) ?></strong> yourself, by phone or in person. The password is shown only now.</p>
  <dl class="kv">
    <dt>Sign-in address</dt><dd class="mono"><?= e(url('/admin/')) ?></dd>
    <dt>Email</dt><dd class="mono"><?= e($once['email']) ?></dd>
    <dt>One-time password</dt><dd class="mono key"><?= e($once['password']) ?></dd>
  </dl>
  <p class="btn-row"><button type="button" class="btn btn--line" data-copy="<?= e($once['password']) ?>">Copy the password</button></p>
  <p class="muted">At the first sign-in they choose their own password<?= Auth::requires2fa((string)(Db::value('SELECT role FROM admin_users WHERE id = ?', [(int)$once['id']]) ?? '')) ? ' and set up two-step sign-in' : '' ?>.</p>
  <p><a class="btn btn--primary" href="/admin/users/edit?id=<?= (int)$once['id'] ?>">Done</a></p>
</section>
