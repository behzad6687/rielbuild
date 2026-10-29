<?php
/**
 * Generic list and edit screens for the simple content lists (spec 4.2.13):
 * FAQs, Jobs, Staff, Testimonials and Pathway levels. A module passes a config
 * and gets its routes:
 *   {route}            list (search, filters, sort order with drag or up and down)
 *   {route}/edit       add or edit (?id=), optimistic lock, audit, copy linter
 *   {route}/delete     POST
 *   {route}/move       POST (up and down on touch)
 *   api/{route}/sort   POST JSON {ids: [...]}
 *
 * Config keys: table, route, perm, label, plural, title_col, list (col => heading),
 * fields (see collect()), order, search (cols), filters (col => [value => label]),
 * banner, lede, defaults, validate(fn(array $data, ?array $old): array), after_save(fn(int $id)),
 * after_delete(fn(int $id)), can_delete(fn(array $row): ?string), group (sort group col).
 */
defined('ICM') or exit;

final class SimpleCrud
{
    public static function routes(array $cfg): array
    {
        $r = $cfg['route'];
        $p = $cfg['perm'];
        return [
            $r               => ['perm' => $p, 'methods' => ['GET'], 'handler' => fn(array $req) => self::index($cfg, $req)],
            $r . '/edit'     => ['perm' => $p, 'methods' => ['GET', 'POST'], 'handler' => fn(array $req) => self::edit($cfg, $req)],
            $r . '/delete'   => ['perm' => $p, 'methods' => ['POST'], 'handler' => fn(array $req) => self::delete($cfg, $req)],
            $r . '/move'     => ['perm' => $p, 'methods' => ['POST'], 'handler' => fn(array $req) => self::move($cfg, $req)],
            'api/' . $r . '/sort' => ['perm' => $p, 'methods' => ['POST'], 'handler' => fn(array $req) => self::sort($cfg, $req)],
        ];
    }

    public static function index(array $cfg, array $req): never
    {
        $t = self::table($cfg);
        $q = Sanitizer::text($req['query']['q'] ?? '', false, 100);
        $where = [];
        $args = [];
        if ($q !== '' && !empty($cfg['search'])) {
            $or = [];
            foreach ($cfg['search'] as $col) {
                self::ident($col);
                $or[] = $col . ' LIKE ?' . Db::LIKE_ESCAPE;
                $args[] = '%' . Db::like($q) . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $active = [];
        foreach ($cfg['filters'] ?? [] as $col => $opts) {
            self::ident($col);
            $v = $req['query'][$col] ?? '';
            if (is_scalar($v) && (string)$v !== '' && isset($opts[(string)$v])) {
                $where[] = $col . ' = ?';
                $args[] = (string)$v;
                $active[$col] = (string)$v;
            }
        }
        $rows = Db::all('SELECT * FROM ' . $t . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . self::order($cfg) . ' LIMIT 1000', $args);
        admin_render('lists/list', ['cfg' => $cfg, 'rows' => $rows, 'q' => $q, 'active' => $active]);
    }

    public static function edit(array $cfg, array $req): never
    {
        $t = self::table($cfg);
        $id = AdminKit::int($req['query'], 'id', 0) ?: 0;
        $row = $id ? Db::one('SELECT * FROM ' . $t . ' WHERE id = ?', [$id]) : null;
        if ($id && !$row) admin_redirect($cfg['route'], 'That ' . strtolower($cfg['label']) . ' no longer exists.', 'bad');
        $values = $row ?? ($cfg['defaults'] ?? []);
        $errors = [];
        $warnings = [];

        if ($req['method'] === 'POST') {
            if ($stale = AdminKit::stale($row, $req['post'])) {
                $errors[] = $stale;
            } else {
                [$data, $errors, $warnings] = self::collect($cfg, $req, $row);
                if (isset($cfg['validate'])) $errors = array_merge($errors, (array)($cfg['validate'])($data, $row));
                if (!$errors) {
                    $now = Time::nowUtc();
                    if (Db::hasColumn($t, 'updated_at')) $data['updated_at'] = $now;
                    $saved = Db::tx(function () use ($t, $data, $row, $id, $now, $cfg, $req) {
                        if ($row) {
                            Db::update($t, $data, 'id = ?', [$id]);
                            $newId = $id;
                        } else {
                            if (Db::hasColumn($t, 'created_at') && !isset($data['created_at'])) $data['created_at'] = $now;
                            if (Db::hasColumn($t, 'sort') && !isset($data['sort'])) $data['sort'] = (int)Db::value('SELECT MAX(sort) FROM ' . $t, [], 0) + 10;
                            $newId = Db::insert($t, $data);
                        }
                        if (isset($cfg['after_save'])) ($cfg['after_save'])($newId, $req);
                        return $newId;
                    });
                    $title = (string)($data[$cfg['title_col']] ?? ($row[$cfg['title_col']] ?? ''));
                    admin_audit($row ? 'update' : 'create', $t, (string)$saved, $cfg['label'] . ' saved: ' . mb_substr($title, 0, 150), $row ? AdminKit::diff($row, $data) : []);
                    $msg = 'Saved.' . ($warnings ? ' Please look at the house style warnings.' : '');
                    admin_redirect($cfg['route'] . '/edit?id=' . $saved, $msg, 'ok');
                }
                $values = array_merge($values, $data);
            }
        }
        if ($row) $warnings = array_values(array_unique(array_merge($warnings, self::lintRow($cfg, $values))));
        admin_render('lists/edit', ['cfg' => $cfg, 'row' => $row, 'values' => $values, 'errors' => $errors, 'warnings' => $warnings]);
    }

    public static function delete(array $cfg, array $req): never
    {
        $t = self::table($cfg);
        $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
        $row = Db::one('SELECT * FROM ' . $t . ' WHERE id = ?', [$id]);
        if (!$row) admin_redirect($cfg['route'], 'That ' . strtolower($cfg['label']) . ' was already deleted.', 'bad');
        if (isset($cfg['can_delete']) && ($why = ($cfg['can_delete'])($row))) admin_redirect($cfg['route'] . '/edit?id=' . $id, $why, 'bad');
        Db::delete($t, 'id = ?', [$id]);
        if (isset($cfg['after_delete'])) ($cfg['after_delete'])($id);
        admin_audit('delete', $t, (string)$id, $cfg['label'] . ' deleted: ' . mb_substr((string)$row[$cfg['title_col']], 0, 150));
        admin_redirect($cfg['route'], 'Deleted "' . mb_substr((string)$row[$cfg['title_col']], 0, 80) . '".');
    }

    public static function move(array $cfg, array $req): never
    {
        $t = self::table($cfg);
        $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
        $dir = ($req['post']['dir'] ?? '') === 'up' ? -1 : 1;
        AdminKit::move($t, $id, $dir, $cfg['group'] ?? null);
        if (isset($cfg['after_save'])) ($cfg['after_save'])($id, $req);
        admin_redirect($cfg['route'] . '#row-' . $id);
    }

    public static function sort(array $cfg, array $req): never
    {
        $t = self::table($cfg);
        $ids = AdminKit::ids($req['json']['ids'] ?? ($req['post']['ids'] ?? []));
        if (!$ids) admin_json(['ok' => false, 'error' => 'No rows to sort.'], 422);
        AdminKit::reorder($t, $ids);
        if (isset($cfg['after_save'])) ($cfg['after_save'])($ids[0], $req);
        admin_audit('update', $t, null, $cfg['plural'] . ' reordered', ['order' => $ids]);
        admin_json(['ok' => true]);
    }

    /**
     * Read every configured field from the POST. Field keys: key, label, type
     * (text, email, url, textarea, rich, select, check, date, number, slug, image, json, pairs),
     * max, required, options, from (slug source), min, maxv, sub (pairs: [subkey => label]).
     * Returns [data, errors, warnings].
     */
    public static function collect(array $cfg, array $req, ?array $row): array
    {
        $p = $req['post'];
        $data = [];
        $errors = [];
        $warnings = [];
        $fix = !empty($p['fix_dashes']);
        foreach ($cfg['fields'] as $f) {
            $k = $f['key'];
            $label = $f['label'];
            $max = (int)($f['max'] ?? 0);
            switch ($f['type']) {
                case 'text':
                case 'email':
                case 'url':
                    $v = AdminKit::str($p, $k, 0);
                    if ($fix) $v = AdminKit::fixDashes($v);
                    if ($max && mb_strlen($v) > $max) $errors[] = $label . ' is ' . $max . ' characters at most.';
                    if ($v !== '' && $f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[] = $label . ' is not a valid email address.';
                    if ($v !== '' && $f['type'] === 'url' && !preg_match('#^https?://#i', $v)) $errors[] = $label . ' must start with https://.';
                    $data[$k] = $v !== '' ? $v : (!empty($f['not_null']) ? '' : null);
                    break;
                case 'textarea':
                    $v = AdminKit::str($p, $k, 0, true);
                    if ($fix) $v = AdminKit::fixDashes($v);
                    if ($max && mb_strlen($v) > $max) $errors[] = $label . ' is ' . $max . ' characters at most.';
                    $data[$k] = $v !== '' ? $v : (!empty($f['not_null']) ? '' : null);
                    break;
                case 'rich':
                    $v = AdminKit::rich($p, $k, $fix);
                    $data[$k] = $v !== '' ? $v : (!empty($f['not_null']) ? '' : null);
                    break;
                case 'select':
                    $v = is_scalar($p[$k] ?? null) ? (string)$p[$k] : '';
                    $data[$k] = isset($f['options'][$v]) ? ($v === '' ? null : $v) : ($f['default'] ?? null);
                    break;
                case 'check':
                    $data[$k] = AdminKit::flag($p, $k);
                    break;
                case 'date':
                    $raw = AdminKit::str($p, $k, 20);
                    $v = AdminKit::validDate($raw);
                    if ($raw !== '' && !$v) $errors[] = $label . ' is not a valid date.';
                    $data[$k] = $v;
                    break;
                case 'number':
                    $raw = AdminKit::str($p, $k, 12);
                    $v = AdminKit::int($p, $k);
                    if ($raw !== '' && $v === null) $errors[] = $label . ' must be a whole number.';
                    if ($v !== null && isset($f['min']) && $v < $f['min']) $errors[] = $label . ' must be at least ' . $f['min'] . '.';
                    if ($v !== null && isset($f['maxv']) && $v > $f['maxv']) $errors[] = $label . ' must be at most ' . $f['maxv'] . '.';
                    $data[$k] = $v ?? ($f['default'] ?? null);
                    break;
                case 'slug':
                    $v = slugify(AdminKit::str($p, $k, 200));
                    if ($v === '' && !empty($f['from'])) $v = slugify(AdminKit::str($p, $f['from'], 300));
                    $data[$k] = AdminKit::uniqueSlug(self::table($cfg), $v !== '' ? $v : 'item', $row ? (int)$row['id'] : null, $k, (int)($f['max'] ?? 80));
                    break;
                case 'image':
                    $r = AdminKit::imageFromForm($req, $k, $row ? ((int)($row[$k] ?? 0) ?: null) : null, AdminKit::str($p, $cfg['title_col'], 80) ?: $k);
                    if ($r['error']) $errors[] = $label . ': ' . $r['error'];
                    $data[$k] = $r['id'];
                    break;
                case 'json':
                    $raw = trim(AdminKit::str($p, $k, 0, true));
                    if ($raw === '') {
                        $data[$k] = null;
                    } elseif (($raw[0] === '[' || $raw[0] === '{') && is_array($j = json_decode($raw, true))) {
                        $data[$k] = json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn($x) => $x !== ''));
                        $data[$k] = json_encode($lines, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    break;
                case 'pairs':
                    $list = [];
                    foreach ((array)($p[$k] ?? []) as $item) {
                        if (!is_array($item)) continue;
                        $one = [];
                        foreach ($f['sub'] as $sk => $sl) {
                            $sv = Sanitizer::text($item[$sk] ?? '', false, 200);
                            if ($sv !== '') $one[$sk] = $sv;
                        }
                        if ($one) $list[] = $one;
                    }
                    $data[$k] = $list ? json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                    break;
            }
            $empty = ($data[$k] ?? null) === null || (is_string($data[$k]) && trim(strip_tags($data[$k])) === '');
            if (!empty($f['required']) && $empty) $errors[] = $label . ' is required.';
        }
        $warnings = self::lintRow($cfg, $data);
        return [$data, array_values(array_unique($errors)), $warnings];
    }

    private static function lintRow(array $cfg, array $values): array
    {
        $texts = [];
        foreach ($cfg['fields'] as $f) {
            if (in_array($f['type'], ['text', 'textarea', 'rich'], true) && empty($f['no_lint'])) $texts[] = (string)($values[$f['key']] ?? '');
        }
        return AdminKit::lint(...$texts);
    }

    private static function order(array $cfg): string
    {
        $o = (string)($cfg['order'] ?? 'sort, id');
        if (!preg_match('/^[a-z_]+( (ASC|DESC))?(, [a-z_]+( (ASC|DESC))?)*$/i', $o)) throw new InvalidArgumentException('Bad order');
        return $o;
    }

    private static function table(array $cfg): string
    {
        self::ident((string)$cfg['table']);
        return (string)$cfg['table'];
    }

    private static function ident(string $s): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $s)) throw new InvalidArgumentException('Bad identifier');
    }
}
