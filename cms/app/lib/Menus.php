<?php
/**
 * The menu engine (from ICM). Writes, defaults and the admin rules live in MenusAdmin.
 *
 *   Menus::tree('header')     the header tree; Menus::phoneTree() the phone menu (the header unless it has its own items)
 *   Menus::expand($source)    one automatic list (the admin preview)
 *   Menus::expandPreview($row) what one saved or drafted item shows right now
 *
 * An item is a page (it follows the page when its address changes and hides while the page is a draft),
 * a web address, a heading (shown only with visible children) or an automatic list: the service pages,
 * the pages under a page, or the latest blog posts. Item shape: id, label, path, new_tab, children, kind,
 * ref_id, description, image (a public image media row), icon, layout (list | mega), opts, auto, still.
 */
defined('ICM') or exit;

final class Menus
{
    public const KINDS = [
        'page' => 'Page', 'link' => 'Web address', 'heading' => 'Heading', 'auto' => 'Automatic list',
    ];

    /** Special controls (none on this site; the admin screen reads the list). */
    public const SPECIALS = [];

    /** Automatic lists: key => [name, what it lists]. */
    public const AUTO_SOURCES = [
        'services'       => ['Services', 'Every published service page, each with its icon, photo and short line.'],
        'pages_children' => ['Pages under a page', 'The published pages that sit under one page.'],
        'recent_posts'   => ['Latest blog posts', 'The newest blog posts.'],
    ];

    /** menus.area => where a menu of that area shows (the Menus screen tabs). */
    public const AREAS = [
        'header' => 'the header on computers and tablets',
        'phone'  => 'the phone menu',
        'footer' => 'a footer column',
        'legal'  => 'the lowest row of the footer',
    ];

    public const LAYOUTS = ['list' => 'A plain list', 'mega' => 'Big panel with photos (mega menu)'];

    /** Icons an item may show (the service icons of partials/icons.php). */
    public const ICONS = Content::ICONS;

    public const THUMB_MIN = 3;
    public const THUMB_MAX = 5;
    public const THUMB_KINDS = ['page', 'link'];
    public const THUMB_SPECIALS = [];

    /** Deepest level built; the admin allows three. */
    public const MAX_DEPTH = 4;

    /**
     * The menus rows every site has (the installer inserts them):
     * mkey => [label, area, heading, show_heading, col, same_as, sort, is_visible, is_system].
     */
    public const MENU_DEFAULTS = [
        'header'          => ['Header menu', 'header', null, 1, 1, null, 10, 1, 1],
        'phone'           => ['Phone menu', 'phone', null, 1, 1, 'header', 20, 1, 1],
        'footer_services' => ['Footer, services', 'footer', 'Services', 1, 1, null, 30, 1, 1],
        'footer_company'  => ['Footer, company', 'footer', 'Company', 1, 2, null, 40, 1, 1],
        'footer_legal'    => ['Footer, lower row', 'legal', null, 0, 1, null, 80, 1, 1],
    ];

    private static ?bool $installed = null;
    /** @var array<string,array>|null menus rows by mkey */
    private static ?array $menus = null;
    /** @var array<int,array>|null menu_items rows by id */
    private static ?array $items = null;
    /** @var array<string,array<int,list<int>>> menu => parent id (0 = top) => child ids in order */
    private static array $kids = [];
    /** @var array<int,array>|null */
    /** @var array<int,array>|null */
    private static ?array $pages = null;
    /** @var array<int,array|false> */
    private static array $media = [];
    /** @var array<string,list<array>> */
    private static array $trees = [];

    /** Forget every memo (tests, and admin code that saves then reads in the same request). */
    public static function flush(): void
    {
        self::$installed = null;
        self::$menus = self::$items = self::$pages = null;
        self::$kids = [];
        self::$media = [];
        self::$trees = [];
    }

    /** True once migration 1021 has added the menus table and the item kinds. */
    public static function installed(): bool
    {
        if (self::$installed === null) {
            try {
                self::$installed = Db::tableExists('menus') && Db::hasColumn('menu_items', 'kind');
            } catch (Throwable) {
                self::$installed = false;
            }
        }
        return self::$installed;
    }

    // =====================================================================
    // Menus
    // =====================================================================

    /** The menus rows by mkey, in their screen order. @return array<string,array> */
    public static function all(): array
    {
        if (!self::installed()) return [];
        if (self::$menus === null) {
            self::$menus = [];
            foreach (Db::all('SELECT * FROM menus ORDER BY sort, mkey') as $m) self::$menus[(string)$m['mkey']] = $m;
        }
        return self::$menus;
    }

    public static function menu(string $key): ?array
    {
        return self::all()[self::key($key)] ?? null;
    }

    /** A menu name as round 1 passes it ('footer') to its key ('footer_quick'). */
    public static function key(string $name): string
    {
        $aliases = class_exists('Content') ? Content::MENU_ALIASES : ['footer' => 'footer_quick'];
        return $aliases[$name] ?? $name;
    }

    /** The area of a menu (its menus row, else guessed from the key). */
    public static function areaOf(string $key): string
    {
        $key = self::key($key);
        $m = self::menu($key);
        if ($m && isset(self::AREAS[(string)$m['area']])) return (string)$m['area'];
        return match (true) {
            $key === 'phone'                          => 'phone',
            $key === 'footer_legal'                   => 'legal',
            in_array($key, ['thumb_bar', 'mobile_bar'], true) => 'thumb',
            str_starts_with($key, 'footer')           => 'footer',
            default                                   => 'header',
        };
    }

    /** The photo of a menu (the phone menu's header photo), as a public image media row. */
    public static function menuImage(string $key): ?array
    {
        $m = self::menu($key);
        $id = $m ? (int)($m['media_id'] ?? 0) : 0;
        return $id > 0 ? self::mediaRows([$id])[$id] ?? null : null;
    }

    // =====================================================================
    // Trees
    // =====================================================================

    /**
     * One menu as a tree. $area overrides the menu's own area (the phone tree reads the header's items with the
     * phone rules). An empty, missing or hidden menu gives [] (once installed, nothing falls back to code).
     */
    public static function tree(string $key, ?string $area = null): array
    {
        if (!self::installed()) return [];
        $key = self::key($key);
        $area = $area !== null && isset(self::AREAS[$area]) ? $area : self::areaOf($key);
        $ck = $key . '|' . $area;
        if (isset(self::$trees[$ck])) return self::$trees[$ck];
        $m = self::menu($key);
        if ($m !== null && (int)$m['is_visible'] !== 1) return self::$trees[$ck] = [];
        try {
            self::load();
            $tree = self::build($key, 0, 0, $area, []);
            self::attachImages($tree);
        } catch (Throwable $e) {
            Log::exception($e, 'Menus::tree ' . $key);
            $tree = [];
        }
        return self::$trees[$ck] = $tree;
    }

    /** The phone menu: the header's items with the phone rules while "Same as the header" is on. */
    public static function phoneTree(): array
    {
        if (!self::installed()) return [];
        $m = self::menu('phone');
        if ($m !== null && (int)$m['is_visible'] !== 1) return [];
        $same = $m === null ? 'header' : trim((string)($m['same_as'] ?? ''));
        if ($same !== '' && $same !== 'phone') return self::tree($same, 'phone');
        return self::tree('phone', 'phone');
    }

    /**
     * The phone thumb bar (ADMIN 4.6): the top items of thumb_bar whose kind the bar allows (special finder,
     * call_sheet, menu_sheet, search; page; link; program), at most five, or [] when fewer than three remain.
     */
    public static function thumb(): array
    {
        $ok = [];
        foreach (self::tree('thumb_bar', 'thumb') as $it) {
            if (!in_array($it['kind'], self::THUMB_KINDS, true)) continue;
            if ($it['kind'] === 'special' && !in_array((string)$it['special'], self::THUMB_SPECIALS, true)) continue;
            $it['children'] = [];
            $ok[] = $it;
            if (count($ok) === self::THUMB_MAX) break;
        }
        return count($ok) >= self::THUMB_MIN ? $ok : [];
    }

    /**
     * What an item (a saved row or a drafted one: kind, ref_id, auto_source, auto_opts, label, path, location_id)
     * shows right now, with the rules of $area. An automatic list gives its expansion; anything else gives
     * one item or nothing (hidden right now).
     * @return list<array>
     */
    public static function expandPreview(array $row, ?string $area = null): array
    {
        if (!self::installed()) return [];
        $area = $area !== null && isset(self::AREAS[$area]) ? $area : 'header';
        $row += ['id' => 0, 'label' => '', 'path' => null, 'location_id' => null, 'opens_new_tab' => 0, 'is_visible' => 1,
                 'kind' => 'link', 'ref_id' => null, 'auto_source' => null, 'auto_opts' => null, 'description' => null,
                 'media_id' => null, 'icon' => null, 'layout' => null, 'visible_from' => null, 'visible_until' => null];
        try {
            self::load();
            if ($row['kind'] === 'auto') {
                $out = self::expandRow($row, $area);
            } else {
                $it = self::resolve($row, $area);
                $out = $it ? [$it] : [];
            }
            self::attachImages($out);
            return $out;
        } catch (Throwable $e) {
            Log::exception($e, 'Menus::expandPreview');
            return [];
        }
    }

    /**
     * One automatic list with its options, under the rules of $area ('' or null: no area rule, which gives the
     * raw source; locations_others then equals Locations::finderCities()['others'] exactly).
     * @return list<array>
     */
    public static function expand(string $source, array $opts = [], ?string $area = null): array
    {
        if (!isset(self::AUTO_SOURCES[$source])) return [];
        self::load();
        $out = self::expandSource($source, $opts, (string)$area, 0, null);
        self::attachImages($out);
        return $out;
    }

    /** The options of a row: auto_opts decoded (always an array). */
    public static function opts(?string $json): array
    {
        if ($json === null || trim($json) === '') return [];
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    /** Options as stored: keys sorted, so the same options always give the same text. */
    public static function encodeOpts(array $opts): ?string
    {
        if (!$opts) return null;
        ksort($opts);
        return (string)json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // =====================================================================
    // Building
    // =====================================================================

    private static function load(): void
    {
        if (self::$items !== null) return;
        self::$items = [];
        self::$kids = [];
        foreach (Db::all('SELECT * FROM menu_items ORDER BY menu, sort, id') as $r) {
            self::$items[(int)$r['id']] = $r;
        }
        foreach (self::$items as $id => $r) {
            $pid = $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
            // A child whose parent is missing or sits in another menu is never reached (it disappears, as in round 1).
            if ($pid !== 0 && (!isset(self::$items[$pid]) || self::$items[$pid]['menu'] !== $r['menu'])) continue;
            self::$kids[(string)$r['menu']][$pid][] = $id;
        }
    }

    /** @return list<array> */
    private static function build(string $menu, int $parent, int $depth, string $area, array $seen): array
    {
        $out = [];
        if ($depth >= self::MAX_DEPTH) return [];
        foreach (self::$kids[$menu][$parent] ?? [] as $id) {
            if (isset($seen[$id])) continue;
            $row = self::$items[$id];
            if (!self::live($row)) continue;
            if ($row['kind'] === 'auto') {
                $gen = self::expandRow($row, $area);
                if (!empty(self::opts($row['auto_opts'])['keep_heading'])) {
                    if ($gen) {
                        $h = self::base($row, 'heading');
                        $h['children'] = $gen;
                        $out[] = $h;
                    }
                } else {
                    foreach ($gen as $g) $out[] = $g;
                }
                continue;
            }
            $it = self::resolve($row, $area);
            if ($it === null) continue;
            $it['children'] = self::build($menu, $id, $depth + 1, $area, $seen + [$id => true]);
            if ($it['kind'] === 'heading' && !$it['children']) continue;   // a heading never dangles
            $out[] = $it;
        }
        return $out;
    }

    /** Shown, and inside its show from / until dates (Toronto dates). */
    private static function live(array $r): bool
    {
        if ((int)($r['is_visible'] ?? 1) !== 1) return false;
        $today = Time::today();
        if (!empty($r['visible_from']) && (string)$r['visible_from'] > $today) return false;
        if (!empty($r['visible_until']) && (string)$r['visible_until'] < $today) return false;
        return true;
    }

    /** The round 1 keys plus the round 2 keys, before the kind fills them in. */
    private static function base(array $r, ?string $kind = null): array
    {
        $opts = self::opts($r['auto_opts'] ?? null);
        $kind ??= (string)$r['kind'];
        return [
            'id'          => !empty($r['id']) ? (int)$r['id'] : null,
            'label'       => trim((string)($r['label'] ?? '')),
            'path'        => null,
            'new_tab'     => (int)($r['opens_new_tab'] ?? 0) === 1,
            'location'    => null,
            'status'      => null,
            'opens_on'    => null,
            'children'    => [],
            'kind'        => $kind,
            'ref_id'      => isset($r['ref_id']) && $r['ref_id'] !== null ? (int)$r['ref_id'] : null,
            'description' => trim((string)($r['description'] ?? '')) !== '' ? trim((string)$r['description']) : null,
            'image'       => !empty($r['media_id']) ? (int)$r['media_id'] : null,
            'icon'        => !empty($r['icon']) && isset(self::ICONS[$r['icon']]) ? (string)$r['icon'] : null,
            'special'     => null,
            'layout'      => !empty($r['layout']) && isset(self::LAYOUTS[$r['layout']]) ? (string)$r['layout'] : null,
            'opts'        => $opts,
            'auto'        => null,
        ];
    }

    /** One row as an item, or null when it is hidden right now (dangling, draft, hidden record, area switch). */
    private static function resolve(array $r, string $area): ?array
    {
        $kind = (string)$r['kind'];
        if (!isset(self::KINDS[$kind]) || $kind === 'auto') return null;
        $it = self::base($r);
        $ref = $it['ref_id'];

        switch ($kind) {
            case 'page':
                $p = $ref ? (self::pages()[$ref] ?? null) : null;
                if (!$p || $p['status'] !== 'published') return null;
                if ($it['label'] === '') $it['label'] = trim((string)($p['h1'] ?: $p['title']));
                $it['path'] = (string)$p['path'];
                if ($p['template'] === 'service') self::serviceExtras($it, $p);
                break;

            case 'link':
                $path = trim((string)($r['path'] ?? ''));
                if ($path === '') return null;
                $it['path'] = $path;
                break;

            case 'heading':
                break;

        }
        return $it['label'] === '' ? null : $it;
    }

    // ---------------------------------------------------------------------
    // Programs
    // ---------------------------------------------------------------------

    // ---------------------------------------------------------------------
    // Locations and pages
    // ---------------------------------------------------------------------

    /** Pages by id, one query per request. */
    private static function pages(): array
    {
        if (self::$pages === null) {
            self::$pages = [];
            foreach (Db::all('SELECT id, path, title, h1, status, template, data_json, location_id, parent_id, sort FROM pages ORDER BY sort, id') as $p) {
                self::$pages[(int)$p['id']] = $p;
            }
        }
        return self::$pages;
    }

    // ---------------------------------------------------------------------
    // Automatic lists
    // ---------------------------------------------------------------------

    /** @return list<array> */
    private static function expandRow(array $row, string $area): array
    {
        $source = (string)($row['auto_source'] ?? '');
        if (!isset(self::AUTO_SOURCES[$source])) return [];
        return self::expandSource($source, self::opts($row['auto_opts'] ?? null), $area, (int)($row['id'] ?? 0), $row);
    }

    /** @return list<array> */
    private static function expandSource(string $source, array $o, string $area, int $fromId, ?array $row): array
    {
        $out = [];
        try {
            $out = match ($source) {
                'services'       => self::autoServices(),
                'pages_children' => self::autoChildren($o),
                'recent_posts'   => self::autoPosts($o),
                default          => [],
            };
        } catch (Throwable $e) {
            Log::exception($e, 'Menus automatic list ' . $source);
            return [];
        }
        $limit = (int)($o['limit'] ?? 0);
        if ($limit > 0) $out = array_slice($out, 0, $limit);
        $layout = $row && !empty($row['layout']) && isset(self::LAYOUTS[$row['layout']]) ? (string)$row['layout'] : null;
        foreach ($out as &$it) {
            $it['id'] = null;
            $it['auto'] = ['id' => $fromId ?: null, 'source' => $source, 'images' => !empty($o['images']), 'layout' => $layout];
            if ($row && (int)($row['opens_new_tab'] ?? 0) === 1) $it['new_tab'] = true;
        }
        unset($it);
        return $out;
    }

    private static function blank(string $kind, ?int $ref = null): array
    {
        return self::base(['id' => null, 'label' => '', 'kind' => $kind, 'ref_id' => $ref], $kind);
    }

    /** The published service pages, each as a page item with its short line, icon and photo. */
    private static function autoServices(): array
    {
        $out = [];
        foreach (self::pages() as $p) {
            if ($p['template'] !== 'service' || $p['status'] !== 'published') continue;
            $it = self::blank('page', (int)$p['id']);
            $it['path'] = (string)$p['path'];
            self::serviceExtras($it, $p);
            if ($it['label'] === '') $it['label'] = trim((string)($p['h1'] ?: $p['title']));
            $out[] = $it;
        }
        return $out;
    }

    /** A service page's name, short line, icon and photo fill whatever the item leaves empty. */
    private static function serviceExtras(array &$it, array $p): void
    {
        $d = json_decode((string)($p['data_json'] ?? ''), true) ?: [];
        if ($it['label'] === '' && trim((string)($d['name'] ?? '')) !== '') $it['label'] = trim((string)$d['name']);
        if ($it['description'] === null && trim((string)($d['tag'] ?? '')) !== '') $it['description'] = trim((string)$d['tag']);
        if ($it['icon'] === null && isset(self::ICONS[$d['icon'] ?? ''])) $it['icon'] = (string)$d['icon'];
        if ($it['image'] === null && !empty($d['media']['photo'])) $it['image'] = (int)$d['media']['photo'];
        $it['still'] = (string)($d['still'] ?? '');
    }

    /** Published pages under one page (opts parent: a page id), in page order. */
    private static function autoChildren(array $o): array
    {
        $parent = (int)($o['parent'] ?? 0);
        if ($parent <= 0) return [];
        $out = [];
        foreach (self::pages() as $p) {
            if ((int)($p['parent_id'] ?? 0) !== $parent || $p['status'] !== 'published') continue;
            $it = self::blank('page', (int)$p['id']);
            $it['label'] = trim((string)($p['h1'] ?: $p['title']));
            $it['path'] = (string)$p['path'];
            $out[] = $it;
        }
        return $out;
    }

    /** The newest posts (opts limit, default 3), each with its featured photo. */
    private static function autoPosts(array $o): array
    {
        $out = [];
        foreach (Content::recentPosts(max(1, min(12, (int)($o['limit'] ?? 3)))) as $p) {
            $it = self::blank('link');
            $it['label'] = trim((string)$p['title']);
            $it['path'] = '/blog/' . $p['slug'] . '/';
            $it['image'] = !empty($p['featured_media_id']) ? (int)$p['featured_media_id'] : null;
            $out[] = $it;
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Images: one media query for every photo a tree names
    // ---------------------------------------------------------------------

    private static function attachImages(array &$items): void
    {
        $ids = [];
        $collect = static function (array $list) use (&$collect, &$ids): void {
            foreach ($list as $it) {
                if (is_int($it['image'] ?? null)) $ids[] = $it['image'];
                if (!empty($it['children'])) $collect($it['children']);
            }
        };
        $collect($items);
        $rows = $ids ? self::mediaRows($ids) : [];
        $apply = static function (array &$list) use (&$apply, $rows): void {
            foreach ($list as &$it) {
                if (is_int($it['image'] ?? null)) $it['image'] = $rows[$it['image']] ?? null;
                if (!empty($it['children'])) $apply($it['children']);
            }
            unset($it);
        };
        $apply($items);
    }

    /** Public image rows by id (a missing, private or non-image id gives nothing). @return array<int,array> */
    private static function mediaRows(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        $need = array_values(array_filter($ids, static fn(int $i): bool => !array_key_exists($i, self::$media)));
        if ($need) {
            foreach ($need as $i) self::$media[$i] = false;
            foreach (Db::all('SELECT * FROM media WHERE id IN (' . Db::in($need) . ')', $need) as $m) {
                if (($m['kind'] ?? '') === 'image' && (int)($m['is_private'] ?? 0) === 0) self::$media[(int)$m['id']] = $m;
            }
        }
        $out = [];
        foreach ($ids as $i) {
            if (self::$media[$i] !== false) $out[$i] = self::$media[$i];
        }
        return $out;
    }
}
