<?php
/**
 * Admin > Content > Menus (V2-PLAN 4 WP-B task 4; ADMIN-AUDIT 4.12, 4.6). Permission menus.edit (owner,
 * manager, editor). The tabs come from the menus table, each saying where its menu shows. An item "links to"
 * a page, a program, a location, a course, a web address, a heading, a special control or an automatic list,
 * and carries a description, a photo, an icon, show from and until dates and, for top items, a layout.
 *
 *   menus               GET   the tabs, the item tree, warnings, "Switch this list to automatic" and "Reset"
 *   menus/item          GET   edit one item (?id=) or add one (?menu=&parent=); POST saves it (no JavaScript needed)
 *   menus/delete        POST  delete one item and its sub-items
 *   menus/move          POST  one place up or down among its siblings
 *   menus/visible       POST  show or hide one item
 *   menus/settings      POST  the phone menu's "Same as the header menu" and photo; a footer column's heading
 *   menus/auto          GET   preview; POST switch the matching lists of a menu to automatic lists (P4)
 *   menus/reset         GET   confirm; POST put a menu back to its default items
 *   api/menus/item      POST  JSON: save one item
 *   api/menus/sort      POST  JSON {menu, parent_id, ids}: the order of one group (drag and drop in menus.js)
 *   api/menus/preview   POST  what a drafted item shows right now ("Right now this shows: Whitby, Oshawa")
 * Saves go item by item (never one whole-menu form: ADMIN R10). Every save calls SiteEvents::changed('menus')
 * (in MenusAdmin) and writes the audit log.
 */
defined('ICM') or exit;

$perm = 'menus.edit';

$menuKey = static function (mixed $v): string {
    $v = is_scalar($v) ? (string)$v : '';
    return isset(Menus::all()[$v]) ? $v : 'header';
};

$ready = static function (): void {
    if (!Menus::installed()) admin_redirect('', 'The menus need the database update first: open System and press "Apply database update".', 'bad');
};

/** The edit form's values from a saved row, then overlaid with what was posted. */
$formValues = static function (?array $row, ?array $post = null): array {
    $kind = (string)($row['kind'] ?? 'page');
    $ref = isset($row['ref_id']) && $row['ref_id'] !== null ? (int)$row['ref_id'] : null;
    $v = [
        'kind' => $kind, 'label' => (string)($row['label'] ?? ''),
        'page_id' => $kind === 'page' ? $ref : null, 'program_id' => $kind === 'program' ? $ref : null,
        'location_id' => $kind === 'location' ? $ref : null, 'course_id' => $kind === 'course' ? $ref : null,
        'path' => $kind === 'link' ? (string)($row['path'] ?? '') : '', 'special' => $kind === 'special' ? (string)$row['auto_source'] : '',
        'auto_source' => $kind === 'auto' ? (string)$row['auto_source'] : '', 'opts' => Menus::opts($row['auto_opts'] ?? null),
        'badge_location_id' => $kind !== 'location' && !empty($row['location_id']) ? (int)$row['location_id'] : null,
        'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        'description' => (string)($row['description'] ?? ''), 'icon' => (string)($row['icon'] ?? ''), 'layout' => (string)($row['layout'] ?? ''),
        'visible_from' => $row['visible_from'] ?? null, 'visible_until' => $row['visible_until'] ?? null,
        'opens_new_tab' => (int)($row['opens_new_tab'] ?? 0), 'is_visible' => (int)($row['is_visible'] ?? 1),
        'media_id' => !empty($row['media_id']) ? (int)$row['media_id'] : null, 'updated_at' => $row['updated_at'] ?? null,
    ];
    if ($post !== null) {
        foreach (['kind', 'label', 'path', 'special', 'auto_source', 'description', 'icon', 'layout', 'visible_from', 'visible_until', 'updated_at'] as $k) {
            if (isset($post[$k]) && is_scalar($post[$k])) $v[$k] = (string)$post[$k];
        }
        foreach (['page_id', 'program_id', 'location_id', 'course_id', 'badge_location_id', 'parent_id'] as $k) $v[$k] = AdminKit::int($post, $k);
        $v['opts'] = is_array($post['opts'] ?? null) ? $post['opts'] : [];
        $v['opens_new_tab'] = AdminKit::flag($post, 'opens_new_tab');
        $v['is_visible'] = AdminKit::flag($post, 'is_visible');
    }
    return $v;
};

/** What an item shows right now, as one sentence. */
$previewFor = static function (array $data, string $menu): string {
    $items = Menus::expandPreview($data, Menus::areaOf($menu));
    if (($data['kind'] ?? '') === 'auto') return MenusAdmin::previewText($items);
    if (!$items) return 'Right now this item is hidden.';
    $it = $items[0];
    return 'Right now this shows: ' . $it['label'] . ($it['path'] ? ', linking to ' . $it['path'] : '') . '.';
};

$itemView = static function (string $menu, ?array $row, array $values, array $errors, array $warnings, string $preview): never {
    admin_style('menus.css');
    admin_render('menus/item', [
        'title' => $row ? 'Edit a menu item' : 'Add a menu item', 'menu' => $menu, 'menuRow' => Menus::menu($menu), 'row' => $row,
        'v' => $values, 'errors' => $errors, 'warnings' => $warnings, 'preview' => $preview,
        'pages' => MenusAdmin::pageChoices(), 'programs' => MenusAdmin::programChoices(), 'locations' => MenusAdmin::locationChoices(),
        'locationSlugs' => MenusAdmin::locationChoices(true), 'courses' => MenusAdmin::courseChoices(),
        'parents' => MenusAdmin::parentChoices($menu, $row ? (int)$row['id'] : null),
    ]);
};

$shownLabel = static function (array $data, string $menu): string {
    if (trim((string)($data['label'] ?? '')) !== '') return (string)$data['label'];
    $items = Menus::expandPreview($data, Menus::areaOf($menu));
    return $items ? (string)$items[0]['label'] : 'the item';
};

return [
    'nav' => ['group' => 'Content', 'label' => 'Menus', 'path' => 'menus', 'perm' => $perm, 'sort' => 20],
    'routes' => [
        'menus' => ['perm' => $perm, 'methods' => ['GET'], 'handler' => static function (array $req) use ($menuKey, $ready): never {
            $ready();
            $menu = $menuKey($req['query']['menu'] ?? 'header');
            $tree = MenusAdmin::nested($menu);
            $flat = MenusAdmin::flat($tree);
            $programsReady = null;
            if ($menu === 'header') {
                foreach ($flat as $r) {
                    if ((int)$r['depth'] === 0 && (int)$r['is_visible'] === 0
                        && Db::value("SELECT 1 FROM menu_items WHERE parent_id = ? AND kind = 'auto' AND auto_source = 'programs'", [(int)$r['id']])) {
                        $programsReady = $r;
                        break;
                    }
                }
            }
            admin_style('menus.css');
            admin_render('menus/index', [
                'title' => 'Menus', 'menu' => $menu, 'menus' => Menus::all(), 'menuRow' => Menus::menu($menu), 'tree' => $tree,
                'count' => count($flat), 'problems' => count(array_filter($flat, static fn(array $r): bool => $r['info']['problem'] !== null)),
                'counts' => Db::pairs('SELECT menu, COUNT(*) FROM menu_items GROUP BY menu'), 'candidates' => MenusAdmin::candidates($menu),
                'programsReady' => $programsReady, 'hasDefault' => MenusAdmin::hasDefault($menu), 'thumb' => $menu === 'thumb_bar' ? Menus::thumb() : null,
            ]);
        }],

        'menus/item' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($menuKey, $ready, $formValues, $previewFor, $itemView, $shownLabel): never {
            $ready();
            $id = AdminKit::int($req['query'], 'id', 0) ?: 0;
            $row = $id ? Db::one('SELECT * FROM menu_items WHERE id = ?', [$id]) : null;
            if ($id && !$row) admin_redirect('menus', 'That menu item no longer exists.', 'bad');
            $menu = $row ? (string)$row['menu'] : $menuKey($req['post']['menu'] ?? ($req['query']['menu'] ?? 'header'));
            $errors = [];
            $warnings = [];
            $values = $formValues($row);
            if (!$row && ($pp = AdminKit::int($req['query'], 'parent'))) $values['parent_id'] = $pp;
            if ($req['method'] === 'POST') {
                $p = $req['post'];
                if ($row && ($stale = AdminKit::stale($row, $p))) $errors[] = $stale;
                $data = MenusAdmin::clean($p, $menu, $row, $errors, $warnings);
                $img = AdminKit::imageFromForm($req, 'image', $row && !empty($row['media_id']) ? (int)$row['media_id'] : null, 'menu-' . ($data['label'] !== '' ? $data['label'] : 'item'));
                if ($img['error']) $errors[] = $img['error'];
                $data['media_id'] = $img['id'];
                if (!$errors) {
                    $newId = MenusAdmin::save($menu, $row, $data, AdminKit::uid($req));
                    $label = $shownLabel($data, $menu);
                    admin_audit($row ? 'update' : 'create', 'menu_items', (string)$newId, ($row ? 'Menu item saved: ' : 'Menu item added: ') . $label . ' (' . $menu . ')', $row ? AdminKit::diff($row, $data) : []);
                    admin_redirect('menus?menu=' . $menu . '#item-' . $newId, 'Saved "' . $label . '".' . ($warnings ? ' ' . implode(' ', $warnings) : ''));
                }
                http_response_code(422);
                $values = $formValues(null, $p);
                $values['media_id'] = $img['id'];
                $values['updated_at'] = $row['updated_at'] ?? null;
                $preview = $previewFor($data, $menu);
            } else {
                $preview = $row ? $previewFor($row, $menu) : 'Choose what the item links to, and the preview shows what it will show.';
            }
            $itemView($menu, $row, $values, $errors, $warnings, $preview);
        }],

        'menus/delete' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $it = MenusAdmin::delete(AdminKit::int($req['post'], 'id', 0) ?: 0);
            if (!$it) admin_redirect('menus', 'That item was already deleted.', 'bad');
            $label = trim((string)$it['label']) !== '' ? (string)$it['label'] : 'the item';
            $more = (int)$it['_removed'] - 1;
            admin_audit('delete', 'menu_items', (string)$it['id'], 'Menu item deleted: ' . $label . ' (' . $it['menu'] . ')' . ($more ? ', with ' . $more . ' sub-items' : ''));
            admin_redirect('menus?menu=' . $it['menu'], 'Deleted "' . $label . '"' . ($more ? ' and its ' . $more . ' sub-items' : '') . '.');
        }],

        'menus/move' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $row = Db::one('SELECT id, menu FROM menu_items WHERE id = ?', [$id]);
            if (!$row) admin_redirect('menus', 'That item no longer exists.', 'bad');
            MenusAdmin::move($id, ($req['post']['dir'] ?? '') === 'up' ? -1 : 1);
            admin_audit('update', 'menu_items', (string)$id, 'Menu item moved ' . (($req['post']['dir'] ?? '') === 'up' ? 'up' : 'down') . ' (' . $row['menu'] . ')');
            admin_redirect('menus?menu=' . $row['menu'] . '#item-' . $id);
        }],

        'menus/visible' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req): never {
            $id = AdminKit::int($req['post'], 'id', 0) ?: 0;
            $on = AdminKit::flag($req['post'], 'on') === 1;
            $row = MenusAdmin::setVisible($id, $on, AdminKit::uid($req));
            if (!$row) admin_redirect('menus', 'That item no longer exists.', 'bad');
            $label = trim((string)$row['label']) !== '' ? (string)$row['label'] : 'The item';
            admin_audit('update', 'menu_items', (string)$id, 'Menu item ' . ($on ? 'shown' : 'hidden') . ': ' . $label . ' (' . $row['menu'] . ')');
            admin_redirect('menus?menu=' . $row['menu'] . '#item-' . $id, $on ? '"' . $label . '" now shows on the site.' : '"' . $label . '" is hidden.');
        }],

        'menus/settings' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($menuKey, $ready): never {
            $ready();
            $p = $req['post'];
            $menu = $menuKey($p['menu'] ?? '');
            $m = Menus::menu($menu);
            if (!$m) admin_redirect('menus', 'That menu no longer exists.', 'bad');
            $data = [];
            $note = '';
            if ($m['area'] === 'phone') {
                $same = AdminKit::flag($p, 'same_as') === 1;
                $data['same_as'] = $same ? 'header' : null;
                $img = AdminKit::imageFromForm($req, 'menu_image', !empty($m['media_id']) ? (int)$m['media_id'] : null, 'phone-menu');
                if ($img['error']) admin_redirect('menus?menu=' . $menu, $img['error'], 'bad');
                $data['media_id'] = $img['id'];
                if (!$same && trim((string)($m['same_as'] ?? '')) !== '') {
                    $n = MenusAdmin::copyHeaderToPhone(AdminKit::uid($req));
                    if ($n) $note = ' The header menu was copied here (' . $n . ' items), so you can shorten it for phones.';
                }
            } elseif (in_array($m['area'], ['footer'], true)) {
                $h = AdminKit::str($p, 'heading', 100);
                if (mb_strlen($h) > 80) admin_redirect('menus?menu=' . $menu, 'The heading is 80 characters at most.', 'bad');
                $data['heading'] = $h !== '' ? $h : null;
                $data['show_heading'] = AdminKit::flag($p, 'show_heading');
            }
            if (!$data) admin_redirect('menus?menu=' . $menu, 'This menu has no settings to save.');
            MenusAdmin::saveSettings($menu, $data, AdminKit::uid($req));
            admin_audit('update', 'menus', $menu, 'Menu settings saved: ' . $m['label'], AdminKit::diff($m, $data));
            admin_redirect('menus?menu=' . $menu, 'Saved the settings of the ' . mb_strtolower((string)$m['label']) . '.' . $note);
        }],

        'menus/auto' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($menuKey, $ready): never {
            $ready();
            $menu = $menuKey($req['post']['menu'] ?? ($req['query']['menu'] ?? 'header'));
            $cands = MenusAdmin::candidates($menu);
            if ($req['method'] === 'POST') {
                $keys = array_values(array_filter(array_map('strval', (array)($req['post']['keys'] ?? [])), 'strlen'));
                if (!$keys) admin_redirect('menus/auto?menu=' . $menu, 'Tick at least one list to switch.', 'bad');
                $n = MenusAdmin::switchToAuto($menu, $keys, AdminKit::uid($req));
                admin_audit('update', 'menu_items', $menu, 'Lists switched to automatic in ' . $menu . ': ' . $n, ['keys' => $keys]);
                admin_redirect('menus?menu=' . $menu, $n ? ($n === 1 ? 'One list now fills itself.' : $n . ' lists now fill themselves.') : 'Nothing was switched: the lists changed in the meantime.');
            }
            admin_style('menus.css');
            admin_render('menus/auto', ['title' => 'Switch lists to automatic', 'menu' => $menu, 'menuRow' => Menus::menu($menu), 'candidates' => $cands]);
        }],

        'menus/reset' => ['perm' => $perm, 'methods' => ['GET', 'POST'], 'handler' => static function (array $req) use ($menuKey, $ready): never {
            $ready();
            $menu = $menuKey($req['post']['menu'] ?? ($req['query']['menu'] ?? 'header'));
            if (!MenusAdmin::hasDefault($menu)) admin_redirect('menus?menu=' . $menu, 'This menu has no default to go back to.', 'bad');
            if ($req['method'] === 'POST') {
                if (AdminKit::flag($req['post'], 'confirm') !== 1) admin_redirect('menus/reset?menu=' . $menu, 'Tick the box to confirm, then press Reset.', 'bad');
                $before = (int)Db::value('SELECT COUNT(*) FROM menu_items WHERE menu = ?', [$menu], 0);
                $n = MenusAdmin::resetToDefault($menu);
                admin_audit('update', 'menu_items', $menu, 'Menu reset to the default: ' . $menu . ' (' . $before . ' items replaced by ' . $n . ')');
                admin_redirect('menus?menu=' . $menu, 'The menu is back to its default items.');
            }
            $defaults = [];
            $walk = static function (array $items, int $depth) use (&$walk, &$defaults): void {
                foreach ($items as $it) {
                    $defaults[] = [$depth, (string)$it['label'], isset($it['auto']) ? (Menus::AUTO_SOURCES[$it['auto'][0]][0] ?? '') : ''];
                    if (!empty($it['children'])) $walk($it['children'], $depth + 1);
                }
            };
            $walk(MenusAdmin::defaults()[$menu], 0);
            admin_style('menus.css');
            admin_render('menus/reset', ['title' => 'Reset a menu', 'menu' => $menu, 'menuRow' => Menus::menu($menu), 'defaults' => $defaults,
                'count' => (int)Db::value('SELECT COUNT(*) FROM menu_items WHERE menu = ?', [$menu], 0)]);
        }],

        'api/menus/item' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($menuKey, $shownLabel): never {
            if (!Menus::installed()) admin_json(['ok' => false, 'errors' => ['The menus need the database update first.']], 409);
            $in = is_array($req['json']) ? $req['json'] : $req['post'];
            $id = AdminKit::int($in, 'id', 0) ?: 0;
            $row = $id ? Db::one('SELECT * FROM menu_items WHERE id = ?', [$id]) : null;
            if ($id && !$row) admin_json(['ok' => false, 'errors' => ['That menu item no longer exists.']], 404);
            $menu = $row ? (string)$row['menu'] : $menuKey($in['menu'] ?? '');
            $errors = [];
            $warnings = [];
            if ($row && ($stale = AdminKit::stale($row, $in))) $errors[] = $stale;
            $data = MenusAdmin::clean($in, $menu, $row, $errors, $warnings);
            if (array_key_exists('media_id', $in)) {
                $mid = AdminKit::int($in, 'media_id');
                if ($mid && !Db::value("SELECT 1 FROM media WHERE id = ? AND kind = 'image' AND is_private = 0", [$mid])) $errors[] = 'There is no image with the number ' . $mid . ' in the media library.';
                $data['media_id'] = $mid ?: null;
            } else {
                $data['media_id'] = $row && !empty($row['media_id']) ? (int)$row['media_id'] : null;
            }
            if ($errors) admin_json(['ok' => false, 'errors' => array_values(array_unique($errors))], 422);
            $newId = MenusAdmin::save($menu, $row, $data, AdminKit::uid($req));
            $label = $shownLabel($data, $menu);
            admin_audit($row ? 'update' : 'create', 'menu_items', (string)$newId, ($row ? 'Menu item saved: ' : 'Menu item added: ') . $label . ' (' . $menu . ')', $row ? AdminKit::diff($row, $data) : []);
            admin_json(['ok' => true, 'id' => $newId, 'label' => $label, 'warnings' => $warnings,
                'updated_at' => (string)Db::value('SELECT updated_at FROM menu_items WHERE id = ?', [$newId])]);
        }],

        'api/menus/sort' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($menuKey): never {
            $in = is_array($req['json']) ? $req['json'] : $req['post'];
            $menu = $menuKey($in['menu'] ?? '');
            $parent = AdminKit::int($in, 'parent_id') ?: null;
            try {
                $n = MenusAdmin::sort($menu, $parent, (array)($in['ids'] ?? []));
            } catch (InvalidArgumentException $e) {
                admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            admin_audit('update', 'menu_items', $menu, 'Menu order changed (' . $menu . ')', ['parent' => $parent, 'order' => AdminKit::ids($in['ids'] ?? [])]);
            admin_json(['ok' => true, 'moved' => $n]);
        }],

        'api/menus/preview' => ['perm' => $perm, 'methods' => ['POST'], 'handler' => static function (array $req) use ($menuKey, $previewFor): never {
            $in = is_array($req['json']) ? $req['json'] : $req['post'];
            $id = AdminKit::int($in, 'id', 0) ?: 0;
            $row = $id ? Db::one('SELECT * FROM menu_items WHERE id = ?', [$id]) : null;
            $menu = $row ? (string)$row['menu'] : $menuKey($in['menu'] ?? '');
            $errors = [];
            $warnings = [];
            $data = MenusAdmin::clean($in, $menu, $row, $errors, $warnings);
            $items = Menus::expandPreview($data, Menus::areaOf($menu));
            admin_json(['ok' => true, 'text' => $previewFor($data, $menu), 'problems' => $errors,
                'items' => array_map(static fn(array $i): array => ['label' => $i['label'], 'path' => $i['path'], 'status' => $i['status'],
                    'image' => is_array($i['image']) ? mediaUrl($i['image'], 'thumb') : null], $items)]);
        }],
    ],
];
