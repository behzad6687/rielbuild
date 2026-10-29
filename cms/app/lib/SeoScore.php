<?php
/**
 * The SEO check panel for posts and pages (spec 4.2.2, 4.2.13), adapted from NHF.
 * Not a ranking prediction: the mechanical things that are easy to get wrong,
 * each with a plain hint, scored so the list screens can show a number.
 * NHF's keyword-density and FAQ-count checks are dropped (spec 1.8).
 */
defined('ICM') or exit;

final class SeoScore
{
    public const TITLE_MIN = 30;
    public const TITLE_MAX = 60;        // design brief 16: under 60 characters, brand included
    public const META_MIN = 140;        // the editor's meter band (spec 4.2.2)
    public const META_MAX = 158;
    public const META_HARD_MIN = 70;    // the crawler's limits (spec 8.1)
    public const META_HARD_MAX = 160;

    /**
     * Audit one item. Keys of $x:
     *   kind ('post'|'page'), title, h1, seo_title, meta_description, intro_html, body_html,
     *   has_image (bool), image_alt, title_duplicate (bool), h1_duplicate (bool)
     * @return array{score:int, checks:list<array{key:string,ok:bool,weight:int,label:string,hint:string}>, title:string, unknown_links:list<string>, problems:list<string>}
     */
    public static function audit(array $x): array
    {
        $kind = ($x['kind'] ?? 'post') === 'page' ? 'page' : 'post';
        $title = self::finalTitle((string)($x['seo_title'] ?? ''), (string)(($x['h1'] ?? '') ?: ($x['title'] ?? '')));
        $tLen = mb_strlen($title);
        $meta = trim((string)($x['meta_description'] ?? ''));
        $mLen = mb_strlen($meta);
        $body = (string)($x['intro_html'] ?? '') . "\n" . (string)($x['body_html'] ?? '');
        $h1 = trim((string)(($x['h1'] ?? '') ?: ($x['title'] ?? '')));
        $bodyH1 = preg_match_all('/<h1[\s>]/i', $body);
        $links = Sanitizer::internalLinks($body);
        $unknown = self::unknownLinks($links);
        $problems = Plain::problems(($x['title'] ?? '') . "\n" . $meta . "\n" . $body);

        $checks = [
            [
                'key' => 'title', 'weight' => 15,
                'ok' => $tLen >= self::TITLE_MIN && $tLen <= self::TITLE_MAX,
                'label' => 'Search title length',
                'hint' => 'Currently ' . $tLen . ' characters, "| ' . siteName() . '" included. Aim for 30 to 60 so Google shows it whole.',
            ],
            [
                'key' => 'meta', 'weight' => 15,
                'ok' => $mLen >= self::META_MIN && $mLen <= self::META_MAX,
                'label' => 'Meta description length',
                'hint' => $mLen === 0
                    ? 'Add a meta description. Without one, Google picks a random line from the page.'
                    : 'Currently ' . $mLen . ' characters. Aim for 140 to 158.',
            ],
            [
                'key' => 'h1', 'weight' => 10,
                'ok' => $h1 !== '' && $bodyH1 === 0 && empty($x['h1_duplicate']),
                'label' => 'One clear main heading',
                'hint' => $bodyH1 > 0
                    ? 'The text has its own H1. The page already prints the heading, so use H2 inside the text.'
                    : (!empty($x['h1_duplicate']) ? 'Another ' . $kind . ' uses the same main heading. Make this one different.' : 'Add a heading.'),
            ],
            [
                'key' => 'unique', 'weight' => 10,
                'ok' => empty($x['title_duplicate']),
                'label' => 'Search title is unique',
                'hint' => 'Another page or post already uses this search title. Every title on the site must differ.',
            ],
            [
                'key' => 'image', 'weight' => 10,
                'ok' => !empty($x['has_image']),
                'label' => $kind === 'post' ? 'Has a featured image' : 'Has a social image',
                'hint' => 'Add an image so the ' . $kind . ' looks right when it is shared.',
            ],
            [
                'key' => 'alt', 'weight' => 10,
                'ok' => empty($x['has_image']) || trim((string)($x['image_alt'] ?? '')) !== '',
                'label' => 'Image has alt text',
                'hint' => 'Describe the image for people using screen readers. It is required before publishing.',
            ],
            [
                'key' => 'links', 'weight' => 10,
                'ok' => count($links) >= 1,
                'label' => 'Links to at least one other page on this site',
                'hint' => 'Link to a related page, for example the registration page or the calendar.',
            ],
            [
                'key' => 'known', 'weight' => 5,
                'ok' => $unknown === [],
                'label' => 'Internal links go to real pages',
                'hint' => $unknown ? 'Not found on the site: ' . implode(', ', array_slice($unknown, 0, 5)) . '.' : '',
            ],
            [
                'key' => 'style', 'weight' => 15,
                'ok' => $problems === [],
                'label' => 'Passes the house style check',
                'hint' => implode(' ', $problems),
            ],
        ];

        $total = array_sum(array_column($checks, 'weight'));
        $got = array_sum(array_map(fn($c) => $c['ok'] ? $c['weight'] : 0, $checks));
        return [
            'score'         => (int)round($got / max(1, $total) * 100),
            'checks'        => $checks,
            'title'         => $title,
            'unknown_links' => $unknown,
            'problems'      => $problems,
        ];
    }

    /** Audit a posts row (duplicates looked up in the database). */
    public static function forPost(array $post): array
    {
        $alt = trim((string)($post['featured_alt'] ?? ''));
        if ($alt === '' && !empty($post['featured_media_id'])) $alt = (string)(mediaRow((int)$post['featured_media_id'])['alt'] ?? '');
        $title = self::finalTitle((string)($post['seo_title'] ?? ''), (string)($post['title'] ?? ''));
        return self::audit([
            'kind'             => 'post',
            'title'            => (string)($post['title'] ?? ''),
            'seo_title'        => (string)($post['seo_title'] ?? ''),
            'meta_description' => (string)(($post['meta_description'] ?? '') ?: ''),
            'body_html'        => (string)($post['body_html'] ?? ''),
            'has_image'        => !empty($post['featured_media_id']),
            'image_alt'        => $alt,
            'title_duplicate'  => self::titleTaken($title, 'post', (int)($post['id'] ?? 0)),
            'h1_duplicate'     => self::safeBool(fn() => (bool)Db::value(
                'SELECT 1 FROM posts WHERE title = ? AND id <> ? LIMIT 1',
                [(string)($post['title'] ?? ''), (int)($post['id'] ?? 0)]
            )),
        ]);
    }

    /** Audit a pages row. */
    public static function forPage(array $page): array
    {
        $h1 = (string)(($page['h1'] ?? '') ?: ($page['title'] ?? ''));
        $title = self::finalTitle((string)($page['seo_title'] ?? ''), $h1);
        $alt = !empty($page['og_media_id']) ? (string)(mediaRow((int)$page['og_media_id'])['alt'] ?? '') : '';
        return self::audit([
            'kind'             => 'page',
            'title'            => (string)($page['title'] ?? ''),
            'h1'               => $h1,
            'seo_title'        => (string)($page['seo_title'] ?? ''),
            'meta_description' => (string)($page['meta_description'] ?? ''),
            'intro_html'       => (string)($page['intro_html'] ?? ''),
            'body_html'        => (string)($page['body_html'] ?? ''),
            'has_image'        => !empty($page['og_media_id']),
            'image_alt'        => $alt,
            'title_duplicate'  => self::titleTaken($title, 'page', (int)($page['id'] ?? 0)),
            'h1_duplicate'     => self::safeBool(fn() => (bool)Db::value(
                "SELECT 1 FROM pages WHERE COALESCE(NULLIF(h1, ''), title) = ? AND id <> ? AND status = 'published' LIMIT 1",
                [$h1, (int)($page['id'] ?? 0)]
            )),
        ]);
    }

    /** The <title> as it will print: the SEO title or the heading, with " | {site name}". */
    public static function finalTitle(string $seoTitle, string $fallback): string
    {
        $t = trim($seoTitle) !== '' ? trim($seoTitle) : trim($fallback);
        if ($t === '') return '';
        $name = siteName();
        $t = plainText($t);
        if ($t !== $name && !str_ends_with($t, $name)) $t .= ' | ' . $name;
        return $t;
    }

    /**
     * Site-relative link paths that match nothing we serve (reported, never removed;
     * spec 7.5). Known: published pages and posts, snapshot pages, news pages,
     * redirect sources, and files under /uploads and /assets.
     * @param list<string> $paths
     * @return list<string>
     */
    public static function unknownLinks(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $p = (string)$p;
            if ($p === '' || $p === '/') continue;
            if (preg_match('#^/(uploads|assets)/#', $p)) {
                if (!is_file(ROOT_DIR . rawurldecode($p))) $out[] = $p;
                continue;
            }
            $canon = Router::canonicalise($p);
            if (preg_match('#^/news/page/\d+/$#', $canon)) continue;
            try {
                if (Db::value("SELECT 1 FROM pages WHERE path = ? AND status = 'published' LIMIT 1", [$canon])) continue;
                if (preg_match('#^/blog/([^/]+)/$#', $canon, $m)
                    && Db::value("SELECT 1 FROM posts WHERE slug = ? AND status IN ('published','scheduled') LIMIT 1", [rawurldecode($m[1])])) continue;
                if (preg_match('#^/ranking/archive/([^/]+)/$#', $canon, $m)
                    && Db::value("SELECT 1 FROM ranking_snapshots WHERE slug = ? LIMIT 1", [$m[1]])) continue;
                if (Db::value('SELECT 1 FROM redirects WHERE from_path = ? LIMIT 1', [Redirects::normalizeFrom($canon)])) continue;
            } catch (Throwable) {
                continue;
            }
            if (in_array($canon, ['/sitemap.xml', '/feed/', '/calendar/ics/', '/search/'], true)) continue;
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /** Is this final title used by another page (published) or post? */
    private static function titleTaken(string $title, string $kind, int $id): bool
    {
        if ($title === '') return false;
        return self::safeBool(function () use ($title, $kind, $id): bool {
            foreach (Db::all("SELECT id, title, h1, seo_title FROM pages WHERE status = 'published'") as $p) {
                if ($kind === 'page' && (int)$p['id'] === $id) continue;
                if (self::finalTitle((string)$p['seo_title'], (string)(($p['h1'] ?? '') ?: $p['title'])) === $title) return true;
            }
            foreach (Db::all("SELECT id, title, seo_title FROM posts WHERE status IN ('published','scheduled')") as $p) {
                if ($kind === 'post' && (int)$p['id'] === $id) continue;
                if (self::finalTitle((string)$p['seo_title'], (string)$p['title']) === $title) return true;
            }
            return false;
        });
    }

    private static function safeBool(callable $fn): bool
    {
        try {
            return (bool)$fn();
        } catch (Throwable) {
            return false;
        }
    }
}
