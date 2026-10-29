<?php
/**
 * Admin > Site > Redirects and 404s (spec 4.2.12). Redirects: from (normalised,
 * lowercase, trailing slash), to, code (301, 302, 410), source, hits, last hit;
 * no chains and no loops (older redirects that pointed at a new source are
 * repointed); "Test a URL" shows the full resolution path; CSV import (with a
 * preview) and export. 404 log: path, hits, last seen, last referrer;
 * "Redirect to..." (source 404log) and Ignore.
 */
defined('ICM') or exit;

$perm = 'redirects.edit';
$codes = [301 => '301 Moved for good', 302 => '302 Moved for now', 410 => '410 Removed'];
$sources = ['seed' => 'Launch map', 'admin' => 'Added here', '404log' => 'From the 404 log', 'slug_change' => 'Address change'];

/** Where a path finally lands: the hops, then what serves the last address. */
$test = static function (string $path): array {
    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) $path = (string)parse_url($path, PHP_URL_PATH);
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    $hops = Redirects::trace($path);
    $final = $hops ? (string)end($hops)['to'] : $path;
    $finalCode = $hops ? (int)end($hops)['code'] : 200;
    $landing = 'Not found (404)';
    if ($finalCode === 410) $landing = 'Removed (410)';
    elseif ($finalCode === 404) $landing = 'Not found, quietly (404)';
    elseif (preg_match('#^https?://#i', $final) && !str_starts_with($final, rtrim((string)cfg('base_url'), '/'))) $landing = 'Another website';
    else {
        $c = Router::canonicalise((string)parse_url($final, PHP_URL_PATH));
        if (Db::value("SELECT 1 FROM pages WHERE path = ? AND status = 'published'", [$c])) $landing = 'A live page (200)';
        elseif (preg_match('#^/blog/([^/]+)/$#', $c, $m) && Redirects::isPublishedPost($m[1])) $landing = 'A live blog post (200)';
        elseif (preg_match('#^/(feed|search|sitemap\.xml|robots\.txt|calendar/ics)#', $c)) $landing = 'A site feature (200)';
        elseif (preg_match('#^/news/page/\d+/$#', $c)) $landing = 'A news page (200)';
    }
    return ['path' => $path, 'hops' => $hops, 'final' => $final, 'landing' => $landing, 'ok' => count($hops) <= 1 && str_contains($landing, '200')];
};

return [
    'nav' => ['group' => 'Site', 'label' => 'Redirects', 'path' => 'redirects', 'perm' => $perm, 'sort' => 10,
              'badge' => static fn(): int => (int)Db::value('SELECT COUNT(*) FROM not_found_log WHERE last_seen >= ? AND ignored = 0 AND hits >= 3', [Time::utcAgo(7 * 86400)], 0)],
    'routes' => [
        'redirects' => ['perm' => $perm, 'methods' => ['GET'], 'handler' => static function (array $req) use ($codes, $sources, $test): never {
            $q = AdminKit::str($req['query'], 'q', 190);
            $source = AdminKit::pick($req['query']['source'] ?? '', array_keys($sources), '');
            $where = [];
            $args = [];
            if ($q !== '') {
                $like = '%' . Db::like(strtolower($q)) . '%';
                $where[] = '(from_path LIKE ?' . Db::LIKE_ESCAPE . ' OR to_path LIKE ?' . Db::LIKE_ESCAPE . ')';
                array_push($args, $like, $like);
            }
            if ($source !== '') {
                $where[] = 'source = ?';
                $args[] = $source;
            }
            $pg = Db::paginate('SELECT * FROM redirects' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY hits DESC, from_path', $args, AdminKit::int($req['query'], 'page', 1) ?? 1, 50);
            $t = trim((string)($req['query']['test'] ?? '')) !== '' ? $test(AdminKit::str($req['query'], 'test', 500)) : null;
            admin_render('redirects/index', ['title' => 'Redirects', 'pg' => $pg, 'q' => $q, 'source' => $source, 'codes' => $codes, 'sources' => $sources, 'test' => $t,
                'n404' => (int)Db::value('SELECT COUNT(*) FROM not_found_log WHERE ignored = 0', [], 0)]);
        }],

        'redirects/edit' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($codes): never {
            $id = AdminKit::int($req['query'], 'id', 0) ?: 0;
            $row = $id ? Db::one('SELECT * FROM redirects WHERE id = ?', [$id]) : null;
            if ($id && !$row) admin_redirect('redirects', 'That redirect no longer exists.', 'bad');
            $errors = [];
            $from404 = preg_match('/^[a-f0-9]{64}$/', (string)($req['query']['from404'] ?? $req['post']['from404'] ?? '')) ? (string)($req['query']['from404'] ?? $req['post']['from404']) : '';
            $values = $row ?? ['from_path' => AdminKit::str($req['query'], 'from', 191), 'to_path' => '', 'code' => 301, 'note' => ''];
            if ($req['method'] === 'POST') {
                $p = $req['post'];
                $from = AdminKit::str($p, 'from_path', 500);
                $to = AdminKit::str($p, 'to_path', 500);
                $code = (int)($p['code'] ?? 301);
                $note = AdminKit::str($p, 'note', 255);
                if (mb_strlen(Redirects::normalizeFrom($from ?: '/')) > 191) $errors[] = 'The old address is 191 characters at most.';
                if ($problem = ContentAdmin::redirectProblem($from, $to, $code, $row ? $id : null)) $errors[] = $problem;
                if (!$errors) {
                    $r = ContentAdmin::saveRedirect($from, $to, $code, $row ? (string)$row['source'] : ($from404 !== '' ? '404log' : 'admin'), $note, $row ? $id : null);
                    if ($from404 !== '') Db::delete('not_found_log', 'path_hash = ?', [$from404]);
                    $data = ['from_path' => Redirects::normalizeFrom($from), 'to_path' => $to, 'code' => $code, 'note' => $note];
                    admin_audit($row ? 'update' : 'create', 'redirects', (string)$r['id'], 'Redirect saved: ' . $data['from_path'] . ' to ' . ($code === 410 ? '(removed)' : $to), $row ? AdminKit::diff($row, $data) : []);
                    admin_redirect($from404 !== '' ? 'redirects/404' : 'redirects?test=' . rawurlencode($data['from_path']), 'Saved. ' . $data['from_path'] . ($code === 410 ? ' now answers "removed".' : ' now goes to ' . $to . '.')
                        . ($r['fixed'] ? ' ' . $r['fixed'] . ' older ' . ($r['fixed'] === 1 ? 'redirect was' : 'redirects were') . ' pointed straight there too, so nothing chains.' : ''));
                }
                $values = ['from_path' => $from, 'to_path' => $to, 'code' => $code, 'note' => $note];
            }
            admin_render('redirects/edit', ['title' => $row ? 'Edit redirect' : 'Add a redirect', 'row' => $row, 'values' => $values, 'errors' => $errors, 'codes' => $codes,
                'from404' => $from404, 'pages' => AdminKit::pageOptions(true)]);
        }],

        'redirects/delete' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $r = Db::one('SELECT * FROM redirects WHERE id = ?', [$id]);
            if (!$r) admin_redirect('redirects', 'That redirect was already deleted.', 'bad');
            Db::delete('redirects', 'id = ?', [$id]);
            admin_audit('delete', 'redirects', (string)$id, 'Redirect deleted: ' . $r['from_path'] . ' to ' . $r['to_path']);
            admin_redirect('redirects', 'Deleted the redirect from ' . $r['from_path'] . '. That address now shows the not found page unless a page lives there.');
        }],

        'redirects/export' => ['perm' => $perm, 'methods' => ['GET'], 'handler' => static function (array $req): never {
            $rows = [];
            foreach (Db::all('SELECT * FROM redirects ORDER BY from_path') as $r) {
                $rows[] = [$r['from_path'], $r['to_path'], (int)$r['code'], $r['source'], $r['note'], (int)$r['hits'], $r['last_hit_at'] ? (Time::toLocal((string)$r['last_hit_at'])?->format('Y-m-d H:i') ?? '') : ''];
            }
            admin_audit('export', 'redirects', null, 'Redirects exported (' . count($rows) . ' rows)');
            AdminKit::sendCsv('redirects-' . Time::today() . '.csv', ['from_path', 'to_path', 'code', 'source', 'note', 'hits', 'last_hit'], $rows);
        }],

        'redirects/import' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req): never {
            $errors = [];
            $preview = null;
            $batch = '';
            if ($req['method'] === 'POST') {
                $f = AdminKit::file($req, 'csv');
                if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_file((string)$f['tmp_name'])) {
                    $errors[] = 'Choose a CSV file.';
                } else {
                    $csv = AdminKit::readCsv((string)$f['tmp_name'], 2000);
                    if ($csv['error']) $errors[] = $csv['error'];
                    elseif (!in_array('from_path', $csv['header'], true) || !in_array('to_path', $csv['header'], true)) $errors[] = 'The first row must name the columns from_path and to_path (code and note are optional).';
                    else {
                        $preview = [];
                        foreach ($csv['rows'] as $i => $r) {
                            $from = (string)($r['from_path'] ?? '');
                            $to = (string)($r['to_path'] ?? '');
                            $code = (int)(($r['code'] ?? '') !== '' ? $r['code'] : 301);
                            $existing = $from !== '' && str_starts_with($from, '/') ? Db::value('SELECT id FROM redirects WHERE from_path = ?', [Redirects::normalizeFrom($from)]) : null;
                            $problem = ContentAdmin::redirectProblem($from, $to, $code, $existing ? (int)$existing : null);
                            $preview[] = ['line' => $i + 2, 'from' => $from, 'to' => $to, 'code' => $code, 'note' => mb_substr((string)($r['note'] ?? ''), 0, 255),
                                'action' => $problem ? 'error' : ($existing ? 'update' : 'new'), 'error' => $problem];
                        }
                        if (!$preview) $errors[] = 'The file has no rows.';
                        else $batch = AdminKit::stashPut('redirects', ['rows' => $preview]);
                    }
                }
            }
            admin_render('redirects/import', ['title' => 'Import redirects', 'errors' => $errors, 'preview' => $preview, 'batch' => $batch]);
        }],
        'redirects/import-apply' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $data = AdminKit::stashGet('redirects', (string)($req['post']['batch'] ?? ''));
            if (!$data) admin_redirect('redirects/import', 'That preview has expired. Upload the file again.', 'bad');
            $c = ['new' => 0, 'update' => 0, 'error' => 0];
            foreach ((array)$data['rows'] as $r) {
                if ($r['action'] === 'error') { $c['error']++; continue; }
                $existing = Db::value('SELECT id FROM redirects WHERE from_path = ?', [Redirects::normalizeFrom((string)$r['from'])]);
                // Checked again in file order: an earlier row may have changed what is allowed.
                if (ContentAdmin::redirectProblem((string)$r['from'], (string)$r['to'], (int)$r['code'], $existing ? (int)$existing : null)) { $c['error']++; continue; }
                ContentAdmin::saveRedirect((string)$r['from'], (string)$r['to'], (int)$r['code'], 'admin', $r['note'] !== '' ? $r['note'] : null, $existing ? (int)$existing : null);
                $c[$existing ? 'update' : 'new']++;
            }
            admin_audit('import', 'redirects', null, 'Redirects imported: ' . $c['new'] . ' new, ' . $c['update'] . ' updated, ' . $c['error'] . ' skipped');
            admin_redirect('redirects', 'Imported: ' . $c['new'] . ' new, ' . $c['update'] . ' updated' . ($c['error'] ? ', ' . $c['error'] . ' skipped with problems' : '') . '.');
        }],

        // ---------------- 404 log ----------------
        'redirects/404' => ['perm' => $perm, 'methods' => ['GET'], 'handler' => static function (array $req): never {
            $days = AdminKit::pick($req['query']['days'] ?? '30', ['7', '30', 'all'], '30');
            $ignored = AdminKit::flag($req['query'], 'ignored');
            $where = ['ignored = ?'];
            $args = [$ignored];
            if ($days !== 'all') {
                $where[] = 'last_seen >= ?';
                $args[] = Time::utcAgo((int)$days * 86400);
            }
            $pg = Db::paginate('SELECT * FROM not_found_log WHERE ' . implode(' AND ', $where) . ' ORDER BY hits DESC, last_seen DESC', $args, AdminKit::int($req['query'], 'page', 1) ?? 1, 50);
            admin_render('redirects/notfound', ['title' => '404 log', 'pg' => $pg, 'days' => $days, 'ignored' => $ignored,
                'nRedirects' => (int)Db::value('SELECT COUNT(*) FROM redirects', [], 0)]);
        }],
        'redirects/404-ignore' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $hash = (string)($req['post']['hash'] ?? '');
            $undo = !empty($req['post']['undo']);
            if (!preg_match('/^[a-f0-9]{64}$/', $hash)) admin_redirect('redirects/404', 'That entry no longer exists.', 'bad');
            $path = (string)Db::value('SELECT path FROM not_found_log WHERE path_hash = ?', [$hash], '');
            Db::update('not_found_log', ['ignored' => $undo ? 0 : 1], 'path_hash = ?', [$hash]);
            admin_audit('update', 'not_found_log', substr($hash, 0, 16), ($undo ? 'Shown again: ' : 'Ignored: ') . mb_substr($path, 0, 180));
            admin_redirect(admin_back(admin_url('redirects/404')), ($undo ? 'Shown again: ' : 'Ignored: ') . $path);
        }],
    ],
];
