<?php
/**
 * Users list (spec 4.2.14). Owner: W7.
 * @var array $rows
 */
defined('ICM') or exit;
$now = Time::nowUtc();
?>
<div class="page-head page-head--row">
  <h1>Users</h1>
  <a class="btn btn--primary" href="/admin/users/new">Invite someone</a>
</div>
<p class="muted">Owners manage users, settings and the system. Managers run registration, rankings, the inbox and the newsletter. Coaches enter scores and handle ranking sign-ups and certificates. Editors look after posts, pages and media.</p>
<div class="table-wrap">
<table class="table table--cards">
  <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Two-step</th><th scope="col">Last sign-in</th><th scope="col">Status</th></tr></thead>
  <tbody>
<?php foreach ($rows as $r): $locked = !empty($r['locked_until']) && $r['locked_until'] > $now; ?>
    <tr<?= (int)$r['is_active'] === 1 ? '' : ' class="is-muted"' ?>>
      <td data-label="Name"><a href="/admin/users/edit?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
      <td data-label="Email"><?= e($r['email']) ?></td>
      <td data-label="Role"><?= e(Auth::ROLES[$r['role']] ?? $r['role']) ?></td>
      <td data-label="Two-step"><?= (int)$r['totp_enabled'] === 1 ? 'On' : (Auth::requires2fa((string)$r['role']) ? '<span class="tag tag--warn">Not set up</span>' : 'Off') ?></td>
      <td data-label="Last sign-in"><?= $r['last_login_at'] ? e(Time::long((string)$r['last_login_at'], true)) : '<span class="muted">Never</span>' ?></td>
      <td data-label="Status">
<?php if ((int)$r['is_active'] !== 1): ?><span class="tag">Deactivated</span>
<?php elseif ($locked): ?><span class="tag tag--bad">Paused after failed sign-ins</span>
<?php elseif ((int)$r['must_change_password'] === 1): ?><span class="tag tag--warn">Waiting for first sign-in</span>
<?php else: ?><span class="tag tag--ok">Active</span><?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
