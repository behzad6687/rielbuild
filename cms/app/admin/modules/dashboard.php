<?php
/**
 * Dashboard (/admin): a greeting, quick actions, the figures that matter (new estimate requests,
 * blog posts, pages whose search titles or descriptions need work), "Needs attention", the recent
 * inbox and the recent posts. Every figure is read defensively: an empty table gives an empty tile.
 */
defined('ICM') or exit;

$safe = static function (callable $fn, mixed $fallback = null): mixed {
    try {
        return $fn();
    } catch (Throwable $e) {
        Log::exception($e, 'dashboard');
        return $fallback;
    }
};

$attention = static function () use ($safe): array {
    $items = [];
    $add = static function (string $text, ?string $link = null, string $kind = 'warn') use (&$items): void {
        $items[] = ['text' => $text, 'link' => $link, 'kind' => $kind];
    };
    if (Auth::can('inbox.view')) {
        $failed = (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM form_submissions WHERE notified_ok = 0 AND status <> 'spam'", [], 0), 0);
        if ($failed > 0) $add($failed . ' estimate ' . ($failed === 1 ? 'request' : 'requests') . ' could not be emailed to you. They are safe in the inbox; check Settings > Email sending.', 'inbox', 'bad');
    }
    if (Auth::can('pages.edit')) {
        $counts = ContentAdmin::titleCounts();
        $n = 0;
        foreach ((array)$safe(fn() => Db::all("SELECT * FROM pages WHERE status = 'published'"), []) as $p) if (ContentAdmin::pageIssues($p, $counts)) $n++;
        if ($n > 0) $add($n . ($n === 1 ? ' page has' : ' pages have') . ' a search title or description to improve.', 'pages?attention=1', 'info');
        $drafts = (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM pages WHERE status = 'draft'", [], 0), 0);
        if ($drafts > 0) $add($drafts . ' draft ' . ($drafts === 1 ? 'page is' : 'pages are') . ' not visible to visitors yet.', 'pages', 'info');
    }
    if (Auth::can('system.run')) {
        if ($safe(fn() => Migrator::pending(), [])) $add('A database update is waiting. Open System and apply it.', 'system', 'bad');
        $lastBackup = (string)Db::setting('backup.last_at', '');
        if ($lastBackup === '' || $lastBackup < Time::utcAgo(30 * 86400)) $add('No backup in the last 30 days. Make one in System and keep a copy off the server.', 'system#backups');
    }
    if (Auth::can('settings.edit') && isStaging()) {
        $add('Search engines are told not to index this copy of the site (demo or preview mode). It switches off when the site runs on its real address.', null, 'info');
    }
    return $items;
};

return [
    'nav'    => ['group' => 'Dashboard', 'label' => 'Dashboard', 'path' => '', 'perm' => 'dashboard.view', 'sort' => 0],
    'routes' => [
        '' => ['perm' => 'dashboard.view', 'methods' => ['GET'], 'handler' => function (array $req) use ($safe, $attention) {
            $me = $req['user'];
            $hour = (int)Time::nowLocal()->format('G');
            $home = $safe(fn() => Db::value("SELECT id FROM pages WHERE path = '/'"), null);
            admin_render('dashboard', [
                'title'    => 'Dashboard',
                'greeting' => ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening')) . ', ' . explode(' ', trim((string)$me['name']))[0] . '.',
                'homeId'   => $home ? (int)$home : null,
                'inboxNew' => Auth::can('inbox.view') ? (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM form_submissions WHERE status = 'new'", [], 0), 0) : null,
                'inbox30'  => Auth::can('inbox.view') ? (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM form_submissions WHERE status <> 'spam' AND created_at >= ?", [Time::utcAgo(30 * 86400)], 0), 0) : null,
                'postsLive' => Auth::can('posts.edit') ? (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM posts WHERE status = 'published'", [], 0), 0) : null,
                'postsDraft' => Auth::can('posts.edit') ? (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM posts WHERE status <> 'published'", [], 0), 0) : null,
                'pagesLive' => Auth::can('pages.edit') ? (int)$safe(fn() => Db::value("SELECT COUNT(*) FROM pages WHERE status = 'published'", [], 0), 0) : null,
                'items'    => $attention(),
                'top404'   => Auth::can('redirects.edit') ? (array)$safe(fn() => Db::all('SELECT path, hits, last_seen FROM not_found_log WHERE last_seen >= ? AND ignored = 0 ORDER BY hits DESC LIMIT 5', [Time::utcAgo(7 * 86400)]), []) : [],
                'inbox'    => Auth::can('inbox.view') ? (array)$safe(fn() => Db::all("SELECT id, name, subject, status, created_at FROM form_submissions WHERE status <> 'spam' ORDER BY created_at DESC LIMIT 5"), []) : null,
                'posts'    => Auth::can('posts.edit') ? (array)$safe(fn() => Db::all('SELECT id, title, status, published_at, updated_at FROM posts ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 5'), []) : null,
            ]);
        }],
    ],
];
