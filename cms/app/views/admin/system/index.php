<?php
/**
 * System (spec 4.2.14). Owner: W7.
 */
defined('ICM') or exit;
$ok = static fn(bool $b): string => $b ? '<span class="tag tag--ok">OK</span>' : '<span class="tag tag--bad">Needs attention</span>';
$cronAge = $cron['last'] !== '' ? Time::ts() - (int)strtotime($cron['last'] . ' UTC') : null;
?>
<div class="page-head">
  <h1>System</h1>
  <p class="muted">Build <?= e($version) ?>. <?= e($now) ?>.</p>
</div>
<nav class="chips" aria-label="On this page">
  <a class="chip" href="#health">Health</a><a class="chip" href="#cron">Cron</a><a class="chip" href="#db">Database update</a>
  <a class="chip" href="#backups">Backups</a><a class="chip" href="#tools">Tools</a><a class="chip" href="#logs">Logs</a>
</nav>

<section class="card" id="health" aria-labelledby="h-h">
  <h2 id="h-h">Health</h2>
  <dl class="kv">
    <dt>PHP</dt><dd><?= e($php) ?> (<?= e($sapi) ?>) <?= $ok(version_compare($php, '8.2.0', '>=')) ?></dd>
    <dt>Database</dt><dd><?= e($db) ?><?= $dbSize !== null ? ', ' . e(Backup::human((int)$dbSize)) : '' ?></dd>
    <dt>Site address</dt><dd class="mono"><?= e($baseUrl) ?><?= $staging ? ' <span class="tag tag--warn">Staging: hidden from search engines</span>' : '' ?></dd>
    <dt>HTTPS</dt><dd><?= $https ? 'Yes' : 'Not on this request' ?> <?= $ok($https) ?></dd>
    <dt>Your IP as the site sees it</dt><dd class="mono"><?= e($ip) ?><?= $forwarded ? ' <span class="tag tag--warn">A proxy or CDN is in front of the site; switch the Hostinger CDN off</span>' : '' ?></dd>
    <dt>Uploads</dt><dd><?= e(Backup::human((int)$uploads[0])) ?> in <?= number_format((int)$uploads[1]) ?><?= $uploads[2] ? '+' : '' ?> files</dd>
    <dt>Private storage</dt><dd class="mono"><?= e($private['rel']) ?></dd>
    <dd><?= $private['exists'] ? e(Backup::human((int)$private['bytes'])) . ' in ' . number_format((int)$private['files']) . ($private['more'] ? '+' : '') . ' files' : 'Missing' ?> <?= $ok($private['exists'] && $private['writable']) ?></dd>
  </dl>
  <h3>Extensions</h3>
  <div class="table-wrap">
  <table class="table table--cards">
    <thead><tr><th scope="col">Extension</th><th scope="col">Used for</th><th scope="col">Status</th></tr></thead>
    <tbody>
<?php foreach ($ext as $x): ?>
      <tr><td data-label="Extension" class="mono"><?= e($x['name']) ?></td><td data-label="Used for"><?= e($x['why']) ?></td>
        <td data-label="Status"><?= $x['ok'] ? '<span class="tag tag--ok">Present</span>' : ($x['required'] ? '<span class="tag tag--bad">Missing: enable it in hPanel</span>' : '<span class="tag">Missing (optional)</span>') ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <h3>PHP options</h3>
  <div class="table-wrap">
  <table class="table table--cards">
    <thead><tr><th scope="col">Option</th><th scope="col">Now</th><th scope="col">Recommended</th></tr></thead>
    <tbody>
<?php foreach ($ini as $i): ?>
      <tr><td data-label="Option" class="mono"><?= e($i['name']) ?></td><td data-label="Now"><?= e($i['value']) ?> <?= $ok($i['ok']) ?></td><td data-label="Recommended"><?= e($i['want']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <h3>Writable folders</h3>
  <ul class="plain-list">
<?php foreach ($writable as $w): ?>
    <li class="mono"><?= e($w['dir']) ?> <?= $ok($w['ok']) ?></li>
<?php endforeach; ?>
  </ul>
</section>

<section class="card" id="cron" aria-labelledby="c-h">
  <h2 id="c-h">Cron</h2>
<?php if ($cron['last'] === ''): ?>
  <div class="alert alert--info" role="status">The cron job has never run. It is optional: scheduled posts go live without it. For nightly backups, add a cron job every 5 minutes in the hosting control panel with the first command below.</div>
<?php elseif ($cronAge !== null && $cronAge > 600): ?>
  <div class="alert alert--bad" role="status">The cron job last ran <?= e(Time::long($cron['last'], true)) ?>. It should run every minute.</div>
<?php endif; ?>
  <dl class="kv">
    <dt>Last run</dt><dd><?= $cron['last'] !== '' ? e(Time::long($cron['last'], true)) . ' (' . e($cron['via'] ?: 'cli') . ')' : 'Never' ?></dd>
    <dt>Cron PHP</dt><dd><?= $cron['php'] !== '' ? e($cron['php']) . ' ' . $ok(version_compare($cron['php'], '8.2.0', '>=')) : 'Not known yet' ?></dd>
<?php if ($cron['binary'] !== ''): ?>
    <dt>PHP binary</dt><dd class="mono wrap-any"><?= e($cron['binary']) ?></dd>
<?php endif; ?>
  </dl>
  <h3>The command for hPanel (every minute)</h3>
  <p class="muted">Paste the first one. If the cron PHP above shows a version below 8.2, paste the second instead. Paste it again whenever the site's domain changes.</p>
<?php foreach ($cron['cmds'] as $i => $c): ?>
  <div class="copyline"><code class="mono wrap-any"><?= e($c) ?></code> <button type="button" class="btn btn--line btn--sm" data-copy="<?= e($c) ?>">Copy</button></div>
<?php endforeach; ?>
<?php if ($cron['web'] !== ''): ?>
  <details class="details">
    <summary>Web fallback address (only if hPanel cron is not available)</summary>
    <p class="muted">An outside pinger can call this every minute. Keep it secret.</p>
    <div class="copyline"><code class="mono wrap-any"><?= e($cron['web']) ?></code> <button type="button" class="btn btn--line btn--sm" data-copy="<?= e($cron['web']) ?>">Copy</button></div>
  </details>
<?php endif; ?>
  <div class="table-wrap">
  <table class="table table--cards">
    <thead><tr><th scope="col">Task</th><th scope="col">Schedule (Toronto)</th><th scope="col">Last run</th><th scope="col">Result</th><th scope="col"><span class="sr-only">Run</span></th></tr></thead>
    <tbody>
<?php foreach ($cron['tasks'] as $t): ?>
      <tr>
        <td data-label="Task" class="mono"><?= e($t['name']) ?></td>
        <td data-label="Schedule"><?= e($t['schedule']) ?></td>
        <td data-label="Last run"><?= $t['last'] !== '' ? e(Time::long($t['last'], true)) : '<span class="muted">' . ($t['shipped'] ? 'Never' : 'Not installed yet') . '</span>' ?></td>
        <td data-label="Result"><?= $t['error'] !== '' ? '<span class="tag tag--bad">' . e($t['error']) . '</span>' : e($t['result']) ?></td>
        <td data-label="Run"><?php if ($t['shipped']): ?><form method="post" action="/admin/system/cron-run"><?= csrfField() ?><input type="hidden" name="task" value="<?= e($t['name']) ?>"><button class="btn btn--line btn--sm" type="submit" data-busy="Running...">Run now</button></form><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" id="db" aria-labelledby="d-h">
  <h2 id="d-h">Database update</h2>
<?php if ($pending): ?>
  <div class="alert alert--warn" role="status">Waiting: <?= e(implode(', ', $pending)) ?>. Public pages show "Back soon" until it is applied.</div>
  <form method="post" action="/admin/system/migrate"><?= csrfField() ?><button class="btn btn--primary" type="submit" data-busy="Updating...">Apply database update</button></form>
<?php else: ?>
  <p>Up to date. <?= count($applied) ?> <?= count($applied) === 1 ? 'update' : 'updates' ?> applied, the latest <span class="mono"><?= e((string)end($applied)) ?></span>.</p>
<?php endif; ?>
</section>

<section class="card" id="backups" aria-labelledby="b-h">
  <h2 id="b-h">Backups</h2>
  <p class="muted">A backup of the database runs every night at 3:10 am and the latest 14 are kept in private storage. Hostinger keeps its own backups too; once a month, download the latest file and keep it off the server.</p>
<?php if ($backupInfo['error'] !== ''): ?>
  <div class="alert alert--bad" role="status">The last backup failed: <?= e($backupInfo['error']) ?></div>
<?php endif; ?>
  <form method="post" action="/admin/system/backup"><?= csrfField() ?><button class="btn btn--primary" type="submit" data-busy="Backing up...">Back up now</button></form>
<?php if (!$backups): ?>
  <p>No backups yet.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table table--cards">
    <thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">Made</th><th scope="col"><span class="sr-only">Download</span></th></tr></thead>
    <tbody>
<?php foreach ($backups as $b): ?>
      <tr>
        <td data-label="File" class="mono"><?= e($b['file']) ?></td>
        <td data-label="Size"><?= e(Backup::human((int)$b['bytes'])) ?></td>
        <td data-label="Made"><?= e(Time::long($b['at'], true)) ?></td>
        <td data-label="Download">
          <form method="post" action="/admin/system/download" class="btn-row"><?= csrfField() ?>
            <button class="btn btn--line btn--sm" type="submit" name="file" value="<?= e($b['file']) ?>">Download</button>
<?php if ($b['manifest']): ?>
            <button class="btn btn--line btn--sm" type="submit" name="file" value="<?= e($b['manifest']) ?>">Uploads list</button>
<?php endif; ?>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <p class="muted small">Last download: <?= $backupInfo['download'] !== '' ? e(Time::long($backupInfo['download'], true)) : 'never' ?>.</p>
  <details class="details">
    <summary>How to restore a backup</summary>
    <ol class="steps">
      <li>In hPanel, open Databases, then phpMyAdmin for the site's database.</li>
      <li>Choose Import, pick the downloaded .sql.gz file and press Go. It replaces the tables it contains.</li>
      <li>Uploaded photos are not in the database backup: they come back from Hostinger's file backups (the uploads list shows what should be there).</li>
      <li>Restoring onto a fresh install (a new app secret) leaves the stored passwords unreadable: enter the mail passwords, API keys and the webhook secret again in Settings, make a new DKIM key and replace its DNS record.</li>
    </ol>
  </details>
</section>

<section class="card" id="tools" aria-labelledby="t-h">
  <h2 id="t-h">Tools</h2>
  <div class="btn-row">
    <form method="post" action="/admin/system/search"><?= csrfField() ?><button class="btn btn--line" type="submit" data-busy="Rebuilding...">Rebuild the search index</button></form>
    <form method="post" action="/admin/system/cache"><?= csrfField() ?><button class="btn btn--line" type="submit">Clear the cache</button></form>
<?php if ($installer): ?>
    <form method="post" action="/admin/system/installer" data-confirm="Delete the installer folder? Reinstalling later means uploading it again."><?= csrfField() ?><button class="btn btn--danger" type="submit">Delete the installer</button></form>
<?php endif; ?>
  </div>
<?php if ($installer): ?>
  <p class="alert alert--warn" role="status">The installer folder is still on the server<?= $lock ? ' (it is locked)' : ' and it is not locked' ?>. Delete it once the site is live.</p>
<?php endif; ?>
</section>

<section class="card" id="logs" aria-labelledby="l-h">
  <h2 id="l-h">Logs</h2>
  <form method="get" action="/admin/system#logs" class="form form--inline">
    <div class="field">
      <label class="field__label" for="log">Log</label>
      <select id="log" name="log">
<?php foreach ($channels as $c): ?>
        <option value="<?= e($c) ?>"<?= $c === $channel ? ' selected' : '' ?>><?= e($c) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn--line" type="submit">Show</button>
  </form>
  <p class="muted small">The last 200 lines, newest at the bottom. Email addresses are masked.</p>
<?php if (!$log): ?>
  <p>This log is empty.</p>
<?php else: ?>
  <pre class="log" tabindex="0"><?= e(implode("\n", $log)) ?></pre>
<?php endif; ?>
</section>
