<?php
/**
 * Admin > Inbox: every estimate request from the site's forms (and the ones caught as spam). Cards with
 * Mark handled and Assign to me; a detail page with every field, tap-to-call and tap-to-email, a notes
 * thread, status and assignment, and Delete (owner and manager). CSV export by date range, and the
 * notification settings with "Send a test notification".
 */
defined('ICM') or exit;

$tabs = [
    'contact' => 'Estimate requests',
    'spam'    => 'Spam',
];
$statuses = ['new' => 'New', 'in_progress' => 'In progress', 'handled' => 'Handled', 'spam' => 'Spam', 'archived' => 'Archived'];
$readOnly = ['newsletter', 'unsubscribe'];

$scope = static fn(array $req): ?array => class_exists('Auth') ? Auth::inboxScope($req['user']) : null;
$canSee = static fn(?array $scope, string $type): bool => $scope === null || in_array($type, $scope, true);
$isBoss = static fn(array $req): bool => in_array(AdminKit::role($req), ['owner', 'manager'], true);
$payload = static fn(array $r): array => is_array($p = json_decode((string)$r['payload_json'], true)) ? $p : [];

/** The one-line summary on a card, by form type. */
$summary = static function (array $r) use ($payload): string {
    $p = $payload($r);
    $s = static fn($v): string => is_array($v) ? implode(', ', array_map('strval', $v)) : trim((string)$v);
    return mb_strimwidth(match ((string)$r['form_type']) {
        'contact'        => (string)($r['subject'] ?: $s($p['message'] ?? '')),
        'volunteer'      => trim('Requirement: ' . ($s($p['requirement'] ?? '') ?: 'not said') . '. ' . ($s($p['age_status'] ?? '') !== '' && str_contains($s($p['age_status'] ?? ''), 'under') ? 'Under 18.' : '')),
        'job'            => 'Position: ' . ($s($p['job_title'] ?? '') ?: $s($p['job'] ?? '')) . ($s($p['location_pref'] ?? '') !== '' ? ', ' . $s($p['location_pref']) : ''),
        'ranking_signup' => trim($s($p['player_first'] ?? '') . ' ' . $s($p['player_last'] ?? '')) . ($r['event_date'] ? ', ' . Time::long((string)$r['event_date']) : '') . ($s($p['category_label'] ?? '') !== '' ? ', ' . $s($p['category_label']) : ''),
        'newsletter'     => 'Newsletter signup' . ($s($p['first_name'] ?? '') !== '' ? ': ' . $s($p['first_name']) : ''),
        'unsubscribe'    => 'Unsubscribe request',
        'waitlist'       => (string)($r['subject'] ?: 'Waitlist'),
        default          => (string)$r['subject'],
    }, 0, 140, '...');
};

$find = static function (array $req, int $id) use ($scope, $canSee): array {
    $r = Db::one('SELECT fs.*, u.name AS assigned_name FROM form_submissions fs LEFT JOIN admin_users u ON u.id = fs.assigned_to WHERE fs.id = ?', [$id]);
    if (!$r || !$canSee($scope($req), (string)$r['form_type'])) admin_redirect('inbox', 'That message no longer exists.', 'bad');
    return $r;
};

$fileFor = static function (array $f): ?string {
    $rel = ltrim(str_replace('\\', '/', (string)$f['stored_path']), '/');
    if ($rel === '' || str_contains($rel, '..')) return null;
    $root = realpath(privatePath());
    $abs = realpath(privatePath($rel));
    if ($root === false || $abs === false || !is_file($abs)) return null;
    return str_starts_with(str_replace('\\', '/', $abs), rtrim(str_replace('\\', '/', $root), '/') . '/') ? $abs : null;
};

return [
    'nav' => ['group' => 'Inbox', 'label' => 'Inbox', 'path' => 'inbox', 'perm' => 'inbox.view', 'sort' => 10,
              'badge' => static function () use ($scope): int {
                  $sc = class_exists('Auth') ? Auth::inboxScope() : null;
                  $types = $sc ?? ['contact'];
                  $types = array_values(array_diff($types, ['newsletter', 'unsubscribe', 'waitlist']));
                  if (!$types) return 0;
                  return (int)Db::value('SELECT COUNT(*) FROM form_submissions WHERE status = ? AND form_type IN (' . Db::in($types) . ')', array_merge(['new'], $types), 0);
              }],
    'routes' => [
        'inbox' => ['perm' => 'inbox.view', 'methods' => ['GET'], 'handler' => static function (array $req) use ($tabs, $statuses, $scope, $canSee, $summary): never {
            $sc = $scope($req);
            $visible = array_filter($tabs, static fn($label, $k) => $k === 'spam' ? $sc === null : $canSee($sc, $k), ARRAY_FILTER_USE_BOTH);
            $type = (string)($req['query']['type'] ?? array_key_first($visible));
            if (!isset($visible[$type])) $type = (string)array_key_first($visible);
            $show = AdminKit::pick($req['query']['show'] ?? 'open', ['open', 'handled', 'all'], 'open');
            $q = AdminKit::str($req['query'], 'q', 100);
            $where = [];
            $args = [];
            if ($type === 'spam') {
                $where[] = "fs.status = 'spam'";
            } else {
                $types = $type === 'newsletter' ? ['newsletter', 'unsubscribe'] : [$type];
                $where[] = 'fs.form_type IN (' . Db::in($types) . ')';
                array_push($args, ...$types);
                $where[] = "fs.status <> 'spam'";
                if ($show === 'open' && $type !== 'newsletter') $where[] = "fs.status IN ('new', 'in_progress')";
                if ($show === 'handled') $where[] = "fs.status IN ('handled', 'archived')";
            }
            if ($sc !== null) {
                $where[] = 'fs.form_type IN (' . Db::in($sc) . ')';
                array_push($args, ...$sc);
            }
            if ($q !== '') {
                $like = '%' . Db::like($q) . '%';
                $where[] = '(fs.name LIKE ?' . Db::LIKE_ESCAPE . ' OR fs.email LIKE ?' . Db::LIKE_ESCAPE . ' OR fs.phone LIKE ?' . Db::LIKE_ESCAPE . ' OR fs.subject LIKE ?' . Db::LIKE_ESCAPE . ')';
                array_push($args, $like, $like, $like, $like);
            }
            $pg = Db::paginate('SELECT fs.*, u.name AS assigned_name FROM form_submissions fs LEFT JOIN admin_users u ON u.id = fs.assigned_to WHERE '
                . implode(' AND ', $where) . ' ORDER BY fs.created_at DESC, fs.id DESC', $args, AdminKit::int($req['query'], 'page', 1) ?? 1, 25);
            foreach ($pg['rows'] as &$r) $r['summary'] = $summary($r);
            unset($r);
            $counts = [];
            foreach (Db::all("SELECT form_type, COUNT(*) AS n FROM form_submissions WHERE status = 'new' GROUP BY form_type") as $c) $counts[$c['form_type']] = (int)$c['n'];
            $counts['newsletter'] = ($counts['newsletter'] ?? 0) + ($counts['unsubscribe'] ?? 0);
            $counts['spam'] = (int)Db::value("SELECT COUNT(*) FROM form_submissions WHERE status = 'spam'", [], 0);
            admin_render('inbox/list', ['title' => 'Inbox', 'tabs' => $visible, 'type' => $type, 'show' => $show, 'q' => $q, 'pg' => $pg, 'counts' => $counts,
                'statuses' => $statuses, 'canExport' => admin_can('inbox.export') && $type !== 'spam', 'canSettings' => $sc === null && in_array(AdminKit::role($req), ['owner', 'manager'], true)]);
        }],

        'inbox/view' => ['perm' => 'inbox.view', 'methods' => ['GET'], 'handler' => static function (array $req) use ($find, $payload, $statuses, $readOnly, $isBoss): never {
            $r = $find($req, AdminKit::int($req['query'], 'id', 0) ?: 0);
            $p = $payload($r);
            $type = (string)$r['form_type'];
            $rows = class_exists('Forms') ? Forms::displayRows($type, $p) : array_map(static fn($k, $v) => [(string)$k, is_array($v) ? implode(', ', $v) : (string)$v], array_keys($p), $p);
            $files = Db::all('SELECT * FROM form_files WHERE submission_id = ? ORDER BY id', [(int)$r['id']]);
            $notes = Db::all('SELECT n.*, u.name AS author FROM form_notes n LEFT JOIN admin_users u ON u.id = n.user_id WHERE n.submission_id = ? ORDER BY n.created_at, n.id', [(int)$r['id']]);
            $users = Db::pairs("SELECT id, name FROM admin_users WHERE is_active = 1 ORDER BY name");
            $st = $statuses + ($type === 'waitlist' ? ['offered' => 'Offered', 'registered' => 'Registered', 'withdrawn' => 'Withdrawn'] : []);
            admin_render('inbox/view', ['title' => 'Message', 'r' => $r, 'rows' => $rows, 'files' => $files, 'notes' => $notes, 'users' => $users,
                'statuses' => $st, 'readOnly' => in_array($type, $readOnly, true), 'canDelete' => $isBoss($req), 'label' => (string)(Db::value('SELECT label FROM form_types WHERE type_key = ?', [$type]) ?: ucfirst($type))]);
        }],

        'inbox/update' => ['perm' => 'inbox.edit', 'methods' => ['POST'], 'handler' => static function (array $req) use ($find, $statuses): never {
            $r = $find($req, AdminKit::int($req['post'], 'id', 0) ?: 0);
            $all = $statuses + ['offered' => 1, 'registered' => 1, 'withdrawn' => 1];
            $to = AdminKit::pick($req['post']['status'] ?? $r['status'], array_keys($all), (string)$r['status']);
            $assign = AdminKit::int($req['post'], 'assigned_to');
            if ($assign && !Db::value('SELECT 1 FROM admin_users WHERE id = ? AND is_active = 1', [$assign])) $assign = null;
            $now = Time::nowUtc();
            $data = ['status' => $to, 'assigned_to' => $assign, 'updated_at' => $now];
            if (in_array($to, ['handled', 'registered', 'withdrawn'], true) && empty($r['handled_at'])) $data['handled_at'] = $now;
            Db::update('form_submissions', $data, 'id = ?', [(int)$r['id']]);
            admin_audit('update', 'form_submissions', (string)$r['id'], 'Inbox message updated', AdminKit::diff($r, $data));
            admin_redirect('inbox/view?id=' . $r['id'], 'Saved.');
        }],
        'inbox/handled' => ['perm' => 'inbox.edit', 'methods' => ['POST'], 'handler' => static function (array $req) use ($find): never {
            $r = $find($req, AdminKit::int($req['post'], 'id', 0) ?: 0);
            Db::update('form_submissions', ['status' => 'handled', 'handled_at' => Time::nowUtc(), 'updated_at' => Time::nowUtc()], 'id = ?', [(int)$r['id']]);
            admin_audit('status', 'form_submissions', (string)$r['id'], 'Marked handled', ['status' => [$r['status'], 'handled']]);
            admin_redirect(admin_back(admin_url('inbox', ['type' => $r['form_type']])), 'Marked handled: ' . ($r['name'] ?: 'the message') . '.');
        }],
        'inbox/assign' => ['perm' => 'inbox.edit', 'methods' => ['POST'], 'handler' => static function (array $req) use ($find): never {
            $r = $find($req, AdminKit::int($req['post'], 'id', 0) ?: 0);
            $data = ['assigned_to' => AdminKit::uid($req), 'updated_at' => Time::nowUtc()] + ($r['status'] === 'new' ? ['status' => 'in_progress'] : []);
            Db::update('form_submissions', $data, 'id = ?', [(int)$r['id']]);
            admin_audit('update', 'form_submissions', (string)$r['id'], 'Assigned to ' . ($req['user']['name'] ?? 'me'), AdminKit::diff($r, $data));
            admin_redirect(admin_back(admin_url('inbox', ['type' => $r['form_type']])), 'Assigned to you.');
        }],
        'inbox/note' => ['perm' => 'inbox.edit', 'methods' => ['POST'], 'handler' => static function (array $req) use ($find): never {
            $r = $find($req, AdminKit::int($req['post'], 'id', 0) ?: 0);
            $note = AdminKit::str($req['post'], 'note', 4000, true);
            if ($note === '') admin_redirect('inbox/view?id=' . $r['id'] . '#notes', 'Write the note first.', 'bad');
            Db::insert('form_notes', ['submission_id' => (int)$r['id'], 'user_id' => AdminKit::uid($req), 'note' => $note, 'created_at' => Time::nowUtc()]);
            Db::update('form_submissions', ['updated_at' => Time::nowUtc()], 'id = ?', [(int)$r['id']]);
            admin_audit('update', 'form_submissions', (string)$r['id'], 'Note added');
            admin_redirect('inbox/view?id=' . $r['id'] . '#notes', 'Note added.');
        }],
        'inbox/delete' => ['perm' => 'inbox.edit', 'methods' => ['POST'], 'handler' => static function (array $req) use ($find, $isBoss, $fileFor): never {
            if (!$isBoss($req)) admin_redirect('inbox', 'Only the owner and managers can delete messages.', 'bad');
            $r = $find($req, AdminKit::int($req['post'], 'id', 0) ?: 0);
            $n = 0;
            foreach (Db::all('SELECT * FROM form_files WHERE submission_id = ?', [(int)$r['id']]) as $f) {
                if (class_exists('Upload') && method_exists('Upload', 'remove')) Upload::remove((string)$f['stored_path']);
                elseif ($abs = $fileFor($f)) @unlink($abs);
                $n++;
            }
            Db::delete('form_submissions', 'id = ?', [(int)$r['id']]);
            admin_audit('delete', 'form_submissions', (string)$r['id'], 'Deleted a ' . $r['form_type'] . ' message' . ($n ? ' and ' . $n . ' file(s)' : ''));
            admin_redirect(admin_url('inbox', ['type' => $r['status'] === 'spam' ? 'spam' : $r['form_type']]), 'Deleted' . ($n ? ', with its files' : '') . '.');
        }],
        'inbox/file' => ['perm' => 'inbox.view', 'methods' => ['GET'], 'handler' => static function (array $req) use ($find, $fileFor): never {
            $f = Db::one('SELECT * FROM form_files WHERE id = ?', [AdminKit::int($req['query'], 'id', 0) ?: 0]);
            if (!$f) admin_redirect('inbox', 'That file no longer exists.', 'bad');
            $find($req, (int)$f['submission_id']);
            $abs = $fileFor($f);
            if (!$abs) admin_redirect('inbox/view?id=' . $f['submission_id'], 'The file is missing from private storage.', 'bad');
            admin_audit('export', 'form_files', (string)$f['id'], 'File downloaded from submission #' . $f['submission_id']);
            $name = preg_replace('/[^A-Za-z0-9._ -]+/', '', (string)$f['original_name']) ?: 'file';
            while (ob_get_level() > 0) ob_end_clean();
            Headers::admin();
            header('Content-Type: ' . (preg_match('#^[a-z]+/[a-z0-9.+-]+$#', (string)$f['mime']) ? $f['mime'] : 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string)filesize($abs));
            header('X-Content-Type-Options: nosniff');
            readfile($abs);
            exit;
        }],
        'inbox/export' => ['perm' => 'inbox.export', 'methods' => ['GET'], 'handler' => static function (array $req) use ($scope, $canSee, $payload): never {
            $type = (string)($req['query']['type'] ?? '');
            if (!class_exists('Forms') || !Forms::def($type) || !$canSee($scope($req), $type)) admin_redirect('inbox', 'Choose a form to export.', 'bad');
            $from = AdminKit::date($req['query'], 'from');
            $to = AdminKit::date($req['query'], 'to');
            $where = ['form_type = ?', "status <> 'spam'"];
            $args = [$type];
            if ($from) { $where[] = 'created_at >= ?'; $args[] = Time::toUtc($from . ' 00:00:00'); }
            if ($to) { $where[] = 'created_at < ?'; $args[] = Time::toUtc(Time::addDays($to, 1) . ' 00:00:00'); }
            $fields = array_values(array_filter(Forms::fields($type), static fn($f) => !in_array($f['type'], ['fieldset', 'hidden_ctx'], true) || $f['type'] === 'hidden_ctx'));
            $labels = [];
            foreach ($fields as $f) {
                if ($f['type'] === 'fieldset') continue;
                $labels[] = trim((string)($f['display'] ?? $f['label'])) ?: ucfirst(str_replace('_', ' ', $f['key']));
            }
            $labels = array_values(array_unique($labels));
            $rows = [];
            foreach (Db::all('SELECT * FROM form_submissions WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at, id', $args) as $r) {
                $byLabel = [];
                foreach (Forms::display($fields, $payload($r)) as [$l, $v]) $byLabel[$l] = $v;
                $files = Db::column('SELECT original_name FROM form_files WHERE submission_id = ?', [(int)$r['id']]);
                $line = [(int)$r['id'], Time::toLocal((string)$r['created_at'])?->format('Y-m-d H:i') ?? '', (string)$r['status']];
                foreach ($labels as $l) $line[] = $byLabel[$l] ?? '';
                $line[] = implode(', ', $files);
                $rows[] = $line;
            }
            admin_audit('export', 'form_submissions', null, 'Exported ' . count($rows) . ' ' . $type . ' messages', ['from' => $from, 'to' => $to]);
            AdminKit::sendCsv($type . '-' . ($from ?: 'all') . '-' . ($to ?: Time::today()) . '.csv', array_merge(['Id', 'Received', 'Status'], $labels, ['Files']), $rows);
        }],
        'inbox/settings' => ['perm' => 'inbox.edit', 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($isBoss, $scope): never {
            if (!$isBoss($req) || $scope($req) !== null) admin_redirect('inbox', 'Only the owner and managers change notification settings.', 'bad');
            $type = (string)(($req['method'] === 'POST' ? $req['post']['type'] ?? '' : $req['query']['type'] ?? ''));
            if (!class_exists('Forms') || !in_array($type, Forms::TYPES, true)) admin_redirect('inbox', 'Choose a form.', 'bad');
            Forms::ensureType($type);
            $row = Db::one('SELECT * FROM form_types WHERE type_key = ?', [$type]);
            $errors = [];
            $values = $row;
            if ($req['method'] === 'POST') {
                $p = $req['post'];
                [$ok, $bad] = AdminKit::emails(AdminKit::str($p, 'recipients', 600, true));
                if ($bad) $errors[] = 'These are not email addresses: ' . implode(', ', $bad) . '.';
                $list = implode(', ', $ok);
                if (mb_strlen($list) > 500) $errors[] = 'Too many recipients (500 characters at most).';
                $label = AdminKit::str($p, 'label', 80) ?: (string)$row['label'];
                $subject = AdminKit::str($p, 'autoreply_subject', 200);
                $body = AdminKit::str($p, 'autoreply_body', 5000, true);
                $auto = AdminKit::flag($p, 'send_autoreply');
                if ($auto && ($subject === '' || $body === '')) $errors[] = 'An automatic reply needs a subject and a message.';
                $days = AdminKit::int($p, 'retention_days', 730) ?? 730;
                if ($days < 30 || $days > 3650) $errors[] = 'Keep messages between 30 and 3650 days.';
                $data = ['label' => $label, 'is_enabled' => AdminKit::flag($p, 'is_enabled'), 'recipients' => $list !== '' ? $list : null, 'send_autoreply' => $auto,
                    'autoreply_subject' => $subject !== '' ? $subject : null, 'autoreply_body' => $body !== '' ? $body : null, 'retention_days' => $days, 'updated_at' => Time::nowUtc()];
                if (!$errors) {
                    Db::update('form_types', $data, 'type_key = ?', [$type]);
                    admin_audit('update', 'form_types', $type, 'Form settings saved: ' . $label, AdminKit::diff($row, $data));
                    admin_redirect('inbox/settings?type=' . $type, 'Saved the settings for ' . $label . '.' . (!$data['is_enabled'] ? ' The form is switched off on the site.' : ''));
                }
                $values = array_merge($row, $data);
            }
            admin_render('inbox/settings', ['title' => 'Form settings', 'type' => $type, 'values' => $values, 'errors' => $errors]);
        }],
        'inbox/test-notify' => ['perm' => 'inbox.edit', 'methods' => ['POST'], 'handler' => static function (array $req) use ($isBoss): never {
            $type = (string)($req['post']['type'] ?? '');
            if (!$isBoss($req) || !class_exists('Forms') || !in_array($type, Forms::TYPES, true)) admin_redirect('inbox', 'Choose a form.', 'bad');
            $r = class_exists('Notify') ? Notify::test($type) : ['ok' => false, 'info' => 'the notifier is not installed'];
            admin_audit('send', 'form_types', $type, 'Test notification sent');
            admin_redirect('inbox/settings?type=' . $type, !empty($r['ok'])
                ? 'Test notification sent. Check the inboxes of the recipients.'
                : (!empty($r['deferred']) ? 'The daily email limit is reached; the test waits and goes out when there is room.' : 'The test could not be sent (' . ($r['info'] ?? 'unknown') . '). Check Settings > Email.'), !empty($r['ok']) || !empty($r['deferred']) ? 'ok' : 'bad');
        }],
    ],
];
