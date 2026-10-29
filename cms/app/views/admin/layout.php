<?php
/**
 * The admin shell (spec 4.1.1), adapted from NHF. Owner: W7.
 * 900 px and wider: a sidebar with the nav groups. Narrower: the sidebar is a
 * slide-in drawer and a bottom tab bar carries Home, Scores, Inbox, Courses and
 * More (the next best screens for roles without those). No service worker here.
 *
 * @var View $view
 * @var string $content
 * @var ?array $me
 * @var list<array{msg:string,kind:string}> $flash
 * @var string $csrf
 * @var string $route
 */
defined('ICM') or exit;

$bare = !empty($bare) || !$me;
$siteName = siteName();
$pageTitle = trim((string)($title ?? '')) !== '' ? $title . ' | Admin | ' . $siteName : 'Admin | ' . $siteName;
$groups = $bare ? [] : admin_nav();
$isOn = static function (string $path) use ($route): bool {
    $path = trim($path, '/');
    return $path === '' ? $route === '' : ($route === $path || str_starts_with($route, $path . '/'));
};

$tabs = [];
if (!$bare) {
    $byPath = [];
    foreach ($groups as $items) foreach ($items as $n) $byPath[trim((string)$n['path'], '/')] = $n;
    $tabs[] = ['Home', '', 'home', 0];
    $wanted = [
        ['Inbox', 'inbox', 'inbox.view', 'inbox'],
        ['Pages', 'pages', 'pages.edit', 'list'],
        ['Blog', 'posts', 'posts.edit', 'list'],
        ['Photos', 'media', 'media.edit', 'image'],
    ];
    foreach ($wanted as [$label, $path, $perm, $icon]) {
        if (count($tabs) >= 4) break;
        if (!Auth::can($perm)) continue;
        $tabs[] = [$label, $path, $icon, isset($byPath[$path]) ? admin_badge($byPath[$path]) : 0];
    }
}
$icons = [
    'home'  => '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    'ball'  => '<circle cx="12" cy="12" r="8.5"/><path d="M5.2 7.2c3 1.6 4.3 4.3 3.8 8.8M18.8 16.8c-3-1.6-4.3-4.3-3.8-8.8"/>',
    'inbox' => '<path d="M3 13l2.5-8h13L21 13v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 13h5l1.5 3h5L16 13h5"/>',
    'list'  => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
    'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m3 17 5-5 4 4 3-3 6 6"/><circle cx="15.5" cy="8.5" r="1.5"/>',
    'more'  => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
];
$svg = static fn(string $k): string => '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($icons[$k] ?? '') . '</svg>';

$pending = false;
$maintenance = false;
if (!$bare) {
    try {
        $pending = Migrator::isPendingCached();
        $maintenance = Db::settingBool('site.maintenance');
    } catch (Throwable) {
    }
}
// v3: the admin's light or dark look, chosen with the topbar switch (cookie rb_admin_theme; system follows the device)
$adminTheme = in_array($_COOKIE['rb_admin_theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['rb_admin_theme'] : 'system';
?><!doctype html>
<html lang="en-CA" data-theme="<?= e($adminTheme) ?>" data-site="<?= e($siteName) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#17161a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e($siteName) ?> admin">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle) ?></title>
<link rel="manifest" href="/admin/assets/admin.webmanifest">
<link rel="icon" href="<?= e(admin_asset('icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(admin_asset('icons/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(admin_asset('admin.css')) ?>">
<?php if ($csrf !== ''): ?>
<meta name="csrf-token" content="<?= e($csrf) ?>">
<?php endif; ?>
<?= $view->headHtml() ?>
</head>
<body class="<?= $bare ? 'is-bare' : 'is-shell' ?>" data-route="<?= e($route) ?>"<?= $me ? ' data-user="' . (int)$me['id'] . '" data-role="' . e($me['role']) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($bare): ?>
<main id="main" class="bare" tabindex="-1">
<?php foreach ($flash as $f): ?>
  <div class="alert alert--<?= $f['kind'] === 'bad' ? 'bad' : 'ok' ?>" role="<?= $f['kind'] === 'bad' ? 'alert' : 'status' ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<?php else: ?>
<div class="shell" data-shell>
  <header class="topbar">
    <button class="topbar__menu" type="button" data-drawer-open aria-controls="side" aria-expanded="false"><?= $svg('list') ?><span>Menu</span></button>
    <a class="topbar__brand" href="/admin/"><?= e($siteName) ?> admin</a>
    <button class="topbar__theme" type="button" data-theme-toggle aria-label="Switch between light and dark" title="Light or dark"><span aria-hidden="true" class="topbar__theme-ico"></span></button>
    <a class="topbar__site" href="/" target="_blank" rel="noopener">View site</a>
  </header>

  <nav id="side" class="side" aria-label="Admin sections" data-drawer>
    <div class="side__head">
      <a class="side__brand" href="/admin/"><img src="<?= e(admin_asset('icons/icon.svg')) ?>" alt="" width="32" height="32"><span><?= e($siteName) ?></span></a>
      <button class="side__close" type="button" data-drawer-close aria-label="Close the menu">&times;</button>
    </div>
<?php foreach ($groups as $group => $items): ?>
<?php if ($group !== 'Dashboard'): ?>
    <p class="side__group"><?= e($group) ?></p>
<?php endif; ?>
    <ul class="side__list">
<?php foreach ($items as $n): $on = $isOn((string)$n['path']); $badge = admin_badge($n); ?>
      <li><a href="<?= e(admin_url((string)$n['path'])) ?>"<?= $on ? ' class="is-on" aria-current="page"' : '' ?>><span><?= e($n['label']) ?></span><?php if ($badge > 0): ?><span class="count" aria-label="<?= $badge ?> new"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endforeach; ?>
    <div class="side__foot">
      <a href="/admin/account"<?= $isOn('account') ? ' class="is-on" aria-current="page"' : '' ?>><?= e($me['name']) ?> <span class="muted">(<?= e(Auth::ROLES[$me['role']] ?? $me['role']) ?>)</span></a>
      <a href="/" target="_blank" rel="noopener">View the website</a>
      <form method="post" action="/admin/logout"><?= csrfField() ?><button type="submit" class="linklike">Sign out</button></form>
    </div>
  </nav>
  <div class="backdrop" data-drawer-close hidden></div>

  <main id="main" class="main" tabindex="-1">
<?php if ($pending): ?>
    <div class="alert alert--warn banner" role="status">
<?php if (Auth::can('system.run')): ?>
      <form method="post" action="/admin/system/migrate" class="banner__form"><?= csrfField() ?>
        <span>A database update is waiting. Public pages show the "Back soon" page until it is applied.</span>
        <button class="btn btn--primary btn--sm" type="submit" data-busy="Updating...">Apply database update</button>
      </form>
<?php else: ?>
      A database update is waiting. The owner can apply it in System.
<?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($maintenance): ?>
    <div class="alert alert--warn banner" role="status">Maintenance mode is on: visitors see the "Back soon" page.<?php if (Auth::can('settings.edit')): ?> <a href="/admin/settings?section=site">Turn it off in Settings</a>.<?php endif; ?></div>
<?php endif; ?>
<?php foreach ($flash as $f): ?>
    <div class="alert alert--<?= $f['kind'] === 'bad' ? 'bad' : 'ok' ?>" role="<?= $f['kind'] === 'bad' ? 'alert' : 'status' ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?= $content ?>
    <footer class="main__foot">
      <span><?= e($siteName) ?> admin, build <?= e(appVersion()) ?></span>
<?php if (isStaging()): ?>
      <span>Staging address: hidden from search engines</span>
<?php endif; ?>
    </footer>
  </main>

  <nav class="tabbar" aria-label="Quick links">
<?php foreach ($tabs as [$label, $path, $icon, $badge]): $on = $isOn($path); ?>
    <a href="<?= e(admin_url($path)) ?>"<?= $on ? ' class="is-on" aria-current="page"' : '' ?>><?= $svg($icon) ?><span><?= e($label) ?></span><?php if ($badge > 0): ?><span class="count" aria-label="<?= (int)$badge ?> new"><?= $badge > 99 ? '99+' : (int)$badge ?></span><?php endif; ?></a>
<?php endforeach; ?>
    <button type="button" data-drawer-open aria-controls="side" aria-expanded="false"><?= $svg('more') ?><span>More</span></button>
  </nav>
</div>
<?php endif; ?>
<script src="<?= e(admin_asset('admin.js')) ?>" defer></script>
<?php if (str_contains($content, 'data-rte')): ?>
<script src="<?= e(admin_asset('rte.js')) ?>" defer></script>
<?php endif; ?>
<?= $view->footHtml() ?>
</body>
</html>
