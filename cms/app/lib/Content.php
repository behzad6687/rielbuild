<?php
/**
 * Shared content services for the public templates (adapted from ICM's Content for RIELBUILD).
 *
 * Templates never query shared data directly: they call these memoised methods, so the header,
 * the footer and the page body can ask for the same thing without extra queries. Everything the
 * client edits in the admin arrives through here:
 *   pages + template data   Content::page('/process/'), Content::data($page), Content::field(...)
 *   services                the published service pages under /services/ (Content::services())
 *   areas, steps, promises  the Areas and How we work pages' fields (one source for every page)
 *   projects                Admin > Projects (Content::projects())
 *   faqs                    Admin > FAQs (general, or one per service)
 *   menus                   Admin > Menus (Content::nav())
 *   posts                   Admin > Blog posts
 * Images: Content::img() prints a media library picture, or the bundled still it replaced.
 */
defined('ICM') or exit;

final class Content
{
    /** Menus that exist on this site (Menus::MENU_DEFAULTS). */
    public const MENUS = ['header', 'phone', 'footer_services', 'footer_company', 'footer_legal'];
    public const MENU_ALIASES = ['footer' => 'footer_company'];

    /** The service icons the templates can draw (sprite ids in partials/icons.php). */
    public const ICONS = [
        'house' => 'House', 'kitchen' => 'Kitchen', 'bath' => 'Bathroom', 'basement' => 'Basement (stairs)',
        'level' => 'Level', 'frame' => 'Wall framing', 'doc' => 'Document', 'shield' => 'Shield', 'clock' => 'Clock',
        'phone' => 'Phone', 'check' => 'Check mark', 'star' => 'Star',
    ];

    /** Kept for the shared page editor (no scroll-film blocks on this site: the film's text is page data). */
    public const HERO_BANDS = [];

    private static array $cache = [];

    private static function once(string $key, callable $fn): mixed
    {
        if (!array_key_exists($key, self::$cache)) self::$cache[$key] = $fn();
        return self::$cache[$key];
    }

    /** Forget the per-request memo (tests, and admin code that saves then re-reads). */
    public static function flush(): void
    {
        self::$cache = [];
        if (class_exists('Menus', false)) Menus::flush();
    }

    // =====================================================================
    // Pages and their fields
    // =====================================================================

    /** A published page by path, or null. */
    public static function page(string $path): ?array
    {
        return self::once('page:' . $path, static fn() => Db::one("SELECT * FROM pages WHERE path = ? AND status = 'published' LIMIT 1", [$path]));
    }

    /** pages.data_json decoded. */
    public static function data(?array $page): array
    {
        if (!$page) return [];
        return self::once('data:' . (int)$page['id'], static fn() => json_decode((string)($page['data_json'] ?? ''), true) ?: []);
    }

    /** A text field of a page's data, else $default. */
    public static function field(array $data, string $key, string $default = ''): string
    {
        $v = $data[$key] ?? null;
        return is_scalar($v) && trim((string)$v) !== '' ? trim((string)$v) : $default;
    }

    /** A lines field: a list of strings. */
    public static function lines(array $data, string $key): array
    {
        return array_values(array_filter(array_map('strval', (array)($data[$key] ?? [])), static fn($l) => trim($l) !== ''));
    }

    /** A lines field of "a | b | c" rows, each split into exactly $n trimmed parts. */
    public static function rows(array $data, string $key, int $n = 2): array
    {
        $out = [];
        foreach (self::lines($data, $key) as $l) {
            $parts = array_map('trim', explode('|', $l, $n));
            $out[] = array_pad($parts, $n, '');
        }
        return $out;
    }

    /**
     * Text with *stars* marking the accent words: "Everything your home needs, *built plumb.*"
     * prints the starred words in the brand colour. Everything else is escaped.
     */
    public static function accent(string $s): string
    {
        $h = e($s);
        return (string)preg_replace('/\*([^*]+)\*/', '<span class="brass">$1</span>', $h);
    }

    // =====================================================================
    // Images
    // =====================================================================

    /** A public image by id, or by the file it was made from (exact original_name). */
    public static function media(int|string|null $ref): ?array
    {
        if ($ref === null || $ref === '' || $ref === 0) return null;
        if (is_int($ref) || ctype_digit((string)$ref)) {
            $m = mediaRow((int)$ref);
            return ($m && (int)$m['is_private'] === 0) ? $m : null;
        }
        $name = (string)$ref;
        return self::once('media:' . $name, static fn() => Db::one(
            "SELECT * FROM media WHERE original_name = ? AND is_private = 0 AND kind = 'image' ORDER BY id DESC LIMIT 1", [$name]
        ));
    }

    /** A page's image slot (data.media.{key}), or null. */
    public static function pageMedia(array $data, string $key): ?array
    {
        $id = $data['media'][$key] ?? null;
        return is_numeric($id) ? self::media((int)$id) : null;
    }

    /**
     * One image: the media library row when there is one, else the bundled still
     * (assets/img/{still}.webp + .jpg) it stands in for. $o: sizes, eager, w, h, class.
     */
    public static function img(?array $media, string $still, string $alt, array $o = []): string
    {
        if ($media) {
            $html = picture($media, $alt !== '' ? $alt : null, ['sizes' => $o['sizes'] ?? '100vw', 'eager' => !empty($o['eager']), 'class' => $o['class'] ?? null]);
            if ($html !== '') return $html;
        }
        if ($still === '') return '';
        $b = '/assets/img/' . ltrim($still, '/');
        $w = (int)($o['w'] ?? 1200);
        $h = (int)($o['h'] ?? 900);
        $load = !empty($o['eager']) ? ' fetchpriority="high"' : ' loading="lazy" decoding="async"';
        return '<picture' . (isset($o['class']) ? ' class="' . e($o['class']) . '"' : '') . '><source type="image/webp" srcset="' . e($b) . '-sm.webp 800w, ' . e($b) . '.webp 1600w" sizes="' . e($o['sizes'] ?? '100vw') . '">'
            . '<img src="' . e($b) . '.jpg" alt="' . e($alt) . '" width="' . $w . '" height="' . $h . '"' . $load . '></picture>';
    }

    /** A URL for a background or a small chip: the media variant, else the still's webp. */
    public static function imgUrl(?array $media, string $still, int|string $variant = 1200): string
    {
        if ($media) {
            $u = mediaUrl($media, $variant, 'webp');
            if ($u !== '') return $u;
        }
        return $still !== '' ? '/assets/img/' . ltrim($still, '/') . ((is_int($variant) && $variant <= 800) ? '-sm' : '') . '.webp' : '';
    }

    // =====================================================================
    // Services, areas, the way we work, projects
    // =====================================================================

    /**
     * The published service pages under /services/, in page order. Each:
     * id, path, slug, title, name, short, tag, icon, lead, media (row or null), still, page, data.
     */
    public static function services(): array
    {
        return self::once('services', static function (): array {
            $out = [];
            foreach (Db::all("SELECT * FROM pages WHERE template = 'service' AND status = 'published' ORDER BY sort, id") as $p) {
                $d = json_decode((string)($p['data_json'] ?? ''), true) ?: [];
                $slug = trim((string)preg_replace('#^/services/#', '', (string)$p['path']), '/');
                $name = self::field($d, 'name', (string)$p['title']);
                $out[] = [
                    'id'    => (int)$p['id'],
                    'path'  => (string)$p['path'],
                    'slug'  => $slug,
                    'title' => (string)$p['title'],
                    'name'  => $name,
                    'short' => self::field($d, 'short', $name),
                    'tag'   => self::field($d, 'tag'),
                    'icon'  => isset(self::ICONS[$d['icon'] ?? '']) ? (string)$d['icon'] : 'house',
                    'lead'  => self::field($d, 'lead'),
                    'media' => self::pageMedia($d, 'photo'),
                    'still' => self::field($d, 'still', 'stills/home-renovation'),
                    'page'  => $p,
                    'data'  => $d,
                ];
            }
            return $out;
        });
    }

    /** One service by its page id. */
    public static function service(int $pageId): ?array
    {
        foreach (self::services() as $s) if ($s['id'] === $pageId) return $s;
        return null;
    }

    /** The communities served: the Areas page's list. */
    public static function areas(): array
    {
        return self::once('areas', static function (): array {
            try {
                $p = self::page('/areas/');
            } catch (Throwable) {
                return [];
            }
            return self::lines(self::data($p), 'areas');
        });
    }

    /** The four steps (How we work page): [num, title, text, media, still]. */
    public static function steps(): array
    {
        return self::once('steps', static function (): array {
            $p = self::page('/process/');
            $d = self::data($p);
            $stills = ['stills/step-talk', 'stills/step-design', 'stills/step-build', 'stills/step-walk'];
            $out = [];
            foreach (self::rows($d, 'steps', 2) as $i => [$title, $text]) {
                $out[] = [sprintf('%02d', $i + 1), $title, $text, self::pageMedia($d, 'step' . ($i + 1)), $stills[$i] ?? 'stills/step-build'];
            }
            return $out;
        });
    }

    /** The promises ("The way we work" list): [title, text]. */
    public static function promises(): array
    {
        return self::once('promises', static fn() => self::rows(self::data(self::page('/process/')), 'promises', 2));
    }

    /** Projects (Admin > Projects), visible ones in order. $homeOnly: the ones marked for the home page. */
    public static function projects(bool $homeOnly = false): array
    {
        $all = self::once('projects', static function (): array {
            try {
                return Db::all('SELECT * FROM projects WHERE is_visible = 1 ORDER BY sort, id');
            } catch (Throwable) {
                return [];
            }
        });
        return $homeOnly ? array_values(array_filter($all, static fn($p) => (int)$p['on_home'] === 1)) : $all;
    }

    /** Project filter keys => labels. */
    public static function projectFilters(): array
    {
        return ['kitchen' => 'Kitchens', 'bathroom' => 'Bathrooms', 'basement' => 'Basements', 'home' => 'Full home'];
    }

    // =====================================================================
    // Navigation
    // =====================================================================

    /** A menu tree from Admin > Menus (Menus::tree). */
    public static function nav(string $menu): array
    {
        $menu = self::MENU_ALIASES[$menu] ?? $menu;
        try {
            return Menus::tree($menu);
        } catch (Throwable $e) {
            Log::exception($e, 'menu ' . $menu);
            return [];
        }
    }

    /** The header's "is-current" test: the item's path (or one of its children's) is a prefix of this request. */
    public static function navOn(array $item, string $here): bool
    {
        $paths = [];
        if (!empty($item['path'])) $paths[] = (string)$item['path'];
        foreach ($item['children'] ?? [] as $c) if (!empty($c['path'])) $paths[] = (string)$c['path'];
        foreach ($paths as $p) {
            $p = (string)strtok($p, '#?');
            if ($p === '/' ? $here === '/' : ($p !== '' && str_starts_with($here, $p))) return true;
        }
        return false;
    }

    // =====================================================================
    // Blog
    // =====================================================================

    /** Scheduled posts whose time has come become published. Returns how many. Cheap when there are none. */
    public static function publishDue(): int
    {
        try {
            $now = Time::nowUtc();
            if (!Db::value("SELECT 1 FROM posts WHERE status = 'scheduled' AND published_at <= ? LIMIT 1", [$now])) return 0;
            $ids = Db::column("SELECT id FROM posts WHERE status = 'scheduled' AND published_at <= ?", [$now]);
            foreach ($ids as $id) {
                Db::update('posts', ['status' => 'published'], 'id = ?', [(int)$id]);
                ContentAdmin::reindexPost((int)$id);
            }
            if ($ids) Sitemap::invalidate();
            return count($ids);
        } catch (Throwable $e) {
            Log::exception($e, 'publishDue');
            return 0;
        }
    }

    /** The latest published posts, newest first. */
    public static function recentPosts(int $limit = 3, ?int $excludeId = null): array
    {
        $limit = max(1, min(50, $limit));
        return self::once('recent:' . $limit . ':' . (int)$excludeId, static fn() => Db::all(
            "SELECT * FROM posts WHERE status = 'published' AND published_at <= ? AND id <> ?
           ORDER BY published_at DESC, id DESC LIMIT " . $limit,
            [Time::nowUtc(), (int)$excludeId]
        ));
    }

    /** Featured posts first, then the newest (the home and service pages' "From the blog"). */
    public static function homePosts(int $limit = 3): array
    {
        $limit = max(1, min(12, $limit));
        return self::once('homeposts:' . $limit, static fn() => Db::all(
            "SELECT * FROM posts WHERE status = 'published' AND published_at <= ?
           ORDER BY is_featured DESC, published_at DESC, id DESC LIMIT " . $limit,
            [Time::nowUtc()]
        ));
    }

    /** One page of /blog/ (ICM_NEWS_PER_PAGE a page): the Db::paginate shape. */
    public static function newsPage(int $page, ?string $category = null): array
    {
        $where = "status = 'published' AND published_at <= ?";
        $args = [Time::nowUtc()];
        if ($category !== null && $category !== '') {
            $where .= ' AND category = ?';
            $args[] = $category;
        }
        return self::once('news:' . $page . ':' . $category, static fn() => Db::paginate(
            'SELECT * FROM posts WHERE ' . $where . ' ORDER BY published_at DESC, id DESC', $args, max(1, $page), ICM_NEWS_PER_PAGE
        ));
    }

    /**
     * Posts related to a post or a service: same category first, then shared tags, then the newest.
     * $o: exclude (post id), category, tags (comma list), limit.
     */
    public static function relatedPosts(array $o): array
    {
        $limit = max(1, min(6, (int)($o['limit'] ?? 3)));
        $rows = Db::all("SELECT * FROM posts WHERE status = 'published' AND published_at <= ? AND id <> ? ORDER BY published_at DESC, id DESC LIMIT 60",
            [Time::nowUtc(), (int)($o['exclude'] ?? 0)]);
        $cat = (string)($o['category'] ?? '');
        $tags = array_filter(array_map(static fn($t) => mb_strtolower(trim($t)), explode(',', (string)($o['tags'] ?? ''))));
        $scored = [];
        foreach ($rows as $i => $r) {
            $score = ($cat !== '' && $r['category'] === $cat) ? 10 : 0;
            $mine = array_filter(array_map(static fn($t) => mb_strtolower(trim($t)), explode(',', (string)($r['tags'] ?? ''))));
            $score += 3 * count(array_intersect($tags, $mine));
            $scored[] = [$score, -$i, $r];
        }
        usort($scored, static fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        return array_map(static fn($x) => $x[2], array_slice($scored, 0, $limit));
    }

    /** Blog categories: the service slugs plus "tips" (posts.category). */
    public static function postCategories(): array
    {
        $out = [];
        foreach (self::services() as $s) $out[$s['slug']] = $s['name'];
        return $out + ['planning' => 'Planning and budgets', 'tips' => 'Tips and advice'];
    }

    /** Gallery images of a post (post_media), in order, with their captions (gallery_caption). */
    public static function postGallery(int $postId): array
    {
        return self::once('gallery:' . $postId, static fn() => Db::all(
            "SELECT m.*, pm.caption AS gallery_caption FROM post_media pm JOIN media m ON m.id = pm.media_id
              WHERE pm.post_id = ? AND m.kind = 'image' AND m.is_private = 0 ORDER BY pm.sort, pm.media_id",
            [$postId]
        ));
    }

    public static function postPath(array $post): string
    {
        return '/blog/' . rawurlencode((string)$post['slug']) . '/';
    }

    /** The Toronto date of a post, 'Y-m-d'. */
    public static function postDate(array $post): string
    {
        $d = Time::toLocal($post['published_at'] ?? null);
        return $d ? $d->format('Y-m-d') : '';
    }

    /** Minutes to read, at 220 words a minute. */
    public static function readMinutes(string $html): int
    {
        $n = count(preg_split('/\s+/u', Sanitizer::plain($html), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        return max(1, (int)round($n / 220));
    }

    /** GET /feed/: RSS 2.0 with the 20 latest posts. */
    public static function rss(): void
    {
        $posts = self::recentPosts(20);
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rfc = static fn(?string $utc): string => gmdate(DATE_RSS, $utc ? (int)strtotime($utc . ' UTC') : Time::ts());
        $items = '';
        foreach ($posts as $p) {
            $link = url(self::postPath($p));
            $desc = ($p['excerpt'] ?? '') !== '' ? excerpt((string)$p['excerpt'], 300) : excerpt((string)($p['body_html'] ?? ''), 300);
            $items .= "  <item>\n"
                . '    <title>' . $x((string)$p['title']) . "</title>\n"
                . '    <link>' . $x($link) . "</link>\n"
                . '    <guid isPermaLink="true">' . $x($link) . "</guid>\n"
                . '    <pubDate>' . $rfc($p['published_at']) . "</pubDate>\n"
                . '    <description>' . $x($desc) . "</description>\n"
                . "  </item>\n";
        }
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: no-cache');
        Headers::noindex();
        if (Router::method() === 'HEAD') return;
        $name = site('org.name', 'RIELBUILD');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n"
            . '  <title>' . $x($name) . " Blog</title>\n"
            . '  <link>' . $x(url('/blog/')) . "</link>\n"
            . '  <atom:link href="' . $x(url('/feed/')) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . '  <description>' . $x('Renovation advice from ' . $name . ': kitchens, bathrooms, basements and full home renovations across the GTA.') . "</description>\n"
            . "  <language>en-ca</language>\n"
            . '  <lastBuildDate>' . $rfc($posts[0]['published_at'] ?? null) . "</lastBuildDate>\n"
            . $items
            . "</channel>\n</rss>\n";
    }

    // =====================================================================
    // FAQs
    // =====================================================================

    /** Visible FAQs in admin order; $category limits them ('general' or a service slug). */
    public static function faqs(?string $category = null): array
    {
        $all = self::once('faqs', static fn() => Db::all('SELECT * FROM faqs WHERE is_visible = 1 ORDER BY sort, id'));
        if ($category === null) return $all;
        return array_values(array_filter($all, static fn($f) => (string)$f['category'] === $category));
    }

    /** FAQs whose "show on" lists this path (the home page uses '/'). */
    public static function faqsOn(string $path): array
    {
        return array_values(array_filter(self::faqs(), static function (array $f) use ($path): bool {
            $paths = preg_split('/[\s,]+/', (string)($f['show_on'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            return in_array($path, $paths, true);
        }));
    }

    /** The FAQ categories: general plus one per service. */
    public static function faqCategories(): array
    {
        $out = ['general' => 'General (money, time and trust)'];
        foreach (self::services() as $s) $out[$s['slug']] = $s['name'];
        return $out;
    }

    // =====================================================================
    // Blocks
    // =====================================================================

    /** A visible block by its block_key (shared or page block), or null. */
    public static function block(string $key): ?array
    {
        $all = self::once('blocks:keyed', static function (): array {
            $out = [];
            foreach (Db::all('SELECT * FROM blocks WHERE block_key IS NOT NULL AND is_visible = 1') as $b) $out[(string)$b['block_key']] = $b;
            return $out;
        });
        return $all[$key] ?? null;
    }

    /**
     * The visible blocks of a page, in order. A shared_ref block ({"key":"shared.howto"})
     * is replaced by the shared block it names (keeping the ref's title when set).
     */
    public static function blocks(int $pageId): array
    {
        return self::once('blocks:' . $pageId, static function () use ($pageId): array {
            $out = [];
            foreach (Db::all('SELECT * FROM blocks WHERE page_id = ? AND is_visible = 1 ORDER BY sort, id', [$pageId]) as $b) {
                if ($b['type'] === 'shared_ref') {
                    $d = json_decode((string)($b['data_json'] ?? ''), true);
                    $ref = is_array($d) && !empty($d['key']) ? self::block((string)$d['key']) : null;
                    if (!$ref) continue;
                    if (trim((string)($b['title'] ?? '')) !== '') $ref['title'] = $b['title'];
                    $b = $ref;
                }
                $out[] = $b;
            }
            return $out;
        });
    }

    /** An admin-editable line with a default: the block's title (or its body as text), else $default. */
    public static function text(string $key, string $default): string
    {
        $b = self::block($key);
        if (!$b) return $default;
        $t = trim((string)($b['title'] ?? ''));
        if ($t === '') $t = Sanitizer::plain((string)($b['body_html'] ?? ''));
        return $t !== '' ? $t : $default;
    }

    // =====================================================================
    // Render helpers (return HTML; every value escaped)
    // =====================================================================

    /** An icon from the sprite: <svg class="ico"><use href="#i-{name}"/></svg>. */
    public static function ico(string $name, string $class = 'ico'): string
    {
        return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="#i-' . e(preg_replace('/[^a-z0-9-]/', '', $name)) . '"/></svg>';
    }

    /** The logo: the plumb-bob mark, the name (its first four letters dark, the rest orange) and the tagline. */
    public static function brand(int $size = 40): string
    {
        $name = siteName();
        return '<svg class="brand__mark" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false"><use href="#i-mark"/></svg>'
            . '<span class="brand__text"><b>' . e(mb_substr($name, 0, 4)) . '<span>' . e(mb_substr($name, 4)) . '</span></b>'
            . '<small>' . e(site('org.tagline', 'Renovation & Remodelling')) . '</small></span>';
    }

    /** The blueprint dimension line under headings, drawn on scroll. */
    public static function dimline(): string
    {
        return '<svg class="dim" viewBox="0 0 260 14" preserveAspectRatio="none" aria-hidden="true" focusable="false" data-draw>'
            . '<path d="M1 2v10M259 2v10M1 7h258" fill="none" stroke="currentColor" stroke-width="1.4" pathLength="1"/></svg>';
    }

    /** The visible breadcrumbs, from the same trail as the BreadcrumbList node. */
    public static function crumbs(View $view): string
    {
        $trail = $view->breadcrumbs();
        if (count($trail) < 2) return '';
        $last = count($trail) - 1;
        $li = [];
        foreach (array_values($trail) as $i => [$name, $path]) {
            $li[] = $i === $last
                ? '<li aria-current="page">' . e($name) . '</li>'
                : '<li><a href="' . e($path) . '">' . e($name) . '</a></li>';
        }
        return '<nav aria-label="Breadcrumb"><ol class="crumbs">' . implode('', $li) . '</ol></nav>';
    }

    /** pages.intro_html, sanitised, or '' (the lede under an inner page's H1). */
    public static function intro(?array $page): string
    {
        $intro = trim((string)($page['intro_html'] ?? ''));
        return $intro !== '' ? Sanitizer::html($intro) : '';
    }

    /** Rich text for output: sanitised again at render. */
    public static function prose(?string $html): string
    {
        return Sanitizer::html($html);
    }

    /**
     * Give every <h2> in sanitised HTML a unique id and collect [id, text] pairs into $toc.
     * $used carries ids already on the page so two blocks never share one.
     */
    public static function anchorHeadings(string $html, array &$toc, array &$used = []): string
    {
        return (string)preg_replace_callback('#<h2>(.*?)</h2>#is', static function (array $m) use (&$toc, &$used): string {
            $text = Sanitizer::plain($m[1]);
            $base = slugify($text) ?: 'section';
            $id = $base;
            for ($i = 2; isset($used[$id]); $i++) $id = $base . '-' . $i;
            $used[$id] = true;
            $toc[] = [$id, $text];
            return '<h2 id="' . e($id) . '">' . $m[1] . '</h2>';
        }, $html);
    }

    /** The first $n words of an HTML or text string, with "..." when cut. */
    public static function words(string $html, int $n): string
    {
        $t = Sanitizer::plain($html);
        $w = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($w) <= $n) return $t;
        return rtrim(implode(' ', array_slice($w, 0, $n)), ' ,.;:') . '...';
    }

    /** The phone as printed in copy, "647-895-4555" (from phone.main, or the given number). */
    public static function phone(?string $number = null): string
    {
        $raw = $number ?? site('phone.main', '647-895-4555');
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        return strlen($d) === 10 ? substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6) : $raw;
    }

    /** No location statuses on this site (the shared menu engine asks). */
    public static function locationBadge(?string $status, ?string $opensOn): array
    {
        return [null, null];
    }

    /** Hero band lines (unused here; the shared page editor asks). */
    public static function bandLines(array $block): array
    {
        $d = json_decode((string)($block['data_json'] ?? ''), true);
        return is_array($d['lines'] ?? null) ? array_map('strval', $d['lines']) : ['', ''];
    }
}
