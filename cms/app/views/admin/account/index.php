<?php
/**
 * My account (spec 4.2.14). Owner: W7.
 * @var array $u
 * @var ?array $old
 * @var array $errors
 * @var array $devices
 * @var ?string $current
 * @var array $logins
 * @var int $codesLeft
 */
defined('ICM') or exit;
$v = $old ?? $u;
?>
<div class="page-head">
  <h1>My account</h1>
  <p class="muted"><?= e($u['email']) ?>, <?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></p>
</div>

<div class="grid grid--2">
  <section class="card" aria-labelledby="me-h">
    <h2 id="me-h">Your details</h2>
<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert--bad" role="alert"><?= e($errors['_form']) ?></div>
<?php endif; ?>
    <form method="post" action="/admin/account" class="form">
      <?= csrfField() ?>
      <input type="hidden" name="updated_at" value="<?= e((string)$u['updated_at']) ?>">
      <?php admin_partial('field', ['f' => ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'max' => 120, 'required' => true, 'autocomplete' => 'name'], 'value' => $v['name'] ?? '', 'error' => $errors['name'] ?? null]); ?>
      <?php admin_partial('field', ['f' => ['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'max' => 40, 'autocomplete' => 'tel', 'hint' => 'Optional. Only the owner sees it.'], 'value' => $v['phone'] ?? '', 'error' => $errors['phone'] ?? null]); ?>
      <div class="actions"><button class="btn btn--primary" type="submit">Save</button></div>
    </form>
  </section>

  <section class="card" aria-labelledby="sec-h">
    <h2 id="sec-h">Password and sign-in</h2>
    <p><a class="btn btn--line" href="/admin/account/password">Change password</a></p>
    <p>
      Two-step sign-in: <strong><?= (int)$u['totp_enabled'] === 1 ? 'on' : 'off' ?></strong>.
<?php if ((int)$u['totp_enabled'] === 1): ?>
      <span class="muted"><?= (int)$codesLeft ?> recovery <?= $codesLeft === 1 ? 'code' : 'codes' ?> left.</span>
<?php endif; ?>
    </p>
    <p><a class="btn btn--line" href="/admin/account/2fa"><?= (int)$u['totp_enabled'] === 1 ? 'Manage two-step sign-in' : 'Set up two-step sign-in' ?></a></p>
  </section>
</div>

<section class="card" aria-labelledby="dev-h">
  <h2 id="dev-h">Remembered devices</h2>
  <p class="muted">Devices where you ticked "Remember this device". They skip the password for 30 days.</p>
<?php if (!$devices): ?>
  <p>No remembered devices.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table table--cards">
    <thead><tr><th scope="col">Device</th><th scope="col">Last used</th><th scope="col">Expires</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
    <tbody>
<?php foreach ($devices as $d): ?>
      <tr>
        <td data-label="Device"><?= e(mb_strimwidth((string)($d['user_agent'] ?: 'Unknown browser'), 0, 90, '...')) ?><?= $current !== null && hash_equals((string)$d['selector'], $current) ? ' <span class="tag tag--ok">This device</span>' : '' ?></td>
        <td data-label="Last used"><?= e(Time::long((string)($d['last_used_at'] ?: $d['created_at']), true)) ?></td>
        <td data-label="Expires"><?= e(Time::long((string)$d['expires_at'])) ?></td>
        <td data-label="Action">
          <form method="post" action="/admin/account/devices" data-confirm="Forget this device? It will ask for the password next time."><?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn--line btn--sm" type="submit">Forget</button>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <form method="post" action="/admin/account/devices" data-confirm="Sign out every other device and forget every remembered one? This device stays signed in.">
    <?= csrfField() ?><input type="hidden" name="action" value="others">
    <button class="btn btn--line" type="submit">Sign out everywhere else</button>
  </form>
</section>

<section class="card" aria-labelledby="log-h">
  <h2 id="log-h">Recent sign-ins</h2>
<?php if (!$logins): ?>
  <p class="muted">None recorded yet.</p>
<?php else: ?>
  <ul class="rows">
<?php foreach ($logins as $l): ?>
    <li><span><?= e($l['summary']) ?></span> <span class="muted small"><?= e(Time::long((string)$l['created_at'], true)) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
