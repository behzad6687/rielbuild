<?php
/**
 * Sign in, second step: the 6-digit code or a recovery code (spec 7.2). Owner: W7.
 * @var string $error
 * @var string $name
 */
defined('ICM') or exit;
?>
<div class="login">
  <div class="login__box">
    <p class="login__brand"><img src="<?= e(admin_asset('icons/icon.svg')) ?>" alt="" width="40" height="40"> <?= e(siteName()) ?></p>
    <h1>Enter your code</h1>
    <p class="muted">Hi <?= e($name) ?>. Open your authenticator app and type the 6-digit code for <?= e(siteName()) ?>.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert--bad" role="alert" id="code-error"><?= e($error) ?></div>
<?php endif; ?>
    <form method="post" action="/admin/login/2fa" class="form">
      <?= csrfField() ?>
      <div class="field">
        <label class="field__label" for="code">6-digit code</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="8" autocomplete="one-time-code" enterkeyhint="go" class="input--code"<?= $error !== '' ? ' aria-describedby="code-error"' : '' ?>>
      </div>
      <details class="details">
        <summary>Lost your phone? Use a recovery code</summary>
        <div class="field">
          <label class="field__label" for="recovery">Recovery code</label>
          <input type="text" id="recovery" name="recovery" maxlength="20" autocomplete="off" autocapitalize="none" spellcheck="false">
          <span class="field__hint">One of the 8 codes you saved when you set up two-step sign-in. Each works once.</span>
        </div>
      </details>
      <button class="btn btn--primary btn--block" type="submit" data-busy="Checking...">Continue</button>
    </form>
    <p class="muted small"><a href="/admin/login">Start again</a></p>
  </div>
</div>
