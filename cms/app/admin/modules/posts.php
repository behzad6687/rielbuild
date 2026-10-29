<?php
/**
 * Admin > Content > Blog posts.
 *
 * List: search, status filter, Publish on drafts, View on live posts, SEO score,
 * "Newsletter sent". Editor: headline, web address (built from the headline
 * while a draft; on a live post it changes only behind "Change web address",
 * and the old address is redirected automatically), summary, body (rte.js),
 * featured image with alt and focal point, gallery (one upload per request,
 * captions, order), search appearance with meters and a Google preview, tags,
 * status with a Toronto date and time, author, featured toggle, the SEO check
 * and the "Send as newsletter" panel (W6 fills it).
 */
defined('ICM') or exit;

$postsPerm = 'posts.edit';

/** Normalise a $_FILES entry posted as name[] into a list of single-file arrays. */
$fileList = static function (?array $f): array {
    if (!$f || !is_array($f['name'] ?? null)) return [];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? 0, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
};

$afterSave = static function (int $id): void {
    ContentAdmin::reindexPost($id);
    Sitemap::invalidate();
    if (class_exists('Content')) Content::flush();
};

$listRoute = static function (array $req): never {
    $q = AdminKit::str($req['query'], 'q', 100);
    $status = AdminKit::pick($req['query']['status'] ?? '', array_keys(ContentAdmin::POST_STATUSES), '');
    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = '(title LIKE ?' . Db::LIKE_ESCAPE . ' OR slug LIKE ?' . Db::LIKE_ESCAPE . ' OR tags LIKE ?' . Db::LIKE_ESCAPE . ')';
        $like = '%' . Db::like($q) . '%';
        array_push($args, $like, $like, $like);
    }
    if ($status !== '') {
        $where[] = 'status = ?';
        $args[] = $status;
    }
    $sql = 'SELECT * FROM posts' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY CASE status WHEN 'draft' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END, COALESCE(published_at, created_at) DESC, id DESC";
    $pg = Db::paginate($sql, $args, AdminKit::int($req['query'], 'page', 1) ?? 1, 25);
    $ids = array_map(static fn($r) => (int)$r['id'], $pg['rows']);
    $sent = [];
    $counts = Db::pairs('SELECT status, COUNT(*) FROM posts GROUP BY status');
    admin_render('posts/list', ['pg' => $pg, 'q' => $q, 'status' => $status, 'sent' => array_flip($sent), 'counts' => $counts]);
};

$editRoute = static function (array $req) use ($fileList, $afterSave): never {
    $id = AdminKit::int($req['query'], 'id', 0) ?: 0;
    $row = $id ? Db::one('SELECT * FROM posts WHERE id = ?', [$id]) : null;
    if ($id && !$row) admin_redirect('posts', 'That post no longer exists.', 'bad');
    $errors = [];
    $warnings = [];
    $values = $row ?? ['id' => 0, 'title' => '', 'slug' => '', 'excerpt' => '', 'body_html' => '', 'featured_media_id' => null, 'featured_alt' => '',
        'seo_title' => '', 'meta_description' => '', 'tags' => '', 'author_name' => siteName() . ' team', 'category' => 'tips', 'status' => 'draft', 'published_at' => null,
        'is_featured' => 0, 'updated_at' => null];

    if ($req['method'] === 'POST') {
        $p = $req['post'];
        $fix = !empty($p['fix_dashes']);
        if ($stale = AdminKit::stale($row, $p)) {
            $errors[] = $stale;
        } else {
            $t = static fn(string $k, int $max, bool $multi = false): string => $fix ? AdminKit::fixDashes(AdminKit::str($p, $k, 0, $multi)) : AdminKit::str($p, $k, 0, $multi);
            $title = $t('title', 250);
            if ($title === '') $errors[] = 'Add a headline.';
            if (mb_strlen($title) > 250) $errors[] = 'The headline is 250 characters at most.';
            $excerpt = $t('excerpt', 400, true);
            if (mb_strlen($excerpt) > 400) $errors[] = 'The summary is 400 characters at most.';
            $seoTitle = $t('seo_title', 200);
            if (mb_strlen($seoTitle) > 200) $errors[] = 'The SEO title is 200 characters at most.';
            $meta = $t('meta_description', 320, true);
            if (mb_strlen($meta) > 320) $errors[] = 'The meta description is 320 characters at most.';
            $tags = implode(', ', array_values(array_unique(array_filter(array_map(static fn($x) => mb_substr(trim($x), 0, 40), explode(',', AdminKit::str($p, 'tags', 255)))))));
            if (mb_strlen($tags) > 255) $errors[] = 'Too many tags (255 characters at most).';
            $author = AdminKit::str($p, 'author_name', 80) ?: siteName() . ' team';
            $category = AdminKit::pick($p['category'] ?? 'tips', array_keys(Content::postCategories()), 'tips');
            $body = AdminKit::rich($p, 'body_html', $fix);

            // Status and date (entered in Toronto time, stored UTC).
            $status = AdminKit::pick($p['status'] ?? 'draft', array_keys(ContentAdmin::POST_STATUSES), 'draft');
            $when = AdminKit::localToUtc($p['published_at'] ?? '');
            if (trim((string)($p['published_at'] ?? '')) !== '' && !$when) $errors[] = 'The date and time could not be read.';
            $now = Time::nowUtc();
            $note = '';
            if ($status === 'published') {
                $when ??= $now;
                if ($when > $now) {
                    $status = 'scheduled';
                    $note = ' The date is in the future, so the post is scheduled.';
                }
            } elseif ($status === 'scheduled' && (!$when || $when <= $now)) {
                $errors[] = 'Choose a future date and time to schedule the post.';
            }

            // Web address: built from the headline while a draft; changed on a live post only on purpose.
            $wasLive = $row && in_array($row['status'], ['published', 'scheduled'], true);
            $slug = slugify(AdminKit::str($p, 'slug', 191));
            if ($slug === '') $slug = slugify($title);
            $slug = substr($slug, 0, 180) ?: 'post';
            $moved = false;
            if ($row && $wasLive && $slug !== $row['slug'] && empty($p['change_slug'])) {
                $slug = (string)$row['slug'];
            }
            $slug = AdminKit::uniqueSlug('posts', $slug, $row ? (int)$row['id'] : null, 'slug', 191);
            if ($row && $wasLive && $slug !== $row['slug']) $moved = true;

            // Featured image with alt text (required before publishing).
            $img = AdminKit::imageFromForm($req, 'featured', $row ? ((int)$row['featured_media_id'] ?: null) : null, $slug);
            if ($img['error']) $errors[] = 'Featured image: ' . $img['error'];
            $alt = Sanitizer::text($p['featured']['alt'] ?? '', false, 300);
            if ($alt === '') $alt = (string)$img['alt'];
            if ($img['id'] && $alt === '' && $status !== 'draft') $errors[] = 'Add alt text for the featured image before publishing.';

            $data = [
                'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt !== '' ? $excerpt : null, 'body_html' => $body !== '' ? $body : null,
                'featured_media_id' => $img['id'], 'featured_alt' => $alt !== '' ? $alt : null, 'tags' => $tags !== '' ? $tags : null,
                'seo_title' => $seoTitle !== '' ? $seoTitle : null, 'meta_description' => $meta !== '' ? $meta : null,
                'author_name' => $author, 'category' => $category, 'status' => $status, 'published_at' => $when, 'is_featured' => AdminKit::flag($p, 'is_featured'),
                'updated_at' => $now, 'updated_by' => AdminKit::uid($req),
            ];

            if (!$errors) {
                $savedId = (int)Db::tx(function () use ($row, $data, $req, $p, $fileList, $slug, $moved) {
                    if ($row) {
                        Db::update('posts', $data, 'id = ?', [(int)$row['id']]);
                        $pid = (int)$row['id'];
                    } else {
                        $pid = Db::insert('posts', $data + ['created_at' => $data['updated_at']]);
                    }
                    if ($moved) ContentAdmin::pathMoved(ContentAdmin::postPath((string)$row['slug']), ContentAdmin::postPath($slug));

                    // Gallery: the posted order, captions and alt texts; missing rows are removed.
                    $gallery = is_array($p['gallery'] ?? null) ? $p['gallery'] : [];
                    $keep = [];
                    $sort = 0;
                    foreach ($gallery as $mid => $g) {
                        $mid = (int)$mid;
                        if ($mid <= 0 || !is_array($g) || !empty($g['remove'])) continue;
                        if (!Db::value("SELECT 1 FROM media WHERE id = ? AND kind = 'image'", [$mid])) continue;
                        $keep[] = $mid;
                        $cap = Sanitizer::text($g['caption'] ?? '', false, 300);
                        Db::upsert('post_media', ['post_id', 'media_id'], ['post_id' => $pid, 'media_id' => $mid, 'sort' => $sort += 10, 'caption' => $cap !== '' ? $cap : null]);
                        $galt = Sanitizer::text($g['alt'] ?? '', false, 300);
                        if ($galt !== '' && $galt !== (string)Db::value('SELECT alt FROM media WHERE id = ?', [$mid], '')) Media::update($mid, ['alt' => $galt], AdminKit::uid($req));
                    }
                    Db::run('DELETE FROM post_media WHERE post_id = ?' . ($keep ? ' AND media_id NOT IN (' . Db::in($keep) . ')' : ''), array_merge([$pid], $keep));
                    // Without JavaScript the photos arrive with the form.
                    foreach ($fileList($req['files']['gallery_files'] ?? null) as $f) {
                        $r = AdminKit::storeImage($f, $slug, ['by' => AdminKit::uid($req)]);
                        if ($r['ok']) Db::insertOrIgnore('post_media', ['post_id' => $pid, 'media_id' => $r['id'], 'sort' => $sort += 10]);
                        else admin_flash('Gallery photo not added: ' . $r['error'], 'bad');
                    }
                    return $pid;
                });
                $afterSave($savedId);
                $action = !$row ? 'create' : (($row['status'] !== 'published' && $data['status'] === 'published') ? 'publish' : 'update');
                admin_audit($action, 'posts', (string)$savedId, 'Post ' . ($action === 'create' ? 'created' : ($action === 'publish' ? 'published' : 'saved')) . ': ' . mb_substr($title, 0, 150), $row ? AdminKit::diff($row, $data) : []);
                $msg = match ($data['status']) {
                    'published' => 'Saved. It is live at ' . ContentAdmin::postPath($slug) . '.',
                    'scheduled' => 'Saved. It goes live on ' . Time::long((string)$data['published_at'], true) . '.',
                    default     => 'Saved as a draft.',
                } . $note;
                if ($moved) $msg .= ' The old address now redirects to the new one.';
                $lint = AdminKit::lint($title, $excerpt, $body, $meta, $seoTitle);
                if ($lint) $msg .= ' There are house style warnings below.';
                admin_redirect('posts/edit?id=' . $savedId, $msg);
            }
            $values = array_merge($values, $data, ['slug' => $slug]);
        }
    }

    if ($row) $warnings = AdminKit::lint($values['title'], (string)$values['excerpt'], (string)$values['body_html'], (string)$values['meta_description'], (string)$values['seo_title']);
    $gallery = $row ? Db::all('SELECT pm.media_id, pm.caption, pm.sort, m.alt, m.path, m.width, m.height, m.variants_json, m.kind FROM post_media pm JOIN media m ON m.id = pm.media_id WHERE pm.post_id = ? ORDER BY pm.sort, pm.media_id', [(int)$row['id']]) : [];
    $campaign = null;
    admin_render('posts/edit', [
        'row' => $row, 'values' => $values, 'errors' => $errors, 'warnings' => $warnings, 'gallery' => $gallery, 'campaign' => $campaign,
        'audit' => ContentAdmin::seo($values + ['id' => $row['id'] ?? 0], 'post'), 'title' => $row ? 'Edit post' : 'Write a post',
    ]);
};

return [
    'nav' => ['group' => 'Content', 'label' => 'Blog posts', 'path' => 'posts', 'perm' => $postsPerm, 'sort' => 10,
              'badge' => static fn(): int => (int)Db::value("SELECT COUNT(*) FROM posts WHERE status = 'draft'", [], 0)],
    'routes' => [
        'posts'      => ['perm' => $postsPerm, 'methods' => ['GET'], 'handler' => $listRoute],
        'posts/edit' => ['perm' => $postsPerm, 'methods' => ['GET', 'POST'], 'handler' => $editRoute],

        'posts/publish' => ['perm' => $postsPerm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($afterSave): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $p = Db::one('SELECT * FROM posts WHERE id = ?', [$id]);
            if (!$p) admin_redirect('posts', 'That post no longer exists.', 'bad');
            if (!empty($p['featured_media_id']) && trim((string)$p['featured_alt']) === '' && trim((string)Db::value('SELECT alt FROM media WHERE id = ?', [(int)$p['featured_media_id']], '')) === '') {
                admin_redirect('posts/edit?id=' . $id, 'Add alt text for the featured image before publishing.', 'bad');
            }
            $now = Time::nowUtc();
            $when = (!empty($p['published_at']) && $p['published_at'] <= $now) ? $p['published_at'] : $now;
            Db::update('posts', ['status' => 'published', 'published_at' => $when, 'updated_at' => $now, 'updated_by' => AdminKit::uid($req)], 'id = ?', [$id]);
            $afterSave($id);
            admin_audit('publish', 'posts', (string)$id, 'Post published: ' . mb_substr((string)$p['title'], 0, 150));
            admin_redirect(($req['post']['back'] ?? '') === 'edit' ? 'posts/edit?id=' . $id : 'posts', 'Published. It is live at ' . ContentAdmin::postPath((string)$p['slug']) . '.');
        }],

        'posts/unpublish' => ['perm' => $postsPerm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($afterSave): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $p = Db::one('SELECT * FROM posts WHERE id = ?', [$id]);
            if (!$p) admin_redirect('posts', 'That post no longer exists.', 'bad');
            Db::update('posts', ['status' => 'draft', 'updated_at' => Time::nowUtc(), 'updated_by' => AdminKit::uid($req)], 'id = ?', [$id]);
            $afterSave($id);
            admin_audit('unpublish', 'posts', (string)$id, 'Post moved back to draft: ' . mb_substr((string)$p['title'], 0, 150));
            admin_redirect('posts/edit?id=' . $id, 'Moved back to draft. The address now shows the blog page instead.');
        }],

        'posts/delete' => ['perm' => $postsPerm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($afterSave): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $p = Db::one('SELECT id, title, slug FROM posts WHERE id = ?', [$id]);
            if (!$p) admin_redirect('posts', 'That post was already deleted.', 'bad');
            Db::delete('posts', 'id = ?', [$id]);
            Search::remove('post', $id);
            Sitemap::invalidate();
            admin_audit('delete', 'posts', (string)$id, 'Post deleted: ' . mb_substr((string)$p['title'], 0, 150));
            admin_redirect('posts', 'Deleted "' . mb_substr((string)$p['title'], 0, 80) . '". Its address now shows the blog page.');
        }],

        // One image per request (spec 2.5): the gallery's JavaScript uploader and the RTE.
        'api/posts/upload' => ['perm' => $postsPerm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $f = AdminKit::file($req, 'file');
            if (!$f) admin_json(['ok' => false, 'error' => 'Choose a photo first.'], 422);
            $hint = AdminKit::str($req['post'], 'name', 80) ?: 'news';
            $r = AdminKit::storeImage($f, $hint, ['by' => AdminKit::uid($req), 'alt' => AdminKit::str($req['post'], 'alt', 300)]);
            if (!$r['ok']) admin_json(['ok' => false, 'error' => $r['error']], 422);
            $m = mediaRow((int)$r['id']);
            admin_json(['ok' => true, 'id' => (int)$r['id'], 'thumb' => mediaUrl($m, 'thumb'), 'src' => mediaUrl($m, 1200), 'alt' => (string)($m['alt'] ?? '')]);
        }],
    ],
];
