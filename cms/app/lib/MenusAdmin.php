<?php
/**
 * The Menus screen's rules and writes (V2-PLAN 4, WP-B tasks 4 and 6; ADMIN-AUDIT 4.12, 4.15). Owner: WP-B.
 * Menus reads; this class validates and writes, one item at a time (no whole-menu form, ADMIN R10), and holds
 * the defaults: app/seed/menus.php is the fresh-install menu, and each automatic group in it names the round 1
 * rows it replaces, so "Switch this list to automatic" on an upgraded site gives exactly the fresh install's
 * list (P4: nothing on the live site changes by itself; the screen previews first).
 *
 *   MenusAdmin::nested($menu)                 the admin tree: rows with depth, target summary, problem, preview
 *   MenusAdmin::clean($in, $menu, $row, ...)  a posted item validated into menu_items columns
 *   MenusAdmin::save(), delete(), move(), sort(), setVisible()      each calls SiteEvents::changed('menus')
 *   MenusAdmin::candidates($menu), switchToAuto($menu, $keys)      the automatic lists a menu can switch to
 *   MenusAdmin::resetToDefault($menu), applyDefaults(...), ensureMenus(), seedImages($mode)
 *   MenusAdmin::copyHeaderToPhone(), saveSettings()
 * Every write also calls Menus::flush(), so the same request reads the new menu.
 */
defined('ICM') or exit;

final class MenusAdmin
{
    /** Above this many items the screen warns (long menus, ADMIN R10). */
    public const WARN_ITEMS = 80;
    /** The admin allows four levels: a top item, a column, a group, a link. */
    public const MAX_LEVEL = 3;

    /** menu_items columns an item's kind sets (written by the defaults and the reset). */
    private const KIND_COLS = ['path', 'location_id', 'kind', 'ref_id', 'auto_source', 'auto_opts', 'description', 'media_id', 'icon', 'layout'];

    /** Program kinds (seed/programs.php), for the automatic list's kind filter. */
    public const PROGRAM_KINDS = [
        'lessons' => 'Lessons', 'hp' => 'High Performance', 'team' => 'Team', 'cup' => 'Cup', 'aerobic' => 'Aerobic tennis',
        'pickleball' => 'Pickleball', 'camp' => 'Camp', 'ranking' => 'Ranking matches', 'private' => 'Private lessons',
        'vip' => 'VIP home program', 'pathway' => 'Coach pathway', 'intake' => 'Academy intake',
    ];

    private static ?array $defaults = null;
    private static ?array $seedMedia = null;

    // =====================================================================
    // Reading for the screen
    // =====================================================================

    /** Every row of a menu, nested: each row gains 'depth', 'children', 'info'. @return list<array> */
    public static function nested(string $menu): array
    {
        $rows = Db::all('SELECT * FROM menu_items WHERE menu = ? ORDER BY sort, id', [$menu]);
        $ids = [];
        foreach ($rows as $r) $ids[(int)$r['id']] = true;
        $by = [];
        foreach ($rows as $r) {
            $pid = (int)($r['parent_id'] ?? 0);
            $by[isset($ids[$pid]) ? $pid : 0][] = $r;
        }
        $area = Menus::areaOf($menu);
        $walk = static function (int $pid, int $depth, array $seen) use (&$walk, &$by, $area): array {
            $out = [];
            foreach ($by[$pid] ?? [] as $r) {
                $id = (int)$r['id'];
                if (isset($seen[$id])) continue;
                $r['depth'] = $depth;
                $r['info'] = self::info($r, $area);
                $r['children'] = $depth < 8 ? $walk($id, $depth + 1, $seen + [$id => true]) : [];
                $out[] = $r;
            }
            return $out;
        };
        return $walk(0, 0, []);
    }

    /** Flatten a nested list (depth order). */
    public static function flat(array $nested): array
    {
        $out = [];
        foreach ($nested as $r) {
            $kids = $r['children'];
            unset($r['children']);
            $out[] = $r;
            foreach (self::flat($kids) as $k) $out[] = $k;
        }
        return $out;
    }

    /**
     * What the screen says about one row: 'target' (where it links), 'problem' (a dangling reference, null when
     * fine), 'preview' (what it shows right now), 'thumb' (a small image URL) and 'label' (as shown).
     */
    public static function info(array $r, string $area): array
    {
        $kind = (string)$r['kind'];
        $ref = $r['ref_id'] !== null ? (int)$r['ref_id'] : null;
        $problem = null;
        $target = '';
        switch ($kind) {
            case 'page':
                $p = $ref ? Db::one('SELECT path, title, status FROM pages WHERE id = ?', [$ref]) : null;
                if (!$p) $problem = 'The page this item linked to is gone.';
                else {
                    $target = (string)$p['path'];
                    if ($p['status'] !== 'published') $problem = 'The page is a draft, so this item stays hidden until it is published.';
                }
                break;
            case 'program':
                $p = $ref ? Db::one('SELECT * FROM programs WHERE id = ?', [$ref]) : null;
                if (!$p) $problem = 'The program this item linked to is gone.';
                else {
                    $target = (string)$p['name'];
                    if ((int)$p['is_active'] !== 1) $problem = 'The program is hidden, so this item is hidden too.';
                }
                break;
            case 'location':
                $l = $ref ? Db::one('SELECT * FROM locations WHERE id = ?', [$ref]) : null;
                if (!$l) $problem = 'The location this item linked to is gone.';
                else {
                    $target = (string)$l['name'] . ((string)($l['hub_page_path'] ?? '') !== '' ? ', ' . $l['hub_page_path'] : '');
                    if ($l['status'] === 'hidden') $problem = 'The location is hidden, so this item is hidden too.';
                }
                break;
            case 'course':
                $c = $ref ? Db::one('SELECT code, title FROM courses WHERE id = ?', [$ref]) : null;
                if (!$c) $problem = 'The course this item linked to is gone.';
                else $target = (string)$c['title'] . ' (' . $c['code'] . ')';
                break;
            case 'link':
                $target = (string)($r['path'] ?? '');
                if ($target === '') $problem = 'This web address item has no address, so it is hidden.';
                break;
            case 'special':
                $sp = Menus::SPECIALS[(string)$r['auto_source']] ?? null;
                if (!$sp) $problem = 'This special item is not one the site knows.';
                else $target = $sp[2];
                break;
            case 'auto':
                $src = Menus::AUTO_SOURCES[(string)$r['auto_source']] ?? null;
                if (!$src) $problem = 'This automatic list is not one the site knows.';
                else $target = $src[0];
                break;
        }
        $shown = Menus::expandPreview($r, $area);
        $thumb = '';
        $mid = (int)($r['media_id'] ?? 0);
        if ($mid > 0) $thumb = AdminKit::thumb($mid);
        elseif (count($shown) === 1 && is_array($shown[0]['image'] ?? null)) $thumb = mediaUrl($shown[0]['image'], 'thumb');
        $label = trim((string)$r['label']);
        if ($label === '' && count($shown) === 1) $label = (string)$shown[0]['label'];
        return [
            'label'   => $label,
            'target'  => $target,
            'problem' => $problem,
            'preview' => $kind === 'auto' ? self::previewText($shown) : null,
            'thumb'   => $thumb,
        ];
    }

    /** "Right now this shows: Whitby, Oshawa." (at most twelve names, then "and N more"). */
    public static function previewText(array $items): string
    {
        $labels = array_values(array_filter(array_map(static fn(array $i): string => (string)$i['label'], $items), 'strlen'));
        if (!$labels) return 'Right now this shows nothing.';
        $more = count($labels) - 12;
        $shown = implode(', ', array_slice($labels, 0, 12));
        return 'Right now this shows: ' . $shown . ($more > 0 ? ' and ' . $more . ' more.' : '.');
    }

    /** Where a menu shows, for its tab. */
    public static function whereText(array $m): string
    {
        if ((int)$m['is_visible'] !== 1) return 'This menu is not shown on the site.';
        $area = (string)$m['area'];
        if ($area === 'phone') {
            return trim((string)($m['same_as'] ?? '')) !== ''
                ? 'Shows in the phone menu. It repeats the header menu while "Same as the header menu" is ticked.'
                : 'Shows in the phone menu, with its own items.';
        }
        if ($area === 'footer') {
            $h = trim((string)($m['heading'] ?? ''));
            return 'Shows in footer column ' . (int)$m['col'] . ($h !== '' && (int)$m['show_heading'] === 1 ? ', under the heading "' . $h . '"' : '') . '.';
        }
        if ($area === 'thumb') return 'Shows in the bar at the bottom of phone screens: ' . Menus::THUMB_MIN . ' to ' . Menus::THUMB_MAX . ' items.';
        return 'Shows in ' . (Menus::AREAS[$area] ?? 'the site') . '.';
    }

    // ---------------------------------------------------------------------
    // Choices for the pickers
    // ---------------------------------------------------------------------

    /** Pages: id => "Title, /path/" (drafts marked). */
    public static function pageChoices(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, path, title, status FROM pages ORDER BY path') as $p) {
            $out[(int)$p['id']] = (string)$p['title'] . ', ' . $p['path'] . ($p['status'] !== 'published' ? ' (draft)' : '');
        }
        return $out;
    }

    public static function programChoices(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, name, is_active FROM programs ORDER BY sort, name') as $p) {
            $out[(int)$p['id']] = (string)$p['name'] . ((int)$p['is_active'] !== 1 ? ' (hidden)' : '');
        }
        return $out;
    }

    public static function locationChoices(bool $bySlug = false): array
    {
        $out = [];
        foreach (Db::all('SELECT id, slug, name, status FROM locations ORDER BY sort, name') as $l) {
            $out[$bySlug ? (string)$l['slug'] : (int)$l['id']] = (string)$l['name'] . ($l['status'] === 'hidden' ? ' (hidden)' : '');
        }
        return $out;
    }

    public static function courseChoices(): array
    {
        $out = [];
        foreach (Db::all('SELECT c.id, c.code, c.title, l.name AS loc FROM courses c LEFT JOIN locations l ON l.id = c.location_id ORDER BY c.sort, c.id') as $c) {
            $out[(int)$c['id']] = (string)$c['title'] . ($c['loc'] ? ', ' . $c['loc'] : '') . ' (' . $c['code'] . ')';
        }
        return $out;
    }

    /** Rows an item may sit under (not itself or its own sub-items, not too deep). */
    public static function parentChoices(string $menu, ?int $exceptId): array
    {
        $out = [];
        foreach (self::flat(self::nested($menu)) as $r) {
            $id = (int)$r['id'];
            if ($r['depth'] >= self::MAX_LEVEL || $r['kind'] === 'auto') continue;
            if ($exceptId && ($id === $exceptId || self::cycle($exceptId, $id))) continue;
            $out[$id] = str_repeat('· ', (int)$r['depth']) . ($r['info']['label'] !== '' ? $r['info']['label'] : Menus::KINDS[$r['kind']] ?? 'Item');
        }
        return $out;
    }

    // =====================================================================
    // Validation
    // =====================================================================

    /**
     * A posted item (form or JSON: label, kind, page_id, program_id, location_id, course_id, path, special,
     * auto_source, opts[...], badge_location_id, parent_id, description, icon, layout, visible_from,
     * visible_until, opens_new_tab, is_visible) as menu_items columns. media_id is set by the caller.
     * $errors stop the save; $warnings are notes (the house style linter, a draft page).
     */
    public static function clean(array $in, string $menu, ?array $row, array &$errors, array &$warnings): array
    {
        $kind = AdminKit::pick($in['kind'] ?? ($row['kind'] ?? 'link'), array_keys(Menus::KINDS), 'link');
        $label = Sanitizer::text($in['label'] ?? '', false, 200);
        if (mb_strlen($label) > 120) $errors[] = 'Labels are 120 characters at most.';
        $id = $row ? (int)$row['id'] : null;
        $d = ['kind' => $kind, 'label' => mb_substr($label, 0, 120), 'path' => null, 'ref_id' => null, 'auto_source' => null, 'auto_opts' => null, 'location_id' => null];

        switch ($kind) {
            case 'page':
                $pid = AdminKit::int($in, 'page_id');
                $p = $pid ? Db::one('SELECT id, path, status FROM pages WHERE id = ?', [$pid]) : null;
                if (!$p) $errors[] = 'Choose the page this item links to.';
                else {
                    $d['ref_id'] = (int)$p['id'];
                    $d['path'] = (string)$p['path'];   // kept as a copy: the page's own path wins when it moves
                    if ($p['status'] !== 'published') $warnings[] = 'That page is a draft, so the item stays hidden until the page is published.';
                }
                break;
            case 'program':
                $pid = AdminKit::int($in, 'program_id');
                if (!$pid || !Db::value('SELECT 1 FROM programs WHERE id = ?', [$pid])) $errors[] = 'Choose the program this item links to.';
                else $d['ref_id'] = $pid;
                break;
            case 'location':
                $lid = AdminKit::int($in, 'location_id');
                $l = $lid ? Db::one('SELECT id, hub_page_path FROM locations WHERE id = ?', [$lid]) : null;
                if (!$l) $errors[] = 'Choose the location this item links to.';
                else {
                    $d['ref_id'] = (int)$l['id'];
                    $d['location_id'] = (int)$l['id'];
                    $d['path'] = ($l['hub_page_path'] ?? '') !== '' ? (string)$l['hub_page_path'] : null;
                }
                break;
            case 'course':
                $cid = AdminKit::int($in, 'course_id');
                if (!$cid || !Db::value('SELECT 1 FROM courses WHERE id = ?', [$cid])) $errors[] = 'Choose the course this item links to.';
                else $d['ref_id'] = $cid;
                break;
            case 'link':
                $path = AdminKit::target((string)(is_scalar($in['path'] ?? null) ? $in['path'] : ''));
                if ($path === null) $errors[] = 'The web address must start with / or be a full https:// address.';
                else $d['path'] = $path;
                if ($label === '') $errors[] = 'Give this web address a label.';
                break;
            case 'heading':
                if ($label === '') $errors[] = 'Give the heading a label.';
                break;
            case 'special':
                $sp = AdminKit::pick($in['special'] ?? '', array_keys(Menus::SPECIALS), '');
                if ($sp === '') $errors[] = 'Choose what the special item does.';
                else $d['auto_source'] = $sp;
                break;
            case 'auto':
                $src = AdminKit::pick($in['auto_source'] ?? '', array_keys(Menus::AUTO_SOURCES), '');
                if ($src === '') $errors[] = 'Choose what the automatic list shows.';
                else {
                    $d['auto_source'] = $src;
                    $d['auto_opts'] = Menus::encodeOpts(self::cleanOpts($src, is_array($in['opts'] ?? null) ? $in['opts'] : [], $errors));
                }
                if ($label === '') $errors[] = 'Give the automatic list a name (it shows only on this screen, unless it is kept as a heading).';
                break;
        }
        if ($d['label'] === '' && $kind !== 'auto' && !in_array($kind, ['page', 'program', 'location', 'course', 'special'], true)) {
            $errors[] = 'Every item needs a label (120 characters at most).';
        }

        // A status pill from a location (headings, pages and web addresses; a location item is bound already).
        if (in_array($kind, ['heading', 'page', 'link'], true)) {
            $bl = AdminKit::int($in, 'badge_location_id');
            if ($bl && Db::value('SELECT 1 FROM locations WHERE id = ?', [$bl])) $d['location_id'] = $bl;
        }

        // Where it sits.
        $parent = AdminKit::int($in, 'parent_id');
        if ($parent) {
            $pr = Db::one('SELECT id, menu, kind FROM menu_items WHERE id = ?', [$parent]);
            if (!$pr || $pr['menu'] !== $menu) {
                $errors[] = 'The item it sits under is not in this menu.';
                $parent = null;
            } elseif ($pr['kind'] === 'auto') {
                $errors[] = 'Nothing can sit under an automatic list: it fills itself.';
                $parent = null;
            } elseif ($id !== null && ($parent === $id || self::cycle($id, $parent))) {
                $errors[] = 'An item cannot sit under itself or one of its own sub-items.';
                $parent = null;
            } elseif (self::depthOf($parent) >= self::MAX_LEVEL) {
                $errors[] = 'Menus go four levels deep at most. Choose a higher item to sit under.';
                $parent = null;
            }
        }
        $d['parent_id'] = $parent ?: null;

        // The thumb bar (ADMIN 4.6): 3 to 5 top items of the allowed kinds, no sub-items.
        if (Menus::areaOf($menu) === 'thumb' && $menu === 'thumb_bar') {
            if ($d['parent_id'] !== null) $errors[] = 'The thumb bar has no sub-items.';
            if (!in_array($kind, Menus::THUMB_KINDS, true)) $errors[] = 'The thumb bar holds a special button, a page, a web address or a program.';
            if ($kind === 'special' && $d['auto_source'] !== null && !in_array($d['auto_source'], Menus::THUMB_SPECIALS, true)) {
                $errors[] = 'In the thumb bar a special button can open the level finder, the call sheet, the menu or the search.';
            }
            $others = (int)Db::value('SELECT COUNT(*) FROM menu_items WHERE menu = ? AND parent_id IS NULL' . ($id ? ' AND id <> ?' : ''), $id ? [$menu, $id] : [$menu], 0);
            if ($others >= Menus::THUMB_MAX) $errors[] = 'The thumb bar holds ' . Menus::THUMB_MAX . ' items at most. Remove one before adding another.';
        }

        $desc = Sanitizer::text($in['description'] ?? '', false, 300);
        if (mb_strlen($desc) > 200) $errors[] = 'The description is 200 characters at most.';
        $d['description'] = $desc !== '' ? mb_substr($desc, 0, 200) : null;
        $icon = (string)(is_scalar($in['icon'] ?? null) ? $in['icon'] : '');
        $d['icon'] = isset(Menus::ICONS[$icon]) ? $icon : null;
        $layout = (string)(is_scalar($in['layout'] ?? null) ? $in['layout'] : '');
        $d['layout'] = $d['parent_id'] === null && isset(Menus::LAYOUTS[$layout]) ? $layout : null;
        $d['visible_from'] = AdminKit::date($in, 'visible_from');
        $d['visible_until'] = AdminKit::date($in, 'visible_until');
        if ($d['visible_from'] && $d['visible_until'] && $d['visible_from'] > $d['visible_until']) $errors[] = '"Show until" must be on or after "Show from".';
        $d['opens_new_tab'] = AdminKit::flag($in, 'opens_new_tab');
        $d['is_visible'] = array_key_exists('is_visible', $in) ? AdminKit::flag($in, 'is_visible') : ($row ? (int)$row['is_visible'] : 1);
        foreach (AdminKit::lint($d['label'], $d['description']) as $w) $warnings[] = $w;
        return $d;
    }

    /** An automatic list's options, cleaned for its source (defaults are left out, so the stored text stays short). */
    public static function cleanOpts(string $source, array $in, array &$errors): array
    {
        $o = [];
        $programish = in_array($source, ['programs', 'programs_at'], true);
        $locish = in_array($source, ['locations', 'locations_primary', 'locations_others'], true);
        if ($programish) {
            $aud = (string)($in['audience'] ?? '');
            if (in_array($aud, ['kids', 'adults'], true)) $o['audience'] = $aud;
            $kinds = array_values(array_intersect(array_keys(self::PROGRAM_KINDS), array_map('strval', (array)($in['kinds'] ?? []))));
            if ($kinds) $o['kinds'] = $kinds;
            $loc = (string)($in['location'] ?? '');
            if ($loc !== '' && Db::value('SELECT 1 FROM locations WHERE slug = ?', [$loc])) $o['location'] = $loc;
            elseif ($source === 'programs_at') $errors[] = 'Choose the city for "Programs in one city".';
            if (($in['label_program'] ?? ($in['label'] ?? '')) === '{name}, {city}') $o['label'] = '{name}, {city}';
            if (!empty($in['images'])) $o['images'] = true;
            if (!empty($in['dedupe'])) $o['dedupe'] = true;
            if (($in['order'] ?? '') === 'name') $o['order'] = 'name';
            $ex = AdminKit::ids($in['exclude_programs'] ?? ($in['exclude'] ?? []));
            if ($ex) $o['exclude'] = $ex;
        }
        if ($locish) {
            if (($in['label_location'] ?? ($in['label'] ?? '')) === '{name} registration') $o['label'] = '{name} registration';
            $ex = AdminKit::ids($in['exclude_locations'] ?? ($in['exclude'] ?? []));
            if ($ex) $o['exclude'] = $ex;
            if ($source === 'locations') {
                $st = (string)($in['status'] ?? '');
                if (in_array($st, ['open', 'not_open'], true)) $o['status'] = $st;
                if (!empty($in['with_hub'])) $o['with_hub'] = true;
            }
        }
        if ($source === 'seasons' && ($in['label_season'] ?? ($in['label'] ?? '')) === 'long') $o['label'] = 'long';
        if ($source === 'pages_children') {
            $p = AdminKit::int($in, 'parent');
            if ($p && Db::value('SELECT 1 FROM pages WHERE id = ?', [$p])) $o['parent'] = $p;
            else $errors[] = 'Choose the page whose sub-pages the list shows.';
        }
        $limit = AdminKit::int($in, 'limit');
        if ($limit !== null && $limit > 0) $o['limit'] = min(24, $limit);
        if (!empty($in['keep_heading'])) $o['keep_heading'] = true;
        return $o;
    }

    /** True when putting $id under $parentId would loop (A under B under A). */
    public static function cycle(int $id, ?int $parentId): bool
    {
        $seen = [];
        while ($parentId) {
            if ($parentId === $id || isset($seen[$parentId])) return true;
            $seen[$parentId] = true;
            $parentId = (int)Db::value('SELECT parent_id FROM menu_items WHERE id = ?', [$parentId], 0) ?: null;
        }
        return false;
    }

    /** 0 for a top item, 1 for its child, and so on. */
    public static function depthOf(int $id): int
    {
        $d = 0;
        $seen = [];
        while (($id = (int)Db::value('SELECT parent_id FROM menu_items WHERE id = ?', [$id], 0)) > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $d++;
        }
        return $d;
    }

    // =====================================================================
    // Writes (item by item)
    // =====================================================================

    /** Insert or update one item. Returns its id. */
    public static function save(string $menu, ?array $row, array $data, ?int $by): int
    {
        $data['menu'] = $menu;
        $data['updated_at'] = Time::nowUtc();
        $data['updated_by'] = $by;
        if ($row) {
            if (($row['parent_id'] ?? null) !== null ? (int)$row['parent_id'] !== (int)$data['parent_id'] : $data['parent_id'] !== null) {
                $data['sort'] = self::nextSort($menu, $data['parent_id']);
            }
            Db::update('menu_items', $data, 'id = ?', [(int)$row['id']]);
            $id = (int)$row['id'];
        } else {
            $data['sort'] = self::nextSort($menu, $data['parent_id']);
            $id = Db::insert('menu_items', $data);
        }
        self::changed();
        return $id;
    }

    /** Delete an item and its sub-items. Returns the deleted row (or null). */
    public static function delete(int $id): ?array
    {
        $it = Db::one('SELECT * FROM menu_items WHERE id = ?', [$id]);
        if (!$it) return null;
        $ids = [$id];
        for ($i = 0; $i < count($ids) && $i < 500; $i++) {
            foreach (Db::column('SELECT id FROM menu_items WHERE parent_id = ?', [$ids[$i]]) as $k) $ids[] = (int)$k;
        }
        Db::tx(static function () use ($ids): void {
            foreach (array_reverse($ids) as $k) Db::delete('menu_items', 'id = ?', [$k]);
        });
        $it['_removed'] = count($ids);
        self::changed();
        return $it;
    }

    /** One place up ($dir -1) or down (+1) among its siblings. */
    public static function move(int $id, int $dir): bool
    {
        $row = Db::one('SELECT * FROM menu_items WHERE id = ?', [$id]);
        if (!$row) return false;
        $ids = self::siblings((string)$row['menu'], $row['parent_id'] !== null ? (int)$row['parent_id'] : null);
        $pos = array_search($id, $ids, true);
        $to = $pos === false ? false : $pos + ($dir < 0 ? -1 : 1);
        if ($to === false || $to < 0 || $to >= count($ids)) return false;
        [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
        self::renumber($ids);
        self::changed();
        return true;
    }

    /** The order of one group (drag and drop). $ids must be exactly that group's items. Returns how many moved. */
    public static function sort(string $menu, ?int $parent, array $ids): int
    {
        $ids = AdminKit::ids($ids);
        $have = self::siblings($menu, $parent);
        sort($have);
        $check = $ids;
        sort($check);
        if (!$ids || $check !== $have) throw new InvalidArgumentException('The order must list every item of that group once.');
        $n = self::renumber($ids);
        self::changed();
        return $n;
    }

    public static function setVisible(int $id, bool $on, ?int $by): ?array
    {
        $row = Db::one('SELECT * FROM menu_items WHERE id = ?', [$id]);
        if (!$row) return null;
        Db::update('menu_items', ['is_visible' => $on ? 1 : 0, 'updated_at' => Time::nowUtc(), 'updated_by' => $by], 'id = ?', [$id]);
        self::changed();
        return $row;
    }

    /** Menu settings: the phone menu's "Same as the header" and photo; a footer column's heading and switch. */
    public static function saveSettings(string $menu, array $data, ?int $by): void
    {
        $allowed = array_intersect_key($data, array_flip(['heading', 'show_heading', 'same_as', 'media_id', 'is_visible']));
        $allowed['updated_at'] = Time::nowUtc();
        $allowed['updated_by'] = $by;
        Db::update('menus', $allowed, 'mkey = ?', [$menu]);
        self::changed();
    }

    /** Unticking "Same as the header menu": copy the header's items into the phone menu (once, when it is empty). */
    public static function copyHeaderToPhone(?int $by): int
    {
        if ((int)Db::value("SELECT COUNT(*) FROM menu_items WHERE menu = 'phone'", [], 0) > 0) return 0;
        $n = 0;
        Db::tx(static function () use (&$n, $by): void {
            $copy = static function (?int $from, ?int $to) use (&$copy, &$n, $by): void {
                $rows = Db::all("SELECT * FROM menu_items WHERE menu = 'header' AND " . ($from ? 'parent_id = ?' : 'parent_id IS NULL') . ' ORDER BY sort, id', $from ? [$from] : []);
                foreach ($rows as $r) {
                    $old = (int)$r['id'];
                    unset($r['id']);
                    $r['menu'] = 'phone';
                    $r['parent_id'] = $to;
                    $r['updated_at'] = Time::nowUtc();
                    $r['updated_by'] = $by;
                    $new = Db::insert('menu_items', $r);
                    $n++;
                    $copy($old, $new);
                }
            };
            $copy(null, null);
        });
        self::changed();
        return $n;
    }

    // =====================================================================
    // "Switch this list to automatic" (P4) and "Reset this menu to the default"
    // =====================================================================

    /**
     * The automatic lists this menu can switch to: each automatic group of the default menu whose round 1 rows
     * ('replaces') all still sit, unchanged, under the same parent; plus any list of locations that matches
     * locations_others or locations_primary. Each: key, label (the list's name), parent_id, parent_label,
     * source, opts, replace_ids, sort, before (labels now), after (labels once switched).
     * @return list<array>
     */
    public static function candidates(string $menu): array
    {
        $out = [];
        $area = Menus::areaOf($menu);
        $walk = static function (array $items, ?int $parentId, string $parentLabel) use (&$walk, &$out, $menu, $area): void {
            foreach ($items as $it) {
                if (($it['kind'] ?? '') === 'auto' && !empty($it['replaces'])) {
                    [$src, $opts] = $it['auto'];
                    if (self::autoExists($menu, $parentId, (string)$src)) continue;
                    $ids = self::replacedIds($menu, $parentId, $it['replaces']);
                    if ($ids === null) continue;
                    $out[] = self::candidate($menu, $area, (string)$it['label'], $parentId, $parentLabel, (string)$src, (array)$opts, $ids);
                    continue;
                }
                if (!empty($it['children'])) {
                    $row = self::findRow($menu, $parentId, (string)$it['label']);
                    if ($row) $walk($it['children'], (int)$row['id'], (string)$it['label']);
                }
            }
        };
        $walk(self::defaults()[$menu] ?? [], null, '');

        return $out;
    }

    /** Replace the chosen candidates (keys, or every one when null) with their automatic lists. Returns how many. */
    public static function switchToAuto(string $menu, ?array $keys, ?int $by): int
    {
        $n = 0;
        foreach (self::candidates($menu) as $c) {
            if ($keys !== null && !in_array($c['key'], $keys, true)) continue;
            Db::tx(static function () use ($c, $menu, $by, &$n): void {
                Db::insert('menu_items', [
                    'menu' => $menu, 'parent_id' => $c['parent_id'], 'label' => $c['label'], 'path' => null, 'location_id' => null,
                    'opens_new_tab' => 0, 'sort' => $c['sort'], 'is_visible' => 1, 'kind' => 'auto', 'ref_id' => null,
                    'auto_source' => $c['source'], 'auto_opts' => Menus::encodeOpts($c['opts']), 'updated_at' => Time::nowUtc(), 'updated_by' => $by,
                ]);
                foreach ($c['replace_ids'] as $id) Db::delete('menu_items', 'id = ?', [$id]);
                $n++;
            });
        }
        if ($n) self::changed();
        return $n;
    }

    /** The default items (app/seed/menus.php), by menu. */
    public static function defaults(): array
    {
        return self::$defaults ??= (static fn(): array => require APP_DIR . '/seed/menus.php')();
    }

    public static function hasDefault(string $menu): bool
    {
        return !empty(self::defaults()[$menu]);
    }

    /** Delete every item of a menu and write its default items again. Returns how many were written. */
    public static function resetToDefault(string $menu): int
    {
        $items = self::defaults()[$menu] ?? null;
        if (!$items) throw new InvalidArgumentException('This menu has no default to go back to.');
        $n = 0;
        Db::tx(static function () use ($menu, $items, &$n): void {
            // Deepest rows first, so no parent row is ever deleted before its sub-items; then any row no tree reaches.
            $rows = self::flat(self::nested($menu));
            usort($rows, static fn(array $a, array $b): int => $b['depth'] <=> $a['depth']);
            foreach ($rows as $r) Db::delete('menu_items', 'id = ?', [(int)$r['id']]);
            Db::run('DELETE FROM menu_items WHERE menu = ?', [$menu]);
            $n = self::applyDefaults($menu, $items, 'reset');
        });
        self::changed();
        return $n;
    }

    /**
     * Write the default items of one menu (the fresh-install step and the reset). Rows are found by their
     * natural key (menu, parent, label), as Seeder::seedMenus writes them. Modes:
     *   missing  set the kind, reference, options and photo of rows the seeder has just written (never touches
     *            a row an admin has saved); an automatic group whose round 1 rows are still there is dropped,
     *            so an upgraded site keeps its own list until someone switches it (P4)
     *   refresh  (dev) rewrite those columns on every default row
     *   reset    insert every row (the menu was emptied first)
     */
    public static function applyDefaults(string $menu, array $items, string $mode, ?int $parent = null): int
    {
        $n = 0;
        $sort = 0;
        foreach ($items as $it) {
            $sort++;
            $label = (string)$it['label'];
            $row = self::findRow($menu, $parent, $label);
            $data = self::defaultRow($menu, $it, $parent, $sort);
            $keepHand = $mode === 'missing' && ($it['kind'] ?? '') === 'auto' && !empty($it['replaces'])
                && self::replacedIds($menu, $parent, $it['replaces']) !== null;
            if (!$row) {
                if ($keepHand) continue;
                $id = Db::insert('menu_items', $data);
                $n++;
            } else {
                $id = (int)$row['id'];
                $raw = $row['kind'] === 'link' && $row['ref_id'] === null && $row['auto_source'] === null && $row['updated_at'] === null
                    && $row['media_id'] === null && $row['description'] === null;
                if ($keepHand && $raw && trim((string)($row['path'] ?? '')) === '') {
                    Db::delete('menu_items', 'id = ?', [$id]);
                    continue;
                }
                if ($mode !== 'missing' || $raw) {
                    Db::update('menu_items', array_intersect_key($data, array_flip(self::KIND_COLS)), 'id = ?', [$id]);
                    $n++;
                }
            }
            if (!empty($it['children'])) $n += self::applyDefaults($menu, $it['children'], $mode, $id);
        }
        return $n;
    }

    /** Insert the menus rows that are missing (Menus::MENU_DEFAULTS). */
    public static function ensureMenus(): int
    {
        $n = 0;
        foreach (Menus::MENU_DEFAULTS as $key => [$label, $area, $heading, $showH, $col, $same, $sort, $vis, $sys]) {
            if (Db::value('SELECT 1 FROM menus WHERE mkey = ?', [$key])) continue;
            Db::insert('menus', [
                'mkey' => $key, 'label' => $label, 'area' => $area, 'heading' => $heading, 'show_heading' => $showH, 'col' => $col,
                'layout' => 'list', 'same_as' => $same, 'media_id' => null, 'sort' => $sort, 'is_visible' => $vis, 'is_system' => $sys,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * The default photos that are not menu items: the phone menu's header photo, and each location's photo
     * from app/seed/locations.php ('image', a seed media key). Only where still empty; a key whose media row
     * does not exist yet (a stand-in not seeded yet) is skipped.
     */
    public static function seedImages(string $mode): int
    {
        $n = 0;
        $phone = self::mediaIdByKey('planning');
        if ($phone && Db::value("SELECT 1 FROM menus WHERE mkey = 'phone' AND " . ($mode === 'refresh' ? '1 = 1' : 'media_id IS NULL'))) {
            $n += Db::update('menus', ['media_id' => $phone], 'mkey = ?', ['phone']);
        }
        return $n;
    }

    /** A media id from a seed media key (app/seed/media.php 'file' is media.original_name), or null. */
    public static function mediaIdByKey(?string $key): ?int
    {
        if ($key === null || $key === '') return null;
        self::$seedMedia ??= (static fn(): array => require APP_DIR . '/seed/media.php')();
        $file = self::$seedMedia[$key]['file'] ?? null;
        if ($file === null) return null;
        $id = Db::value('SELECT id FROM media WHERE original_name = ? ORDER BY id LIMIT 1', [$file]);
        return $id !== null ? (int)$id : null;
    }

    // ---------------------------------------------------------------------

    private static function changed(): void
    {
        Menus::flush();
        if (class_exists('SiteEvents')) SiteEvents::changed('menus');
    }

    private static function nextSort(string $menu, ?int $parent): int
    {
        return (int)Db::value('SELECT MAX(sort) FROM menu_items WHERE menu = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL'), $parent ? [$menu, $parent] : [$menu], 0) + 10;
    }

    /** @return list<int> */
    private static function siblings(string $menu, ?int $parent): array
    {
        return array_map('intval', Db::column('SELECT id FROM menu_items WHERE menu = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL') . ' ORDER BY sort, id', $parent ? [$menu, $parent] : [$menu]));
    }

    private static function renumber(array $ids): int
    {
        $n = 0;
        Db::tx(static function () use ($ids, &$n): void {
            foreach (array_values($ids) as $i => $id) $n += Db::run('UPDATE menu_items SET sort = ? WHERE id = ?', [($i + 1) * 10, (int)$id])->rowCount();
        });
        return $n;
    }

    private static function findRow(string $menu, ?int $parent, string $label): ?array
    {
        return Db::one('SELECT * FROM menu_items WHERE menu = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL') . ' AND label = ? ORDER BY id LIMIT 1',
            $parent ? [$menu, $parent, $label] : [$menu, $label]);
    }

    private static function autoExists(string $menu, ?int $parent, string $source): bool
    {
        return (bool)Db::value("SELECT 1 FROM menu_items WHERE menu = ? AND kind = 'auto' AND auto_source = ? AND " . ($parent ? 'parent_id = ?' : 'parent_id IS NULL'),
            $parent ? [$menu, $source, $parent] : [$menu, $source]);
    }

    /** The ids of the round 1 rows [label, path] under a parent, or null unless every one is still there. */
    private static function replacedIds(string $menu, ?int $parent, array $replaces): ?array
    {
        $ids = [];
        foreach ($replaces as [$label, $path]) {
            $args = $parent ? [$menu, $parent, $label] : [$menu, $label];
            $sql = 'SELECT id FROM menu_items WHERE menu = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL') . ' AND label = ? AND kind <> ?';
            $args[] = 'auto';
            if ($path === null) $sql .= ' AND path IS NULL';
            else {
                $sql .= ' AND path = ?';
                $args[] = $path;
            }
            $id = Db::value($sql . ' ORDER BY id LIMIT 1', $args);
            if ($id === null) return null;
            if ((int)Db::value('SELECT COUNT(*) FROM menu_items WHERE parent_id = ?', [(int)$id], 0) > 0) return null;   // it grew sub-items: leave it
            $ids[] = (int)$id;
        }
        return $ids ?: null;
    }

    private static function candidate(string $menu, string $area, string $label, ?int $parentId, string $parentLabel, string $src, array $opts, array $ids): array
    {
        $rows = Db::all('SELECT id, label, sort FROM menu_items WHERE id IN (' . Db::in($ids) . ') ORDER BY sort, id', $ids);
        return [
            'key'          => $menu . ':' . ($parentId ?? 0) . ':' . $src,
            'label'        => $label,
            'parent_id'    => $parentId,
            'parent_label' => $parentLabel,
            'source'       => $src,
            'opts'         => $opts,
            'replace_ids'  => $ids,
            'sort'         => (int)min(array_map(static fn(array $r): int => (int)$r['sort'], $rows)),
            'before'       => array_map(static fn(array $r): string => (string)$r['label'], $rows),
            'after'        => array_map(static fn(array $i): string => (string)$i['label'], Menus::expand($src, $opts, $area)),
        ];
    }

    /** One default item as a full menu_items row (the kind inferred as migration 1021 converts round 1 rows). */
    private static function defaultRow(string $menu, array $it, ?int $parent, int $sort): array
    {
        $path = isset($it['path']) && trim((string)$it['path']) !== '' ? (string)$it['path'] : null;
        $loc = !empty($it['location']) ? Db::value('SELECT id FROM locations WHERE slug = ?', [(string)$it['location']]) : null;
        $loc = $loc !== null ? (int)$loc : null;
        $kind = (string)($it['kind'] ?? '');
        if (!isset(Menus::KINDS[$kind])) {
            $hub = $loc ? Db::value('SELECT hub_page_path FROM locations WHERE id = ?', [$loc]) : null;
            $kind = match (true) {
                $loc !== null && $path !== null && $hub === $path => 'location',
                $path === null => 'heading',
                (bool)Db::value("SELECT 1 FROM pages WHERE path = ? AND status = 'published'", [$path]) => 'page',
                default => 'link',
            };
        }
        $ref = null;
        $src = null;
        $opts = null;
        switch ($kind) {
            case 'page':
                $ref = $path !== null ? Db::value('SELECT id FROM pages WHERE path = ?', [$path]) : null;
                break;
            case 'location':
                $ref = $loc;
                break;
            case 'program':
                $ref = !empty($it['program']) ? Db::value('SELECT id FROM programs WHERE slug = ?', [(string)$it['program']]) : null;
                break;
            case 'special':
                $src = (string)($it['special'] ?? '');
                break;
            case 'auto':
                [$src, $o] = $it['auto'];
                $opts = Menus::encodeOpts((array)$o);
                break;
        }
        return [
            'menu' => $menu, 'parent_id' => $parent, 'label' => (string)$it['label'], 'path' => $path, 'location_id' => $loc,
            'opens_new_tab' => 0, 'sort' => $sort, 'is_visible' => (int)($it['visible'] ?? 1), 'kind' => $kind,
            'ref_id' => $ref !== null ? (int)$ref : null, 'auto_source' => $src, 'auto_opts' => $opts,
            'description' => $it['description'] ?? null, 'media_id' => self::mediaIdByKey($it['image'] ?? null),
            'icon' => $it['icon'] ?? null, 'layout' => $it['layout'] ?? null, 'visible_from' => null, 'visible_until' => null,
        ];
    }
}
