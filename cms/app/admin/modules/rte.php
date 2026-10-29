<?php
/**
 * Support for the rich-text editor (rte.js). Owner: W7.
 *   api/rte/links?q=   pages and posts for the Link dialog's picker
 *   api/rte/lint       the copy linter (Plain::problems) for a piece of HTML
 * No nav entry.
 */
defined('ICM') or exit;

return [
    'routes' => [
        'api/rte/links' => ['perm' => 'dashboard.view', 'methods' => ['GET'], 'handler' => function (array $req) {
            $q = trim(Sanitizer::text($req['query']['q'] ?? '', false, 80));
            $like = '%' . Db::like($q) . '%';
            $pages = Db::all(
                "SELECT title, path FROM pages WHERE status = 'published'" . ($q !== '' ? ' AND (title LIKE ?' . Db::LIKE_ESCAPE . ' OR path LIKE ?' . Db::LIKE_ESCAPE . ')' : '') . ' ORDER BY path LIMIT 40',
                $q !== '' ? [$like, $like] : []
            );
            $posts = Db::all(
                "SELECT title, slug FROM posts WHERE status = 'published'" . ($q !== '' ? ' AND title LIKE ?' . Db::LIKE_ESCAPE : '') . ' ORDER BY published_at DESC LIMIT 20',
                $q !== '' ? [$like] : []
            );
            $out = [];
            foreach ($pages as $p) $out[] = ['title' => (string)$p['title'], 'path' => (string)$p['path'], 'kind' => 'Page'];
            foreach ($posts as $p) $out[] = ['title' => (string)$p['title'], 'path' => '/blog/' . $p['slug'] . '/', 'kind' => 'Blog post'];
            admin_json(['ok' => true, 'items' => $out]);
        }],

        'api/rte/lint' => ['perm' => 'dashboard.view', 'methods' => ['POST'], 'handler' => function (array $req) {
            $html = (string)($req['json']['html'] ?? $req['post']['html'] ?? '');
            admin_json(['ok' => true, 'problems' => Plain::problems(mb_substr($html, 0, 200000))]);
        }],
    ],
];
