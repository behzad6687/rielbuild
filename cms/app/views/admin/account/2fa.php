<?php
/**
 * Two-step sign-in (spec 7.2): enrolment with a QR code drawn on this server,
 * 8 recovery codes shown once, turning it off (when the role allows). Owner: W7.
 * @var array $u
 * @var bool $required
 * @var string $error
 * @var ?array $codes
 * @var int $codesLeft
 * @var ?string $secret
 * @var string $qr
 */
defined('ICM') or exit;
$on = (int)$u['totp_enabled'] === 1;
?>
<div class="page-head">
  <h1>Two-step sign-in</h1>
  <p class="muted">After your password, the admin asks for a 6-digit code from an app on your phone, such as Google Authenticator, Microsoft Authenticator or 1Password.<?= $required ? ' It is required for your role.' : '' ?></p>
</div>
<?php if ($error !== ''): ?>
<div class="alert alert--bad" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($codes): ?>
<section class="card card--notice" aria-labelledby="codes-h">
  <h2 id="codes-h">Your recovery codes</h2>
  <p>Each code signs you in once if you lose your phone. Save them somewhere safe now: they are not shown again.</p>
  <ol class="codes" id="recovery-codes">
<?php foreach ($codes as $c): ?>
    <li class="mono"><?= e($c) ?></li>
<?php endforeach; ?>
  </ol>
  <p class="btn-row">
    <button type="button" class="btn btn--line" data-copy="<?= e(implode("\n", $codes)) ?>">Copy the codes</button>
    <button type="button" class="btn btn--line" data-print>Print</button>
  </p>
</section>
<?php endif; ?>

<?php if (!$on && $secret): ?>
<section class="card" aria-labelledby="setup-h">
  <h2 id="setup-h">Set it up</h2>
  <ol class="steps">
    <li>Open the authenticator app and add an account (often a "+" button).</li>
    <li>Scan this code with the app.
      <div class="qr"><?= $qr ?></div>
      <details class="details">
        <summary>Cannot scan? Type the key instead</summary>
        <p>Account: <strong><?= e($u['email']) ?></strong>. Key (time based):</p>
        <p class="mono key" data-secret><?= e(Totp::groups($secret)) ?></p>
        <button type="button" class="btn btn--line btn--sm" data-copy="<?= e($secret) ?>">Copy the key</button>
      </details>
    </li>
    <li>Type the 6-digit code the app shows.</li>
  </ol>
  <form method="post" action="/admin/account/2fa" class="form form--inline">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="confirm">
    <div class="field">
      <label class="field__label" for="code">6-digit code</label>
      <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="8" autocomplete="one-time-code" required class="input--code">
    </div>
    <button class="btn btn--primary" type="submit">Turn on two-step sign-in</button>
  </form>
  <form method="post" action="/admin/account/2fa"><?= csrfField() ?><input type="hidden" name="action" value="restart">
    <button class="linklike small" type="submit">Show a new code</button>
  </form>
</section>
<?php elseif ($on): ?>
<section class="card" aria-labelledby="on-h">
  <h2 id="on-h">Two-step sign-in is on</h2>
  <p><?= (int)$codesLeft ?> recovery <?= $codesLeft === 1 ? 'code' : 'codes' ?> left.</p>
  <form method="post" action="/admin/account/2fa" class="form form--inline">
    <?= csrfField() ?>
    <div class="field">
      <label class="field__label" for="pw">Your password</label>
      <input type="password" id="pw" name="password" required autocomplete="current-password">
    </div>
    <div class="btn-row">
      <button class="btn btn--line" type="submit" name="action" value="codes">Make new recovery codes</button>
<?php if (!$required): ?>
      <button class="btn btn--danger" type="submit" name="action" value="disable" data-confirm="Turn off two-step sign-in? Your account will be protected by the password only.">Turn off</button>
<?php endif; ?>
    </div>
  </form>
  <p class="muted small">Lost your phone? Sign in with a recovery code, or ask the owner to reset two-step sign-in in Users.</p>
</section>
<?php endif; ?>
