<?php
/**
 * Admin > Content > Pages and SEO. List: path, template, status and an SEO health summary (title and meta
 * length, H1, social image, robots), duplicate titles, filters. Page edit: title, H1, SEO title and meta
 * description with counters and the Google preview, social image, robots, sitemap, introduction and body,
 * status, parent, the web address (changes add a redirect), the page's wording and images (the template
 * fields), and the blocks editor. Add a page: a text page or a new service. Shared blocks tab too.
 */
defined('ICM') or exit;

$perm = 'pages.edit';
$reserved = '#^/(admin|install|app|storage|vendor|assets|uploads|forms|u|hooks|newsletter|certificates/file|calendar/event|ranking/series|blog|search|feed|sitemap)(/|$)#';

$previewUrl = static function (int $id, bool $signed = false): string {
    return function_exists('admin_preview_url') ? admin_preview_url('page', $id, $signed) : '/admin/preview/page/' . $id;
};

$afterSave = static function (int $id): void {
    ContentAdmin::reindexPage($id);
    Sitemap::invalidate();
    if (class_exists('Content')) Content::flush();
};

return [
    'nav' => ['group' => 'Content', 'label' => 'Pages and SEO', 'path' => 'pages', 'perm' => $perm, 'sort' => 10],
    'routes' => [
        'pages' => ['perm' => $perm, 'methods' => ['GET'], 'handler' => static function (array $req): never {
            $tpl = (string)($req['query']['template'] ?? '');
            $q = AdminKit::str($req['query'], 'q', 100);
            $attention = AdminKit::flag($req['query'], 'attention');
            $where = [];
            $args = [];
            if ($tpl !== '' && preg_match('/^[a-z0-9_]+$/', $tpl)) { $where[] = 'p.template = ?'; $args[] = $tpl; }
            if ($q !== '') {
                $like = '%' . Db::like($q) . '%';
                $where[] = '(p.path LIKE ?' . Db::LIKE_ESCAPE . ' OR p.title LIKE ?' . Db::LIKE_ESCAPE . ')';
                array_push($args, $like, $like);
            }
            $rows = Db::all('SELECT p.*, (SELECT COUNT(*) FROM blocks b WHERE b.page_id = p.id) AS n_blocks FROM pages p'
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.path', $args);
            $counts = ContentAdmin::titleCounts();
            foreach ($rows as $i => $r) $rows[$i]['issues'] = ContentAdmin::pageIssues($r, $counts);
            if ($attention) $rows = array_values(array_filter($rows, static fn($r) => $r['issues'] || $r['status'] !== 'published'));
            admin_render('pages/list', ['title' => 'Pages and SEO', 'rows' => $rows, 'tpl' => $tpl, 'q' => $q, 'attention' => $attention,
                'templates' => Db::column('SELECT DISTINCT template FROM pages ORDER BY template')]);
        }],

        'pages/edit' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($afterSave, $previewUrl, $reserved): never {
            $id = AdminKit::int($req['query'], 'id', 0) ?: 0;
            $row = Db::one('SELECT * FROM pages WHERE id = ?', [$id]);
            if (!$row) admin_redirect('pages', 'That page no longer exists.', 'bad');
            $tpl = (string)$row['template'];
            $isOwner = Auth::isOwner(AdminKit::role($req));
            $blocks = Db::all('SELECT * FROM blocks WHERE page_id = ? ORDER BY sort, id', [$id]);
            $errors = [];
            $warnings = [];
            $values = $row;
            if ($req['method'] === 'POST') {
                $p = $req['post'];
                $fix = !empty($p['fix_dashes']);
                $txt = static fn(string $k, bool $multi = false): string => $fix ? AdminKit::fixDashes(AdminKit::str($p, $k, 0, $multi)) : AdminKit::str($p, $k, 0, $multi);
                if ($stale = AdminKit::stale($row, $p)) $errors[] = $stale;
                $title = $txt('title');
                if ($title === '' || mb_strlen($title) > 160) $errors[] = 'Add the page name (160 characters at most).';
                $h1 = $txt('h1');
                if (mb_strlen($h1) > 200) $errors[] = 'The H1 is 200 characters at most.';
                $seo = $txt('seo_title');
                if (mb_strlen($seo) > 200) $errors[] = 'The SEO title is 200 characters at most.';
                $meta = $txt('meta_description', true);
                if (mb_strlen($meta) > 320) $errors[] = 'The meta description is 320 characters at most.';
                $intro = AdminKit::rich($p, 'intro_html', $fix);
                $body = AdminKit::rich($p, 'body_html', $fix);
                $og = AdminKit::imageFromForm($req, 'og', (int)$row['og_media_id'] ?: null, trim($row['path'], '/') ?: 'home');
                if ($og['error']) $errors[] = 'Social image: ' . $og['error'];
                $parent = AdminKit::int($p, 'parent_id');
                if ($parent) {
                    $seen = [$id => true];
                    for ($x = $parent; $x; $x = (int)Db::value('SELECT parent_id FROM pages WHERE id = ?', [$x], 0)) {
                        if (isset($seen[$x])) { $errors[] = 'A page cannot sit under itself or one of its own sub-pages.'; break; }
                        $seen[$x] = true;
                    }
                }
                // The web address: changed only on purpose; the old one then redirects.
                $path = (string)$row['path'];
                if (!empty($p['change_path']) && $row['path'] !== '/') {
                    $new = Router::canonicalise('/' . trim(strtolower(AdminKit::str($p, 'path', 200)), '/') . '/');
                    if (!preg_match('#^/[a-z0-9][a-z0-9/_-]*/$#', $new) || mb_strlen($new) > 191) $errors[] = 'The web address uses lowercase letters, numbers, hyphens and slashes.';
                    elseif (preg_match($reserved, $new)) $errors[] = 'That address is used by the site itself. Choose another.';
                    elseif ($new !== $path && Db::value('SELECT 1 FROM pages WHERE path = ? AND id <> ?', [$new, $id])) $errors[] = 'Another page already uses ' . $new . '.';
                    else $path = $new;
                }
                // Template data: the fields below, or the owner's JSON.
                $data = json_decode((string)($row['data_json'] ?? ''), true) ?: [];
                $data = ContentAdmin::applyTemplateData($tpl, $data, is_array($p['data'] ?? null) ? $p['data'] : [], $warnings);
                foreach (ContentAdmin::templateFields($tpl) as $f) {   // 2026-10-28: images and PDFs of the page (data.media.{key})
                    $k = $f['key'];
                    $was = !empty($data['media'][$k]) ? (int)$data['media'][$k] : null;
                    if ($f['type'] === 'image') {
                        $img = AdminKit::imageFromForm($req, 'tplimg_' . $k, $was, (trim($row['path'], '/') ?: 'home') . '-' . $k);
                        if ($img['error']) $errors[] = $f['label'] . ': ' . $img['error'];
                        elseif ($img['id']) $data['media'][$k] = (int)$img['id'];
                        else unset($data['media'][$k]);
                    } elseif ($f['type'] === 'pdf') {
                        if (!empty($p['tplpdf_remove'][$k])) unset($data['media'][$k]);
                        if ($file = AdminKit::file($req, 'tplpdf_' . $k)) {
                            $pdf = AdminKit::storePdf($file, (trim($row['path'], '/') ?: 'document') . '-' . $k, false, AdminKit::uid($req));
                            if ($pdf['ok']) $data['media'][$k] = (int)$pdf['id'];
                            else $errors[] = $f['label'] . ': ' . $pdf['error'];
                        }
                    }
                }
                if (isset($data['media']) && !$data['media']) unset($data['media']);
                if ($isOwner && isset($p['data_raw']) && trim((string)$p['data_raw']) !== trim((string)($p['data_raw_before'] ?? ''))) {
                    $raw = trim((string)$p['data_raw']);
                    $parsed = $raw === '' ? [] : json_decode($raw, true);
                    if (!is_array($parsed)) $errors[] = 'The template data is not valid JSON.';
                    else $data = $parsed;
                }
                $locationId = $row['location_id'];
                if (array_key_exists('location', array_column(ContentAdmin::templateFields($tpl), null, 'key'))) {
                    $locationId = !empty($data['location']) ? (int)Db::value('SELECT id FROM locations WHERE slug = ?', [$data['location']], 0) ?: null : null;
                }
                $courseId = $row['course_id'];
                if (in_array($tpl, ContentAdmin::COURSE_TEMPLATES, true)) {
                    $courseId = AdminKit::int($p, 'course_id');
                    if ($courseId && !Db::value('SELECT 1 FROM courses WHERE id = ?', [$courseId])) $courseId = null;
                }
                // Blocks, in the order they were posted.
                $blockRows = [];
                $posted = is_array($p['blocks'] ?? null) ? $p['blocks'] : [];
                $mine = [];
                foreach ($blocks as $b) $mine[(int)$b['id']] = $b;
                $shared = ContentAdmin::sharedKeys();
                $sort = 0;
                foreach ($posted as $bid => $in) {
                    $bid = (int)$bid;
                    if (!isset($mine[$bid]) || !is_array($in)) continue;
                    $b = $mine[$bid];
                    $bt = Sanitizer::text($in['title'] ?? '', false, 250);
                    if ($fix) $bt = AdminKit::fixDashes($bt);
                    if (mb_strlen($bt) > 200) $errors[] = 'Block titles are 200 characters at most.';
                    $bb = AdminKit::rich($in, 'body', $fix);
                    $bd = json_decode((string)($b['data_json'] ?? ''), true) ?: [];
                    if ($b['type'] === 'cta') {
                        $cp = trim((string)($in['path'] ?? ''));
                        if ($cp !== '' && AdminKit::target($cp, false) === null) $errors[] = 'Call to action "' . $bt . '": the link must start with /.';
                        else $bd['path'] = $cp;
                    } elseif ($b['type'] === 'image') {
                        $mid = AdminKit::int($in, 'media_id');
                        if ($mid && !Db::value("SELECT 1 FROM media WHERE id = ? AND kind = 'image' AND is_private = 0", [$mid])) $errors[] = 'There is no image with the number ' . $mid . '.';
                        else $bd['media_id'] = $mid;
                    } elseif ($b['type'] === 'shared_ref') {
                        $key = (string)($in['key'] ?? '');
                        if (in_array($key, $shared, true)) $bd['key'] = $key;
                    } elseif ($b['type'] === 'hero_band') {
                        // Round 2 (WP-A): a band is a kicker (the title) and two lines (data_json.lines, read by partials/hero.php);
                        // each has its own limit (Content::HERO_BANDS) and the two lines together keep max_chars. The body keeps
                        // the same two lines as paragraphs (round 1's shape). Sanitizer::text drops tags and control characters.
                        $lim = Content::HERO_BANDS[(string)$b['block_key']] ?? [null, null, null, 40, 60, 60];
                        $name = 'Hero band ' . ($b['block_key'] ?: $bt);
                        $l1 = Sanitizer::text($in['line1'] ?? '', false, 250);
                        $l2 = Sanitizer::text($in['line2'] ?? '', false, 250);
                        if ($fix) { $l1 = AdminKit::fixDashes($l1); $l2 = AdminKit::fixDashes($l2); }
                        if (mb_strlen($bt) > (int)$lim[3]) $errors[] = $name . ': the kicker is ' . mb_strlen($bt) . ' characters; the limit is ' . (int)$lim[3] . '.';
                        if (mb_strlen($l1) > (int)$lim[4]) $errors[] = $name . ': line 1 is ' . mb_strlen($l1) . ' characters; the limit is ' . (int)$lim[4] . '. Short lines keep the scroll film readable.';
                        if (mb_strlen($l2) > (int)$lim[5]) $errors[] = $name . ': line 2 is ' . mb_strlen($l2) . ' characters; the limit is ' . (int)$lim[5] . '. Short lines keep the scroll film readable.';
                        $len = mb_strlen($l1) + mb_strlen($l2);
                        if (!empty($b['max_chars']) && $len > (int)$b['max_chars']) $errors[] = $name . ': the two lines are ' . $len . ' characters together; the limit is ' . (int)$b['max_chars'] . '.';
                        unset($bd['stage']);
                        $bd['lines'] = [$l1, $l2];
                        $bb = ($l1 !== '' || $l2 !== '') ? '<p>' . e($l1) . '</p><p>' . e($l2) . '</p>' : '';
                    }
                    $blockRows[$bid] = ['title' => $bt !== '' ? $bt : null, 'body_html' => $bb !== '' ? $bb : null,
                        'data_json' => $bd ? json_encode($bd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'is_visible' => AdminKit::flag($in, 'visible'), 'sort' => $sort += 10];
                }

                $data_json = $data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                $now = Time::nowUtc();
                $pageRow = [
                    'title' => $title, 'h1' => $h1 !== '' ? $h1 : null, 'seo_title' => $seo !== '' ? $seo : null, 'meta_description' => $meta !== '' ? $meta : null,
                    'og_media_id' => $og['id'], 'intro_html' => $intro !== '' ? $intro : null, 'body_html' => $body !== '' ? $body : null, 'data_json' => $data_json,
                    'location_id' => $locationId, 'course_id' => $courseId, 'parent_id' => $parent ?: null, 'path' => $path,
                    'robots' => AdminKit::pick($p['robots'] ?? 'index,follow', array_keys(ContentAdmin::ROBOTS), 'index,follow'),
                    'in_sitemap' => AdminKit::flag($p, 'in_sitemap'),
                    'status' => $row['path'] === '/' ? 'published' : AdminKit::pick($p['status'] ?? 'published', array_keys(ContentAdmin::PAGE_STATUSES), 'published'),
                    'updated_at' => $now, 'updated_by' => AdminKit::uid($req),
                ];
                if (!$errors) {
                    Db::tx(function () use ($id, $pageRow, $blockRows, $row, $path, $now, $req) {
                        Db::update('pages', $pageRow, 'id = ?', [$id]);
                        foreach ($blockRows as $bid => $br) Db::update('blocks', $br + ['updated_at' => $now, 'updated_by' => AdminKit::uid($req)], 'id = ?', [$bid]);
                        if ($path !== $row['path']) ContentAdmin::pageMoved((string)$row['path'], $path);
                    });
                    $afterSave($id);
                    $diff = AdminKit::diff($row, $pageRow);
                    admin_audit(($row['status'] !== 'published' && $pageRow['status'] === 'published') ? 'publish' : 'update', 'pages', (string)$id, 'Page saved: ' . $path, $diff);
                    $lint = AdminKit::lint($title, $h1, $intro, $body, $meta, ...array_map(static fn($b) => (string)$b['body_html'], $blockRows));
                    admin_redirect('pages/edit?id=' . $id, 'Saved.' . ($path !== $row['path'] ? ' The page moved to ' . $path . '; the old address redirects there.' : '')
                        . ($pageRow['status'] === 'draft' ? ' It is a draft: visitors get the not found page.' : '')
                        . ($warnings ? ' ' . implode(' ', $warnings) : '') . ($lint ? ' There are house style notes below.' : ''));
                }
                $values = array_merge($row, $pageRow, ['path' => $row['path']]);
                foreach ($blocks as $i => $b) if (isset($blockRows[(int)$b['id']])) $blocks[$i] = array_merge($b, $blockRows[(int)$b['id']]);
            }
            $warnings = array_merge($warnings, AdminKit::lint((string)$values['title'], (string)$values['h1'], (string)$values['intro_html'], (string)$values['body_html'], (string)$values['meta_description']));
            admin_render('pages/edit', [
                'title' => 'Edit page', 'row' => $row, 'values' => $values, 'blocks' => $blocks, 'errors' => $errors, 'warnings' => array_values(array_unique($warnings)),
                'fields' => ContentAdmin::templateFields($tpl), 'data' => json_decode((string)($values['data_json'] ?? ''), true) ?: [], 'isOwner' => $isOwner,
                'audit' => ContentAdmin::seo($values, 'page'), 'preview' => $previewUrl($id), 'shareUrl' => $previewUrl($id, true),
                'parents' => Db::pairs('SELECT id, path FROM pages WHERE id <> ? ORDER BY path', [$id]),
                'locations' => Db::pairs('SELECT slug, name FROM locations ORDER BY sort, name'),
                'shared' => Db::pairs('SELECT block_key, COALESCE(title, block_key) FROM blocks WHERE page_id IS NULL AND block_key IS NOT NULL ORDER BY block_key'),
                'courses' => in_array($tpl, ContentAdmin::COURSE_TEMPLATES, true)
                    ? array_column(array_map(static fn($c) => [$c['id'], $c['title'] . ($c['loc'] ? ', ' . $c['loc'] : '') . ($c['code'] ? ' (' . $c['code'] . ')' : '')],
                        Db::all('SELECT c.id, c.title, c.code, l.name AS loc FROM courses c LEFT JOIN locations l ON l.id = c.location_id ORDER BY l.sort, c.sort, c.id')), 1, 0)
                    : [],
            ]);
        }],

        'pages/new' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($reserved): never {
            $errors = [];
            $values = ['title' => '', 'path' => '', 'template' => 'content'];
            if ($req['method'] === 'POST') {
                $tpl = AdminKit::pick($req['post']['template'] ?? 'content', ['content', 'service'], 'content');
                $title = AdminKit::str($req['post'], 'title', 200);
                $given = AdminKit::str($req['post'], 'path', 200);
                $slugPath = $given !== '' ? $given : ($tpl === 'service' ? 'services/' . slugify($title) : slugify($title));
                $path = Router::canonicalise('/' . trim(strtolower($slugPath), '/') . '/');
                if ($title === '' || mb_strlen($title) > 160) $errors[] = 'Add the page name (160 characters at most).';
                if (!preg_match('#^/[a-z0-9][a-z0-9/_-]*/$#', $path) || mb_strlen($path) > 191) $errors[] = 'The web address uses lowercase letters, numbers, hyphens and slashes.';
                elseif (preg_match($reserved, $path)) $errors[] = 'That address is used by the site itself. Choose another.';
                elseif (Db::value('SELECT 1 FROM pages WHERE path = ?', [$path])) $errors[] = 'A page already uses ' . $path . '.';
                elseif ($hit = Redirects::lookup($path)) $errors[] = $path . ' redirects to ' . $hit['to'] . '. Delete that redirect first, or choose another address.';
                if (!$errors) {
                    $now = Time::nowUtc();
                    $data = $tpl === 'service'
                        ? ['name' => $title, 'short' => $title, 'icon' => 'house', 'kicker' => $title . ' in the GTA', 'how_kicker' => 'How we do it', 'how_title' => 'Done right, the first time.',
                           'way_kicker' => 'The way we work', 'way_title' => 'What you get on every job.',
                           'time' => "It depends on the size and requirements. When we visit you for your free estimate, we'll discuss the timeline in detail."]
                        : [];
                    $parent = $tpl === 'service' ? Db::value("SELECT id FROM pages WHERE path = '/services/'") : null;
                    $pid = Db::insert('pages', ['path' => $path, 'template' => $tpl, 'title' => $title, 'h1' => $title, 'status' => 'draft', 'robots' => 'index,follow',
                        'data_json' => $data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 'parent_id' => $parent ?: null,
                        'in_sitemap' => 1, 'sort' => (int)Db::value('SELECT MAX(sort) FROM pages', [], 0) + 10, 'created_at' => $now, 'updated_at' => $now, 'updated_by' => AdminKit::uid($req)]);
                    admin_audit('create', 'pages', (string)$pid, 'Page created: ' . $path);
                    admin_redirect('pages/edit?id=' . $pid, 'Created ' . $path . ' as a draft. ' . ($tpl === 'service' ? 'Fill in the service wording and photo, then set it to Published: it joins the Services lists by itself.' : 'Write it, then set it to Published.'));
                }
                $values = ['title' => $title, 'path' => $given, 'template' => $tpl];
            }
            admin_render('pages/new', ['title' => 'Add a page', 'values' => $values, 'errors' => $errors]);
        }],

        'pages/delete' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $pg = Db::one('SELECT * FROM pages WHERE id = ?', [$id]);
            if (!$pg) admin_redirect('pages', 'That page was already deleted.', 'bad');
            if ($pg['status'] !== 'draft') admin_redirect('pages/edit?id=' . $id, 'Only draft pages can be deleted. Set it to Draft first; live addresses keep their visitors.', 'bad');
            $uses = (int)Db::value("SELECT COUNT(*) FROM menu_items WHERE path = ? OR (kind = 'page' AND ref_id = ?)", [$pg['path'], $id], 0);
            if ($uses) admin_redirect('pages/edit?id=' . $id, 'A menu item still points at ' . $pg['path'] . '. Remove it in Menus first.', 'bad');
            Db::delete('pages', 'id = ?', [$id]);
            Search::remove('page', $id);
            Sitemap::invalidate();
            admin_audit('delete', 'pages', (string)$id, 'Page deleted: ' . $pg['path']);
            admin_redirect('pages', 'Deleted the draft ' . $pg['path'] . '.');
        }],

        'pages/block-add' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $pid = AdminKit::int($req['post'], 'page_id', 0) ?: 0;
            $pg = Db::one('SELECT id, template FROM pages WHERE id = ?', [$pid]);
            if (!$pg) admin_redirect('pages', 'That page no longer exists.', 'bad');
            $types = array_keys(ContentAdmin::BLOCK_TYPES);
            if ($pg['template'] !== 'home') $types = array_values(array_diff($types, ['hero_band']));
            $type = AdminKit::pick($req['post']['type'] ?? '', $types, '');
            if ($type === '') admin_redirect('pages/edit?id=' . $pid . '#blocks', 'Choose the kind of block.', 'bad');
            $title = AdminKit::str($req['post'], 'title', 200);
            $data = match ($type) { 'shared_ref' => ['key' => ContentAdmin::sharedKeys()[0] ?? ''], 'cta' => ['path' => ''], default => [] };
            $bid = Db::insert('blocks', ['page_id' => $pid, 'type' => $type, 'title' => $title !== '' ? $title : null, 'data_json' => $data ? json_encode($data) : null,
                'sort' => (int)Db::value('SELECT MAX(sort) FROM blocks WHERE page_id = ?', [$pid], 0) + 10, 'is_visible' => 1, 'updated_at' => Time::nowUtc(), 'updated_by' => AdminKit::uid($req)]);
            Db::update('pages', ['updated_at' => Time::nowUtc()], 'id = ?', [$pid]);
            if (class_exists('Content')) Content::flush();
            admin_audit('create', 'blocks', (string)$bid, 'Block added to page #' . $pid . ': ' . $type);
            admin_redirect('pages/edit?id=' . $pid . '#block-' . $bid, 'Added a ' . strtolower(ContentAdmin::BLOCK_TYPES[$type]) . ' block at the end. Fill it in, then save the page.');
        }],

        'pages/block-delete' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($afterSave): never {
            $bid = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $b = Db::one('SELECT * FROM blocks WHERE id = ?', [$bid]);
            if (!$b || !$b['page_id']) admin_redirect('pages', 'That block no longer exists.', 'bad');
            if (!empty($b['block_key'])) admin_redirect('pages/edit?id=' . $b['page_id'] . '#block-' . $bid, 'The page design uses this block (' . $b['block_key'] . '). Hide it instead of deleting it.', 'bad');
            Db::delete('blocks', 'id = ?', [$bid]);
            Db::update('pages', ['updated_at' => Time::nowUtc()], 'id = ?', [(int)$b['page_id']]);
            $afterSave((int)$b['page_id']);
            admin_audit('delete', 'blocks', (string)$bid, 'Block deleted from page #' . $b['page_id']);
            admin_redirect('pages/edit?id=' . $b['page_id'] . '#blocks', 'Deleted the block.');
        }],

        // ---------------- shared blocks ----------------
        'pages/shared' => ['perm' => $perm, 'methods' => ['GET'], 'handler' => static function (array $req): never {
            $rows = Db::all('SELECT * FROM blocks WHERE page_id IS NULL ORDER BY block_key, id');
            foreach ($rows as $i => $r) $rows[$i]['usage'] = $r['block_key'] ? ContentAdmin::sharedUsage((string)$r['block_key']) : [];
            admin_render('pages/shared', ['title' => 'Shared blocks', 'rows' => $rows]);
        }],
        'pages/shared-edit' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req): never {
            $id = AdminKit::int($req['query'], 'id', 0) ?: 0;
            $row = $id ? Db::one('SELECT * FROM blocks WHERE id = ? AND page_id IS NULL', [$id]) : null;
            if ($id && !$row) admin_redirect('pages/shared', 'That block no longer exists.', 'bad');
            $errors = [];
            $values = $row ?? ['block_key' => 'shared.', 'title' => '', 'body_html' => '', 'is_visible' => 1, 'type' => 'rich', 'updated_at' => null];
            $warnings = [];
            if ($req['method'] === 'POST') {
                $p = $req['post'];
                $fix = !empty($p['fix_dashes']);
                if ($stale = AdminKit::stale($row, $p)) $errors[] = $stale;
                $key = $row ? (string)$row['block_key'] : strtolower(AdminKit::str($p, 'block_key', 80));
                if (!$row && (!preg_match('/^shared\.[a-z0-9._-]{2,72}$/', $key) || Db::value('SELECT 1 FROM blocks WHERE block_key = ?', [$key]))) $errors[] = 'Choose a new key that starts with "shared.", for example shared.parking.';
                $title = AdminKit::str($p, 'title', 250);
                if ($fix) $title = AdminKit::fixDashes($title);
                if (mb_strlen($title) > 200) $errors[] = 'The title is 200 characters at most.';
                $body = AdminKit::rich($p, 'body_html', $fix);
                $data = ['title' => $title !== '' ? $title : null, 'body_html' => $body !== '' ? $body : null, 'is_visible' => AdminKit::flag($p, 'is_visible'),
                    'updated_at' => Time::nowUtc(), 'updated_by' => AdminKit::uid($req)];
                if (!$errors) {
                    if ($row) Db::update('blocks', $data, 'id = ?', [$id]);
                    else $id = Db::insert('blocks', $data + ['page_id' => null, 'block_key' => $key, 'type' => 'rich', 'sort' => 0]);
                    foreach (ContentAdmin::sharedUsage($key) as $u) ContentAdmin::reindexPage((int)$u['id']);
                    if (class_exists('Content')) Content::flush();
                    admin_audit($row ? 'update' : 'create', 'blocks', (string)$id, 'Shared block saved: ' . $key, $row ? AdminKit::diff($row, $data) : []);
                    $n = count(ContentAdmin::sharedUsage($key));
                    admin_redirect('pages/shared-edit?id=' . $id, 'Saved. ' . ($n === 1 ? '1 page uses' : $n . ' pages use') . ' this block and now show the change.');
                }
                $values = array_merge($values, $data, ['block_key' => $key]);
            }
            $warnings = AdminKit::lint((string)$values['title'], (string)$values['body_html']);
            admin_render('pages/shared-edit', ['title' => 'Shared block', 'row' => $row, 'values' => $values, 'errors' => $errors, 'warnings' => $warnings,
                'usage' => $row ? ContentAdmin::sharedUsage((string)$row['block_key']) : []]);
        }],
        'pages/shared-delete' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $b = Db::one('SELECT * FROM blocks WHERE id = ? AND page_id IS NULL', [$id]);
            if (!$b) admin_redirect('pages/shared', 'That block was already deleted.', 'bad');
            if ($b['block_key'] && ContentAdmin::sharedUsage((string)$b['block_key'])) admin_redirect('pages/shared-edit?id=' . $id, 'Pages still use this block. Remove it from those pages first.', 'bad');
            Db::delete('blocks', 'id = ?', [$id]);
            if (class_exists('Content')) Content::flush();
            admin_audit('delete', 'blocks', (string)$id, 'Shared block deleted: ' . $b['block_key']);
            admin_redirect('pages/shared', 'Deleted ' . $b['block_key'] . '.');
        }],
    ],
];
