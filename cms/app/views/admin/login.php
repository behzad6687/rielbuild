<?php
/**
 * Sign in (spec 4.1.2, 7.2), adapted from NHF. Owner: W7.
 * @var string $error
 * @var string $email
 * @var bool $signedOut
 */
defined('ICM') or exit;
?>
<div class="login">
  <div class="login__box">
    <p class="login__brand"><img src="<?= e(admin_asset('icons/icon.svg')) ?>" alt="" width="40" height="40"> <?= e(siteName()) ?></p>
    <h1>Sign in</h1>
<?php if (!empty($signedOut) && $error === ''): ?>
    <div class="alert alert--ok" role="status">You are signed out.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert--bad" role="alert" id="login-error"><?= e($error) ?></div>
<?php endif; ?>
    <form method="post" action="/admin/login" class="form">
      <?= csrfField() ?>
      <div class="field">
        <label class="field__label" for="email">Email</label>
        <input type="email" id="email" name="email" required maxlength="191" autocomplete="username" autocapitalize="none" spellcheck="false" inputmode="email" enterkeyhint="next" value="<?= e($email) ?>"<?= $error !== '' ? ' aria-describedby="login-error"' : '' ?>>
      </div>
      <div class="field">
        <label class="field__label" for="password">Password</label>
        <div class="reveal">
          <input type="password" id="password" name="password" required maxlength="200" autocomplete="current-password" enterkeyhint="go">
          <button type="button" class="reveal__btn" data-reveal="password" aria-pressed="false">Show</button>
        </div>
      </div>
      <label class="check"><input type="checkbox" name="remember" value="1"><span>Remember this device for 30 days</span></label>
      <button class="btn btn--primary btn--block" type="submit" data-busy="Signing in...">Sign in</button>
    </form>
    <p class="muted small">Forgotten your password? Ask the owner to reset it in Admin &gt; Users.</p>
  </div>
</div>
