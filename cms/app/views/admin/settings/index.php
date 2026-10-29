<?php
/**
 * Settings, one section at a time (spec 4.2.14). Owner: W7.
 * @var array $sections
 * @var string $key
 * @var array $section
 * @var array $values
 * @var array $errors
 * @var array $secrets  keys with a stored secret
 */
defined('ICM') or exit;
?>
<div class="page-head">
  <h1>Settings</h1>
</div>
<nav class="chips" aria-label="Settings sections">
<?php foreach ($sections as $k => $s): ?>
  <a class="chip<?= $k === $key ? ' is-on' : '' ?>" href="<?= e(admin_url('settings', ['section' => $k])) ?>"<?= $k === $key ? ' aria-current="page"' : '' ?>><?= e($s['label']) ?></a>
<?php endforeach; ?>
</nav>

<section class="card" aria-labelledby="sec-h">
  <h2 id="sec-h"><?= e($section['label']) ?></h2>
  <p class="muted"><?= e($section['intro']) ?></p>
<?php if ($errors): ?>
  <div class="alert alert--bad" role="alert">
    <p>Please fix <?= count($errors) === 1 ? 'this' : 'these' ?> first:</p>
    <ul><?php foreach ($errors as $k => $msg): ?><li><a href="#f-<?= e(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$k))) ?>"><?= e($msg) ?></a></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if (!$section['fields']): ?>
  <p><?= $key === 'recipients' ? 'The form list appears here once the site is seeded.' : 'These texts appear here once the site is seeded.' ?></p>
<?php else: ?>
  <form method="post" action="/admin/settings/save" class="form" data-dirty-guard>
    <?= csrfField() ?>
    <input type="hidden" name="section" value="<?= e($key) ?>">
<?php foreach ($section['fields'] as $f): ?>
    <?php admin_partial('field', [
        'f'         => $f,
        'name'      => 's[' . $f['key'] . ']',
        'value'     => $values[$f['key']] ?? '',
        'error'     => $errors[$f['key']] ?? null,
        'hasSecret' => isset($secrets[$f['key']]),
        'clearName' => 'c[' . $f['key'] . ']',
    ]); ?>
<?php endforeach; ?>
    <div class="actions actions--sticky">
      <button class="btn btn--primary" type="submit">Save</button>
    </div>
  </form>
<?php endif; ?>
</section>

<?php if ($key === 'email_tx'): $passed = (string)Db::setting('mail.tx.test_passed_at', ''); ?>
<section class="card" aria-labelledby="txt-h">
  <h2 id="txt-h">Test</h2>
  <p class="muted">Save first, then test. <?= $passed !== '' ? 'Last connection test passed ' . e(Time::long($passed, true)) . '.' : 'No connection test has passed yet.' ?></p>
  <div class="btn-row">
    <form method="post" action="/admin/settings/mail-test"><?= csrfField() ?><input type="hidden" name="stream" value="tx"><input type="hidden" name="mode" value="connect">
      <button class="btn btn--line" type="submit" data-busy="Testing...">Test the connection</button></form>
    <form method="post" action="/admin/settings/mail-test"><?= csrfField() ?><input type="hidden" name="stream" value="tx"><input type="hidden" name="mode" value="send">
      <button class="btn btn--line" type="submit" data-busy="Sending...">Send me a test email</button></form>
  </div>
</section>
<?php endif; ?>

<?php if ($key === 'email_news'):
    $passed = (string)Db::setting('newsletter.test_passed_at', '');
    $pub = (string)Db::setting('newsletter.dkim_public', '');
    $sel = (string)Db::setting('newsletter.dkim_selector', 'icm1');
    $from = (string)Db::setting('newsletter.from_email', 'news@icmtennis.com');
    $domain = strtolower((string)substr((string)strrchr($from, '@'), 1)) ?: 'icmtennis.com';
    $verified = (string)Db::setting('newsletter.dkim_verified_at', '');
    $hook = (string)(Db::secret('newsletter.webhook_secret') ?? '');
?>
<section class="card" aria-labelledby="nt-h">
  <h2 id="nt-h">Connection test</h2>
  <p class="muted">Save first, then test. The readiness checklist needs a pass in the last 7 days. <?= $passed !== '' ? 'Last pass: ' . e(Time::long($passed, true)) . '.' : 'No pass yet.' ?></p>
  <form method="post" action="/admin/settings/mail-test"><?= csrfField() ?><input type="hidden" name="stream" value="news"><input type="hidden" name="mode" value="connect">
    <button class="btn btn--line" type="submit" data-busy="Testing...">Test the connection</button></form>
</section>

<section class="card" id="dkim" aria-labelledby="dk-h">
  <h2 id="dk-h">DKIM signature</h2>
  <p class="muted">DKIM proves the newsletter really comes from <?= e($domain) ?> and covers the unsubscribe headers Gmail and Yahoo check.</p>
<?php if ($pub === ''): ?>
  <p>No key yet.</p>
<?php else: ?>
  <p>Add this TXT record at your DNS provider<?= $verified !== '' ? ' (checked ' . e(Time::long($verified, true)) . ')' : '' ?>:</p>
  <dl class="kv">
    <dt>Type</dt><dd class="mono">TXT</dd>
    <dt>Name</dt><dd class="mono"><?= e($sel . '._domainkey') ?></dd>
    <dt>Value</dt><dd class="mono wrap-any"><?= e('v=DKIM1; k=rsa; p=' . $pub) ?></dd>
  </dl>
  <p class="btn-row"><button type="button" class="btn btn--line btn--sm" data-copy="<?= e('v=DKIM1; k=rsa; p=' . $pub) ?>">Copy the value</button></p>
<?php endif; ?>
  <div class="btn-row">
    <form method="post" action="/admin/settings/dkim"<?= $pub !== '' ? ' data-confirm="Make a new key? The DNS record must then be replaced, or signed mail will fail."' : '' ?>><?= csrfField() ?><input type="hidden" name="action" value="generate">
      <button class="btn btn--line" type="submit"><?= $pub === '' ? 'Make a key' : 'Make a new key' ?></button></form>
<?php if ($pub !== ''): ?>
    <form method="post" action="/admin/settings/dkim"><?= csrfField() ?><input type="hidden" name="action" value="verify">
      <button class="btn btn--line" type="submit" data-busy="Checking...">Check DNS</button></form>
<?php endif; ?>
  </div>
</section>

<section class="card" id="webhook" aria-labelledby="wh-h">
  <h2 id="wh-h">Bounce and complaint webhook</h2>
<?php if ($hook === ''): ?>
  <p class="muted">Amazon SES and SMTP2GO report bounces to a secret address on this site. Make one, then paste it in the relay's console.</p>
<?php else: ?>
  <dl class="kv">
    <dt>Amazon SES (SNS)</dt><dd class="mono wrap-any"><?= e(url('/hooks/ses/' . $hook . '/')) ?></dd>
    <dt>SMTP2GO</dt><dd class="mono wrap-any"><?= e(url('/hooks/smtp2go/' . $hook . '/')) ?></dd>
  </dl>
<?php endif; ?>
  <form method="post" action="/admin/settings/webhook"<?= $hook !== '' ? ' data-confirm="Make a new webhook address? The old one stops working."' : '' ?>><?= csrfField() ?>
    <button class="btn btn--line" type="submit"><?= $hook === '' ? 'Make the webhook address' : 'Make a new address' ?></button></form>
</section>
<?php endif; ?>

<?php if ($key === 'site'): ?>
<section class="card" aria-labelledby="addr-h">
  <h2 id="addr-h">Site address</h2>
  <p class="muted">Every link, canonical and email uses this address. Change it only at cutover (DEPLOY.md, step C4): any other address then redirects here.</p>
  <form method="post" action="/admin/settings/site-address" class="form form--inline" data-confirm="Change the site address? Visitors on any other address are sent to the new one.">
    <?= csrfField() ?>
    <div class="field">
      <label class="field__label" for="base_url">Address</label>
      <input type="url" id="base_url" name="base_url" value="<?= e((string)cfg('base_url')) ?>" required maxlength="191" inputmode="url" autocapitalize="none" spellcheck="false">
    </div>
    <button class="btn btn--line" type="submit">Change the address</button>
  </form>
</section>
<section class="card" aria-labelledby="prev-h">
  <h2 id="prev-h">Preview the site</h2>
  <p class="muted">Gives this browser a 12-hour pass past the "Back soon" page, so you can check the site while maintenance mode is on.</p>
  <form method="post" action="/admin/settings/preview-site"><?= csrfField() ?><button class="btn btn--line" type="submit">Preview the site</button></form>
</section>
<?php endif; ?>

<?php if ($key === 'security' && !Router::isHttps()): ?>
<div class="alert alert--warn" role="status">This page did not load over HTTPS. Do not turn on HSTS until HTTPS works on the final address.</div>
<?php endif; ?>
