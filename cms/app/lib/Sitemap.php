<?php
/**
 * GET /sitemap.xml, generated live from the published pages and blog posts,
 * cached for 10 minutes in storage/cache/sitemap.xml. A demo or staging copy
 * (base_url other than config live_url) gets an empty urlset.
 * Admin saves call Sitemap::invalidate().
 */
defined('ICM') or exit;

final class Sitemap
{
    public const TTL = 600;

    /** Route handler (R60). */
    public static function xml(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: no-cache');
        echo self::build();
    }

    public static function build(): string
    {
        if (isStaging()) return self::render([]);
        $file = STORAGE_DIR . '/cache/sitemap.xml';
        if (is_file($file) && (time() - (int)filemtime($file)) < self::TTL) {
            $cached = (string)file_get_contents($file);
            if ($cached !== '') return $cached;
        }
        $xml = self::render(self::urls());
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $xml) !== false) @rename($tmp, $file);
        return $xml;
    }

    /**
     * Every URL the sitemap lists: indexable published pages (in_sitemap = 1,
     * not a redirect source), published posts and published snapshot pages.
     * @return list<array{loc:string,lastmod:?string,images:list<string>}>
     */
    public static function urls(): array
    {
        $out = [];
        $sources = array_flip(Db::column('SELECT from_path FROM redirects'));

        foreach (Db::all("SELECT path, robots, updated_at, created_at, og_media_id FROM pages WHERE status = 'published' AND in_sitemap = 1 ORDER BY sort, path") as $p) {
            if (str_contains(strtolower((string)$p['robots']), 'noindex') || isset($sources[$p['path']])) continue;
            $img = $p['og_media_id'] ? mediaUrl((int)$p['og_media_id'], 1200, 'jpg', true) : '';
            $out[] = ['loc' => url($p['path']), 'lastmod' => self::day($p['updated_at'] ?: $p['created_at']), 'images' => $img ? [$img] : []];
        }
        foreach (Db::all(
            "SELECT slug, published_at, updated_at, featured_media_id FROM posts WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC",
            [Time::nowUtc()]
        ) as $p) {
            $img = $p['featured_media_id'] ? mediaUrl((int)$p['featured_media_id'], 1200, 'jpg', true) : '';
            $out[] = ['loc' => url('/blog/' . $p['slug'] . '/'), 'lastmod' => self::day($p['updated_at'] ?: $p['published_at']), 'images' => $img ? [$img] : []];
        }
        return $out;
    }

    public static function invalidate(): void
    {
        @unlink(STORAGE_DIR . '/cache/sitemap.xml');
    }

    private static function render(array $urls): string
    {
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rows = [];
        foreach ($urls as $u) {
            $row = '  <url><loc>' . $x($u['loc']) . '</loc>' . ($u['lastmod'] ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '');
            foreach ($u['images'] as $img) $row .= '<image:image><image:loc>' . $x($img) . '</image:loc></image:image>';
            $rows[] = $row . '</url>';
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n"
            . ($rows ? implode("\n", $rows) . "\n" : '')
            . "</urlset>\n";
    }

    private static function day(?string $utc): ?string
    {
        $d = Time::toLocal($utc);
        return $d ? $d->format('Y-m-d') : null;
    }
}
