<?php
/**
 * Content rules for the admin (spec 4.2.2, 4.2.12, 4.2.13): posts and pages
 * (search index, slug and path changes with the redirect guard, the SEO check
 * through SeoScore, per-template data fields), redirects (no chains, no
 * loops), certificate CSV import (through W3's Certificates) and menus.
 */
defined('ICM') or exit;

final class ContentAdmin
{
    public const POST_STATUSES = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published'];
    public const PAGE_STATUSES = ['published' => 'Published', 'draft' => 'Draft'];
    public const ROBOTS = ['index,follow' => 'Show in search engines', 'noindex,follow' => 'Keep out of search engines (noindex)'];
    public const BLOCK_TYPES = [
        'rich' => 'Text section', 'accordion_item' => 'Question and answer (opens on click)', 'card' => 'Card',
        'cta' => 'Button', 'image' => 'Image', 'table' => 'Table', 'shared_ref' => 'Shared block',
    ];
    /** Page templates whose course comes from pages.course_id (none on this site). */
    public const COURSE_TEMPLATES = [];

    /** The page templates (views/pages/{key}.php) and what they are for. */
    public const TEMPLATES = [
        'home' => 'Home page', 'services' => 'Services overview', 'service' => 'One service', 'process' => 'How we work',
        'projects' => 'Projects gallery', 'about' => 'About', 'faq' => 'FAQ', 'areas' => 'Service areas', 'contact' => 'Contact',
        'blog_index' => 'Blog', 'content' => 'Text page',
    ];

    // ------------------------------------------------------------------
    // Posts and pages
    // ------------------------------------------------------------------

    public static function postPath(string $slug): string
    {
        return '/blog/' . $slug . '/';
    }

    /** Published and dated in the past. */
    public static function postLive(array $p): bool
    {
        return ($p['status'] ?? '') === 'published' && !empty($p['published_at']) && (string)$p['published_at'] <= Time::nowUtc();
    }

    public static function reindexPost(int $id): void
    {
        $p = Db::one('SELECT id, slug, title, excerpt, body_html, status, published_at FROM posts WHERE id = ?', [$id]);
        if ($p && self::postLive($p)) {
            Search::index('post', $id, self::postPath((string)$p['slug']), (string)$p['title'], ($p['excerpt'] ?? '') . ' ' . ($p['body_html'] ?? ''));
        } else {
            Search::remove('post', $id);
        }
    }

    public static function reindexPage(int $id): void
    {
        $p = Db::one('SELECT * FROM pages WHERE id = ?', [$id]);
        if (!$p || $p['status'] !== 'published' || str_contains((string)$p['robots'], 'noindex')) {
            Search::remove('page', $id);
            return;
        }
        $text = ($p['intro_html'] ?? '') . ' ' . ($p['body_html'] ?? '');
        foreach (Db::all('SELECT title, body_html FROM blocks WHERE page_id = ? AND is_visible = 1 ORDER BY sort', [$id]) as $b) {
            $text .= ' ' . ($b['title'] ?? '') . ' ' . ($b['body_html'] ?? '');
        }
        Search::index('page', $id, (string)$p['path'], (string)($p['h1'] ?: $p['title']), $text);
    }

    public static function reindexFaq(int $id): void
    {
        $f = Db::one('SELECT id, question, answer_html, is_visible FROM faqs WHERE id = ?', [$id]);
        if ($f && (int)$f['is_visible'] === 1) Search::index('faq', $id, '/faq/#faq-' . $id, (string)$f['question'], (string)$f['answer_html']);
        else Search::remove('faq', $id);
    }

    /**
     * The slug-change guard (3.2): a 301 from the old address to the new one.
     * A redirect FROM the new address is removed (it would hide the page), and
     * redirects that pointed at the old address now point straight at the new
     * one, so nothing chains. Returns how many older redirects were repointed.
     */
    public static function pathMoved(string $old, string $new): int
    {
        Db::delete('redirects', 'from_path = ?', [Redirects::normalizeFrom($new)]);
        $fixed = Db::update('redirects', ['to_path' => $new], 'to_path = ? OR to_path = ?', [$old, rtrim($old, '/')]);
        Redirects::add($old, $new, 301, 'slug_change');
        return $fixed;
    }

    /** A page path changed: redirect, then keep menus, hubs, embeds and programs on the new path. */
    public static function pageMoved(string $old, string $new): int
    {
        $n = self::pathMoved($old, $new);
        Db::update('menu_items', ['path' => $new], 'path = ?', [$old]);
        return $n;
    }

    /** The SEO check panel for a posts or pages row (W7a's SeoScore). */
    public static function seo(array $row, string $kind): array
    {
        if (class_exists('SeoScore')) return $kind === 'page' ? SeoScore::forPage($row) : SeoScore::forPost($row);
        return ['score' => 0, 'checks' => [], 'title' => self::fullTitle((string)($row['seo_title'] ?: $row['title']))];
    }

    /** The <title> as printed: " | {site name}" appended when missing. */
    public static function fullTitle(string $t): string
    {
        $t = trim(str_replace('*', '', $t));
        $name = siteName();
        if ($t === '') return $name;
        return str_ends_with($t, $name) ? $t : $t . ' | ' . $name;
    }

    /** Lowercased full titles of published pages and posts => how many use each. */
    public static function titleCounts(): array
    {
        $out = [];
        foreach (Db::all("SELECT seo_title, h1, title FROM pages WHERE status = 'published'") as $p) {
            $t = mb_strtolower(self::fullTitle((string)($p['seo_title'] ?: ($p['h1'] ?: $p['title']))));
            $out[$t] = ($out[$t] ?? 0) + 1;
        }
        foreach (Db::all("SELECT seo_title, title FROM posts WHERE status IN ('published', 'scheduled')") as $p) {
            $t = mb_strtolower(self::fullTitle((string)($p['seo_title'] ?: $p['title'])));
            $out[$t] = ($out[$t] ?? 0) + 1;
        }
        return $out;
    }

    /** The SEO health problems of one page for the list (empty: healthy). */
    public static function pageIssues(array $p, array $titleCounts): array
    {
        $issues = [];
        $full = self::fullTitle((string)($p['seo_title'] ?: ($p['h1'] ?: $p['title'])));
        $tl = mb_strlen($full);
        if ($tl < 30 || $tl > 60) $issues[] = 'Title is ' . $tl . ' characters';
        $ml = mb_strlen(trim((string)($p['meta_description'] ?? '')));
        if ($ml === 0) $issues[] = 'No meta description';
        elseif ($ml < 70 || $ml > 160) $issues[] = 'Meta description is ' . $ml . ' characters';
        if (trim((string)($p['h1'] ?: $p['title'])) === '') $issues[] = 'No H1';
        if (($p['status'] ?? '') === 'published' && ($titleCounts[mb_strtolower($full)] ?? 0) > 1) $issues[] = 'Title also used elsewhere';
        return $issues;
    }

    /**
     * The fields each template shows on the page editor ("Page wording and images"). Keys land in
     * pages.data_json; images in data.media.{key} (saved by the pages module because they need the upload).
     * Types: heading (a divider on the form, stores nothing), line (one line), text (a few lines), lines (one
     * item per line; "a | b" rows where the hint says so), image, select, icon (a service icon), check.
     * Wording left empty hides that element on the page.
     */
    public static function templateFields(string $tpl): array
    {
        $h = static fn(string $label, string $hint = ''): array => ['key' => '_h_' . md5($label), 'label' => $label, 'type' => 'heading', 'hint' => $hint];
        $line = static fn(string $key, string $label, string $hint = ''): array => ['key' => $key, 'label' => $label, 'type' => 'line', 'hint' => $hint];
        $text = static fn(string $key, string $label, string $hint = ''): array => ['key' => $key, 'label' => $label, 'type' => 'text', 'hint' => $hint];
        $lines = static fn(string $key, string $label, string $hint = ''): array => ['key' => $key, 'label' => $label, 'type' => 'lines', 'hint' => $hint];
        $img = static fn(string $key, string $label, string $hint = ''): array => ['key' => $key, 'label' => $label, 'type' => 'image', 'hint' => $hint];
        $accent = 'Wrap words in *stars* to show them in orange.';
        $hero = [
            $h('Top of the page', 'The big heading is the "Main heading (H1)" above. ' . $accent),
            $line('kicker', 'Small line above the heading'),
            $img('hero', 'Background photo'),
        ];
        return match ($tpl) {
            'home' => [
                $h('Scroll film: the words over the video', 'Five captions appear one after another as visitors scroll. Keep them short so they read over the video.'),
                $line('film1_kicker', 'Caption 1, small line'), $line('film1_title', 'Caption 1, heading'), $line('film1_sub', 'Caption 1, sub line'),
                $line('film2_kicker', 'Caption 2, small line'), $line('film2_title', 'Caption 2, heading'), $line('film2_sub', 'Caption 2, sub line'),
                $line('film3_kicker', 'Caption 3, small line'), $line('film3_title', 'Caption 3, heading'), $line('film3_sub', 'Caption 3, sub line'),
                $line('film4_kicker', 'Caption 4, small line'), $line('film4_title', 'Caption 4, heading'), $line('film4_sub', 'Caption 4, sub line'),
                $line('film5_title', 'Last caption, heading'), $line('film5_sub', 'Last caption, sub line'), $line('film_cta', 'Last caption, button label'),
                $h('What we build', 'The service cards come from the service pages (Pages > Services).'),
                $line('svc_kicker', 'Small line'), $line('svc_title', 'Heading'), $text('svc_lede', 'Text beside the heading'),
                $h('The way we work', 'The four promises are edited on the How we work page.'),
                $line('way_title', 'Heading'), $line('hold_label', 'Label under the press-and-hold dial'),
                $h('How it works', 'The four steps are edited on the How we work page.'),
                $line('steps_kicker', 'Small line'), $line('steps_title', 'Heading'),
                $h('Rooms gallery', 'The photos come from Projects (the ones marked "Show on the home page").'),
                $line('gal_title', 'Heading'), $text('gal_lede', 'Text beside the heading'),
                $h('Straight answers', 'The questions come from FAQs (the ones with "/" in "Also show on these pages").'),
                $line('faq_kicker', 'Small line'), $line('faq_title', 'Heading'), $text('faq_lede', 'Text under the heading'),
                $h('From the blog', 'The three newest posts (featured posts first).'),
                $line('blog_kicker', 'Small line'), $line('blog_title', 'Heading'),
                $h('Where we work', 'The list of communities is edited on the Service areas page.'),
                $line('areas_kicker', 'Small line'), $line('areas_title', 'Heading'), $text('areas_lede', 'Text under the heading'),
            ],
            'services' => array_merge($hero, [
                $h('Not sure where to start?'),
                $line('help_kicker', 'Small line'), $line('help_title', 'Heading'), $text('help_text', 'Text'), $img('help_image', 'Photo'),
            ]),
            'service' => [
                $h('The service', 'Used on this page, the service cards, the menus and the footer.'),
                $line('name', 'Service name', 'For example "Kitchen Renovation".'),
                $line('short', 'Short name', 'For example "Kitchens". Used in small labels and "Kitchens questions".'),
                $line('tag', 'One-line description', 'Shown on the cards and in the menu.'),
                ['key' => 'icon', 'label' => 'Icon', 'type' => 'select', 'options' => Content::ICONS],
                $img('photo', 'Main photo', 'Used at the top of the page, on the cards and in the menu.'),
                $h('How we do it'),
                $line('how_kicker', 'Small line'), $line('how_title', 'Heading'),
                $text('lead', 'Opening paragraph'),
                $lines('includes', 'What is included, one per line'),
                $text('time', 'How long it takes'),
                $h('The way we work and questions', 'The promises come from the How we work page. The questions are the FAQs in this service\'s category.'),
                $line('way_kicker', 'Small line over the promises'), $line('way_title', 'Promises heading'),
            ],
            'process' => array_merge($hero, [
                $h('The four steps', 'Also shown on the home page.'),
                $line('steps_kicker', 'Small line'), $line('steps_title', 'Heading'),
                $lines('steps', 'Steps, one per line: title | text', 'For example "Talk | A free visit to your home..."'),
                $img('step1', 'Step 1 photo'), $img('step2', 'Step 2 photo'), $img('step3', 'Step 3 photo'), $img('step4', 'Step 4 photo'),
                $h('The way we work (the promises)', 'Also shown on the home page and every service page.'),
                $line('way_title', 'Heading'), $line('hold_label', 'Label under the press-and-hold dial'),
                $lines('promises', 'Promises, one per line: title | text'),
                $h('The worries we take off your plate'),
                $line('worries_kicker', 'Small line'), $line('worries_title', 'Heading'), $text('worries_lede', 'Text beside the heading'),
                $lines('worries', 'Cards, one per line: question | answer'),
            ]),
            'projects' => array_merge($hero, [
                $h('Gallery', 'The photos are edited in Projects.'),
                $text('note', 'Note under the gallery'),
            ]),
            'about' => array_merge($hero, [
                $h('Who we are', 'The longer text is the "Body" above.'),
                $line('who_kicker', 'Small line'), $line('who_title', 'Heading'), $text('who_lede', 'Opening paragraph'),
                $img('who_image', 'Photo'), $line('who_tag', 'Label on the photo'),
                $h('What we stand for'),
                $line('values_kicker', 'Small line'), $line('values_title', 'Heading'),
                $lines('values', 'Values, one per line: title | text'),
                $lines('stats', 'Numbers, one per line: number | label', 'For example "1 | Project lead per job".'),
            ]),
            'faq' => array_merge($hero, [
                $h('Questions', 'The questions themselves are edited in FAQs.'),
                $line('general_kicker', 'Small line over the general questions'), $line('general_title', 'Heading over the general questions'),
                $line('byservice_kicker', 'Small line over the service questions'), $line('byservice_title', 'Heading over the service questions'),
            ]),
            'areas' => array_merge($hero, [
                $h('Communities we serve', 'This list is used on every page that shows the service areas, and in the search engine data.'),
                $lines('areas', 'Communities, one per line'),
                $line('list_kicker', 'Small line'), $line('list_title', 'Heading'), $text('list_lede', 'Text beside the heading'),
                $h('Popular in your area'),
                $line('popular_kicker', 'Small line'), $line('popular_title', 'Heading'),
                $lines('popular', 'Cards, one per line: title | text | link | link label', 'For example "Basements in Vaughan | Family rooms and guest suites. | /services/basement-finishing/ | Basement finishing".'),
            ]),
            'contact' => array_merge($hero, [
                $h('Talk to a project lead', 'The phone number and email come from Settings.'),
                $line('talk_kicker', 'Small line'), $line('talk_title', 'Heading'),
                $line('phone_note', 'Line under the phone number'), $line('email_note', 'Line under the email'),
                $line('area_line', 'Service area line'), $line('area_note', 'Line under the service area'),
                $text('next_text', '"What happens next" text'),
            ]),
            'blog_index' => $hero,
            'content' => array_merge($hero, [
                $h('Options'),
                ['key' => 'updated', 'label' => 'Show "Last updated" with the date the page was saved', 'type' => 'check'],
            ]),
            default => [],
        };
    }

    /** Merge the posted template fields into the page's data array; unknown keys are kept. */
    public static function applyTemplateData(string $tpl, array $data, array $posted, array &$warnings): array
    {
        foreach (self::templateFields($tpl) as $f) {
            $k = $f['key'];
            $v = $posted[$k] ?? null;
            switch ($f['type']) {
                case 'check':
                    if (!empty($v)) $data[$k] = true;
                    else unset($data[$k]);
                    break;
                case 'offswitch':
                    if (!empty($v)) unset($data[$k]);
                    else $data[$k] = false;
                    break;
                case 'path':
                    $v = trim(is_scalar($v) ? (string)$v : '');
                    if ($v === '') unset($data[$k]);
                    elseif (AdminKit::target($v, false) !== null) $data[$k] = $v;
                    else $warnings[] = $f['label'] . ' must start with /, so it was left unchanged.';
                    break;
                case 'select':
                    $v = is_scalar($v) ? (string)$v : '';
                    if ($v !== '' && isset($f['options'][$v])) $data[$k] = $v;
                    else unset($data[$k]);
                    break;
                case 'heading':
                    break;
                case 'line':
                    $v = trim(Sanitizer::text(is_scalar($v) ? (string)$v : '', false, 300));
                    if ($v !== '') $data[$k] = $v;
                    else unset($data[$k]);
                    break;
                case 'text':
                    $v = trim(Sanitizer::text(is_scalar($v) ? (string)$v : '', true, 2000));
                    if ($v !== '') $data[$k] = $v;
                    else unset($data[$k]);
                    break;
                case 'lines':
                    $lines = array_values(array_filter(array_map(static fn($l) => trim(Sanitizer::text($l, false, 400)), preg_split('/\R/', is_scalar($v) ? (string)$v : '') ?: []), 'strlen'));
                    if ($lines) $data[$k] = array_slice($lines, 0, 40);
                    else unset($data[$k]);
                    break;
                case 'shared':
                    $allowed = self::sharedKeys();
                    $keys = array_values(array_filter(is_array($v) ? array_map('strval', $v) : [], static fn($x) => in_array($x, $allowed, true)));
                    if ($keys) $data[$k] = $keys;
                    else unset($data[$k]);
                    break;
            }
        }
        return $data;
    }

    /** Keys of the shared (page-less) blocks. */
    public static function sharedKeys(): array
    {
        return array_map('strval', Db::column('SELECT block_key FROM blocks WHERE page_id IS NULL AND block_key IS NOT NULL ORDER BY block_key'));
    }

    /** Pages that use a shared block: by a shared_ref block or in their accordion data. */
    public static function sharedUsage(string $key): array
    {
        $out = [];
        foreach (Db::all("SELECT DISTINCT p.id, p.path, p.title FROM blocks b JOIN pages p ON p.id = b.page_id WHERE b.type = 'shared_ref' AND b.data_json LIKE ?" . Db::LIKE_ESCAPE, ['%' . Db::like($key) . '%']) as $p) {
            $out[(int)$p['id']] = $p;
        }
        foreach (Db::all('SELECT id, path, title, data_json FROM pages WHERE data_json LIKE ?' . Db::LIKE_ESCAPE, ['%' . Db::like($key) . '%']) as $p) {
            $d = json_decode((string)$p['data_json'], true);
            if (is_array($d) && in_array($key, (array)($d['accordion'] ?? []), true)) $out[(int)$p['id']] = $p;
        }
        return array_values($out);
    }

    // ------------------------------------------------------------------
    // Redirects (4.2.12)
    // ------------------------------------------------------------------

    /** Why this redirect cannot be saved, or null. */
    public static function redirectProblem(string $from, string $to, int $code, ?int $id = null): ?string
    {
        if (!in_array($code, [301, 302, 410], true)) return 'Choose 301, 302 or 410.';
        $from = trim($from);
        if ($from === '' || (!str_starts_with($from, '/') && !preg_match('#^https?://#i', $from))) return 'The old address must start with /.';
        $fromN = Redirects::normalizeFrom($from);
        if ($fromN === '/') return 'The home page cannot be redirected.';
        if (preg_match('#^/(admin|install|app|storage|vendor|assets)(/|$)#', $fromN)) return 'That address belongs to the site itself and cannot be redirected.';
        $dupe = Db::value('SELECT id FROM redirects WHERE from_path = ?', [$fromN]);
        if ($dupe && (int)$dupe !== (int)$id) return 'There is already a redirect from ' . $fromN . '. Edit that one instead.';
        if (Db::value("SELECT 1 FROM pages WHERE path = ? AND status = 'published'", [$fromN])) return $fromN . ' is a live page. Unpublish the page first, or the redirect would hide it.';
        if (preg_match('#^/blog/([^/]+)/$#', $fromN, $m) && Redirects::isPublishedPost($m[1])) return $fromN . ' is a live blog post.';
        if ($code === 410) return null;

        $clean = AdminKit::target($to);
        if ($clean === null) return 'The new address must start with / or be a full https:// address.';
        if (preg_match('#^https?://#i', $clean)) return null;
        $toN = Redirects::normalizeFrom((string)parse_url($clean, PHP_URL_PATH));
        if ($toN === $fromN) return 'A redirect cannot point to itself.';
        $hit = Redirects::lookup($toN);
        if ($hit && (int)($hit['id'] ?? 0) !== (int)$id) return $toN . ' is itself redirected to ' . $hit['to'] . '. Point straight there, so visitors take one hop.';
        $rule = Redirects::rules($toN);
        if ($rule && (int)$rule['code'] !== 404) return $toN . ' is redirected by a built-in rule to ' . $rule['to'] . '. Point straight there instead.';
        foreach (Redirects::trace($clean) as $hop) {
            if (Redirects::normalizeFrom((string)$hop['to']) === $fromN) return 'That would make a loop.';
        }
        return null;
    }

    /**
     * Save a redirect (insert or update) and repoint older redirects that led to
     * $from, so no chain forms. Returns ['id' => int, 'fixed' => int].
     */
    public static function saveRedirect(string $from, string $to, int $code, string $source, ?string $note, ?int $id = null): array
    {
        $fromN = Redirects::normalizeFrom($from);
        $to = $code === 410 ? '' : (string)AdminKit::target($to);
        $row = ['from_path' => $fromN, 'to_path' => $to, 'code' => $code, 'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null];
        return Db::tx(function () use ($row, $fromN, $to, $code, $source, $id) {
            if ($id) Db::update('redirects', $row, 'id = ?', [$id]);
            else $id = Db::insert('redirects', $row + ['source' => $source, 'hits' => 0, 'created_at' => Time::nowUtc()]);
            $fixed = 0;
            if ($code !== 410) {
                $fixed = Db::update('redirects', ['to_path' => $to], '(to_path = ? OR to_path = ?) AND id <> ?', [$fromN, rtrim($fromN, '/'), (int)$id]);
            }
            return ['id' => (int)$id, 'fixed' => $fixed];
        });
    }

    // ------------------------------------------------------------------
    // Certificates CSV import (4.2.13) on W3's Certificates::fromCsv()/save()
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Menus (4.2.13)
    // ------------------------------------------------------------------

    /** One menu in tree order, each row with a 'depth'. */
    public static function menuTree(string $menu): array
    {
        $rows = Db::all(
            'SELECT m.*, l.name AS loc_name, l.status AS loc_status FROM menu_items m
               LEFT JOIN locations l ON l.id = m.location_id WHERE m.menu = ? ORDER BY m.sort, m.id',
            [$menu]
        );
        $ids = [];
        foreach ($rows as $r) $ids[(int)$r['id']] = true;
        $by = [];
        foreach ($rows as $r) {
            $pid = (int)($r['parent_id'] ?? 0);
            $by[isset($ids[$pid]) ? $pid : 0][] = $r;
        }
        $out = [];
        $walk = static function (int $pid, int $depth) use (&$walk, &$by, &$out): void {
            foreach ($by[$pid] ?? [] as $r) {
                $r['depth'] = $depth;
                $out[] = $r;
                if ($depth < 4) $walk((int)$r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /** True when making $parentId the parent of $id would create a cycle. */
    public static function menuCycle(int $id, ?int $parentId): bool
    {
        $seen = [];
        while ($parentId) {
            if ($parentId === $id || isset($seen[$parentId])) return true;
            $seen[$parentId] = true;
            $parentId = (int)Db::value('SELECT parent_id FROM menu_items WHERE id = ?', [$parentId], 0) ?: null;
        }
        return false;
    }
}
