<?php
/**
 * The one-page installer: checks the server, then (one form) writes the configuration, creates the
 * database with the starter content and the owner's admin account. Refuses to run once installed.
 */
define('ICM', 1);
require dirname(__DIR__) . '/app/bootstrap.php';

@set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

$installed = Installer::installed();
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$host = (string)preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$detected = ($https ? 'https' : 'http') . '://' . $host;
$inFolder = trim((string)dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/\\.') !== '';
$checks = Installer::requirements();
$ready = !in_array(false, array_column($checks, 1), true) && !$inFolder;
$errors = [];
$done = false;
$v = ['url' => $detected, 'name' => '', 'email' => '', 'live' => ''];

// A double-submit cookie stands in for a session before the site has a secret.
$token = (string)($_COOKIE['rb_install'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    $token = bin2hex(random_bytes(16));
    setcookie('rb_install', $token, ['path' => '/install/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
}

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $v = [
        'url'   => trim((string)($_POST['url'] ?? '')),
        'name'  => trim((string)($_POST['name'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'live'  => !empty($_POST['live']) ? trim((string)($_POST['url'] ?? '')) : '',
    ];
    $pw = (string)($_POST['password'] ?? '');
    if (!hash_equals($token, (string)($_POST['_t'] ?? ''))) $errors[] = 'The form expired. Please try again.';
    if (!$ready) $errors[] = 'Fix the items marked "No" first.';
    if ($v['name'] === '') $errors[] = 'Enter your name.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($pw !== (string)($_POST['password2'] ?? '')) $errors[] = 'The two passwords do not match.';
    elseif ($p = Auth::passwordProblem($pw, $v['email'])) $errors[] = $p;
    if (!$errors) {
        try {
            Installer::writeConfig($v['url'], $v['live']);
            Installer::run($v['name'], $v['email'], $pw);
            $done = true;
        } catch (Throwable $e) {
            Log::exception($e, 'installer');
            $errors[] = $e->getMessage();
            if (!Installer::installed()) @unlink(APP_DIR . '/config.local.php');
        }
    }
}
?><!doctype html>
<html lang="en-CA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up your website</title>
<style>
  :root{--accent:#FB4500;--ink:#222;--muted:#5d5d5d;--line:#e3e3e3;--bg:#f4f4f4}
  *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px 16px}
  main{max-width:640px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:14px;padding:28px}
  h1{font-size:1.7rem;line-height:1.15;margin:0 0 6px}h2{font-size:1.05rem;margin:26px 0 10px}p{margin:0 0 12px}
  .mark{display:inline-block;width:12px;height:12px;border-radius:3px;background:var(--accent);margin-right:8px}
  table{width:100%;border-collapse:collapse;font-size:.95rem}td{padding:7px 0;border-bottom:1px solid var(--line);vertical-align:top}td.r{text-align:right;font-weight:600;white-space:nowrap}
  .no{color:#b42318}.yes{color:#067647}.fix{display:block;color:var(--muted);font-size:.86rem}
  label{display:block;font-weight:600;margin:14px 0 5px}input[type=text],input[type=email],input[type=password],input[type=url]{width:100%;padding:11px 12px;border:1px solid #cfcfcf;border-radius:9px;font:inherit}
  .hint{color:var(--muted);font-size:.88rem;margin-top:4px}.check{display:flex;gap:10px;align-items:flex-start;margin-top:16px;font-weight:400}
  button,.btn{display:inline-block;margin-top:22px;background:var(--accent);color:#fff;border:0;border-radius:999px;padding:12px 22px;font:600 1rem/1 inherit;text-decoration:none;cursor:pointer}
  .err{background:#fef3f2;border:1px solid #fecdca;color:#b42318;border-radius:10px;padding:12px 14px;margin:14px 0}.ok{background:#ecfdf3;border:1px solid #abefc6;color:#067647;border-radius:10px;padding:12px 14px;margin:14px 0}
</style>
</head>
<body>
<main>
  <h1><span class="mark" aria-hidden="true"></span>Set up your website</h1>
<?php if ($installed && !$done): ?>
  <p>This website is already set up.</p>
  <a class="btn" href="/admin/">Go to the admin</a>
<?php elseif ($done): ?>
  <div class="ok" role="status">All set. Your website, its pages and your admin account are ready.</div>
  <p>Sign in with <b><?= e($v['email']) ?></b> and the password you chose.</p>
  <a class="btn" href="/admin/login">Sign in to the admin</a>
  <p class="hint" style="margin-top:18px">For safety you can now delete the <b>install</b> folder from the server. It refuses to run again either way.</p>
<?php else: ?>
  <p>One form: it creates the database with all the pages, photos, menus and starter blog posts, and your admin account.</p>
  <h2>Server check</h2>
  <table>
    <?php if ($inFolder): ?><tr><td>The site sits at the top of its domain or subdomain<span class="fix">Upload these files to the main folder of a domain or subdomain (for example rielbuild.example.com), not inside a sub-folder.</span></td><td class="r no">No</td></tr><?php endif; ?>
    <?php foreach ($checks as [$label, $ok, $fix]): ?>
    <tr><td><?= e($label) ?><?php if (!$ok): ?><span class="fix"><?= e($fix) ?></span><?php endif; ?></td><td class="r <?= $ok ? 'yes' : 'no' ?>"><?= $ok ? 'Yes' : 'No' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($errors): ?><div class="err" role="alert"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" action="/install/" autocomplete="off">
    <input type="hidden" name="_t" value="<?= e($token) ?>">
    <h2>Website</h2>
    <label for="url">Website address</label>
    <input type="url" id="url" name="url" value="<?= e($v['url']) ?>" required>
    <p class="hint">Detected automatically. Only the address, with no folder after it.</p>
    <label class="check"><input type="checkbox" name="live" value="1"<?= $v['live'] !== '' ? ' checked' : '' ?>> <span>This is the real, public website (let Google index it). Leave it off for a demo or preview copy.</span></label>
    <h2>Your admin account</h2>
    <label for="name">Your name</label>
    <input type="text" id="name" name="name" value="<?= e($v['name']) ?>" required maxlength="120" autocomplete="name">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($v['email']) ?>" required maxlength="191" autocomplete="email">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password">
    <p class="hint">At least 12 characters. A short sentence works well.</p>
    <label for="password2">Password again</label>
    <input type="password" id="password2" name="password2" required minlength="12" autocomplete="new-password">
    <button type="submit"<?= $ready ? '' : ' disabled' ?>>Set up the website</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
