<?php
/**
 * Sudo mode (spec 7.2): a remembered device re-enters the password (and the code)
 * before owner tools, for 15 minutes. Owner: W7.
 * @var string $next
 * @var string $error
 * @var bool $needsCode
 */
defined('ICM') or exit;
?>
<div class="page-head">
  <h1>Confirm it is you</h1>
  <p class="muted">This device was remembered, so owner tools ask for your password again. It lasts 15 minutes.</p>
</div>
<section class="card card--narrow">
<?php if ($error !== ''): ?>
  <div class="alert alert--bad" role="alert"><?= e($error) ?></div>
<?php endif; ?>
  <form method="post" action="/admin/account/sudo" class="form">
    <?= csrfField() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="field">
      <label class="field__label" for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
<?php if ($needsCode): ?>
    <div class="field">
      <label class="field__label" for="code">6-digit code (or a recovery code)</label>
      <input type="text" id="code" name="code" maxlength="20" autocomplete="one-time-code" class="input--code">
    </div>
<?php endif; ?>
    <div class="actions"><button class="btn btn--primary" type="submit">Continue</button> <a class="btn btn--line" href="/admin/">Cancel</a></div>
  </form>
</section>
