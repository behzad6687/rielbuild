<?php
/**
 * Redirects: the redirects table (Admin > Redirects) and a few built-in rules. Every Location is absolute and
 * built from base_url. Resolution runs on the canonical path BEFORE the
 * slash/case normalisation redirect, so any legacy URL takes one hop.
 */
defined('ICM') or exit;

final class Redirects
{
    private static array $postCache = [];
    private static ?int $lastNewsPage = null;

    /**
     * X1 to X3, only for a request to '/' or '/index.php' with a query.
     * @return array{to:string,code:int}|null
     */
    public static function queryRules(array $query): ?array
    {
        if (isset($query['page_id']) && is_string($query['page_id']) && ctype_digit($query['page_id'])) {
            $p = Db::value("SELECT path FROM pages WHERE legacy_wp_id = ? AND status = 'published' LIMIT 1", [(int)$query['page_id']]);
            return ['to' => $p ?: '/', 'code' => 301];
        }
        if (isset($query['p']) && is_string($query['p']) && ctype_digit($query['p'])) {
            $slug = Db::value("SELECT slug FROM posts WHERE legacy_wp_id = ? AND status = 'published' AND published_at <= ? LIMIT 1", [(int)$query['p'], Time::nowUtc()]);
            return ['to' => $slug ? '/blog/' . $slug . '/' : '/blog/', 'code' => 301];
        }
        return null;
    }

    /**
     * Table first, then rules. $canon is the lowercase, slash-normalised path.
     * @return array{to:string,code:int,id?:int}|null  code 404 means "plain 404, not logged"
     */
    public static function resolve(string $canon): ?array
    {
        return self::lookup($canon) ?? self::rules($canon);
    }

    /** @return array{to:string,code:int,id:int}|null */
    public static function lookup(string $canon): ?array
    {
        $alts = array_values(array_unique([$canon, rtrim($canon, '/') ?: '/']));
        $row = Db::one(
            'SELECT id, to_path, code FROM redirects WHERE from_path IN (' . Db::in($alts) . ') ORDER BY LENGTH(from_path) DESC LIMIT 1',
            $alts
        );
        return $row ? ['to' => (string)$row['to_path'], 'code' => (int)$row['code'], 'id' => (int)$row['id']] : null;
    }

    /**
     * Built-in rules on a canonical path. RIELBUILD keeps its addresses, so the only rules are the
     * blog's: /blog/page/1/ and pages past the end go to /blog/, feed variants go to /feed/, and the
     * old static site's .php addresses go to their pages.
     * @return array{to:string,code:int}|null
     */
    public static function rules(string $canon): ?array
    {
        $blog = ['to' => '/blog/', 'code' => 301];
        if ($canon !== '/feed/' && preg_match('#^/(.+/)?feed/$#', $canon)) return ['to' => '/feed/', 'code' => 301];
        if (preg_match('#^/blog/page/(\d+)/$#', $canon, $m)) {
            $n = (int)$m[1];
            return ($n < 2 || $n > self::newsLastPage()) ? $blog : null;
        }
        if (preg_match('#^/page/\d+/$#', $canon)) return $blog;
        if (preg_match('#^/(contact/send\.php|404\.php|sitemap\.php|robots\.php)$#', $canon, $m)) {
            return ['to' => match ($m[1]) { 'sitemap.php' => '/sitemap.xml', 'robots.php' => '/robots.txt', 'contact/send.php' => '/contact/', default => '/' }, 'code' => 301];
        }
        return null;
    }

    /** Count the hit and send the redirect (or a 410 / plain 404). */
    public static function send(array $hit): never
    {
        if (!empty($hit['id'])) {
            try {
                Db::run('UPDATE redirects SET hits = hits + 1, last_hit_at = ? WHERE id = ?', [Time::nowUtc(), (int)$hit['id']]);
            } catch (Throwable $e) {
                Log::exception($e, 'redirect hit');
            }
        }
        $code = (int)$hit['code'];
        Headers::public();
        if ($code === 404) Router::plain404();
        if ($code === 410) {
            http_response_code(410);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache');
            echo "This page has been removed.\n";
            exit;
        }
        header('Cache-Control: max-age=3600');
        redirect(self::absolute((string)$hit['to']), in_array($code, [301, 302, 303, 307, 308], true) ? $code : 301);
    }

    /** Absolute Location from a stored target (a site path or a full URL). */
    public static function absolute(string $to): string
    {
        if (preg_match('#^https?://#i', $to)) return $to;
        return url('/' . ltrim($to, '/'));
    }

    /**
     * Normalise a from_path the way the router will look it up: lowercase,
     * no host, no query, collapsed slashes, a trailing slash unless the last
     * segment has a file extension. For seeds and the admin.
     */
    public static function normalizeFrom(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^https?://#i', $path)) $path = (string)parse_url($path, PHP_URL_PATH);
        $path = (string)preg_replace('/[?#].*$/', '', $path);
        return Router::canonicalise($path === '' ? '/' : $path);
    }

    /** Add or replace one redirect (upsert on from_path). Returns its id. */
    public static function add(string $from, string $to, int $code = 301, string $source = 'admin', ?string $note = null): int
    {
        $from = self::normalizeFrom($from);
        return Db::upsert('redirects', ['from_path'], [
            'from_path'  => $from,
            'to_path'    => $to,
            'code'       => $code,
            'source'     => $source,
            'note'       => $note,
            'hits'       => 0,
            'created_at' => Time::nowUtc(),
        ], ['to_path', 'code', 'source', 'note']);
    }

    /** True when $path is itself a redirect source (the admin refuses chains). */
    public static function isSource(string $path): bool
    {
        return self::lookup(self::normalizeFrom($path)) !== null;
    }

    /**
     * Follow a URL through the router's redirect logic without sending anything
     * ("Test a URL" in the admin). Each hop: from, to, code, via (table|rule|canonical).
     */
    public static function trace(string $path, int $maxHops = 5): array
    {
        $hops = [];
        $current = $path;
        for ($i = 0; $i < $maxHops; $i++) {
            $raw = (string)parse_url($current, PHP_URL_PATH) ?: '/';
            $canon = Router::canonicalise($raw);
            $hit = self::lookup($canon);
            $via = 'table';
            if (!$hit) {
                $hit = self::rules($canon);
                $via = 'rule';
            }
            if (!$hit && $canon !== $raw) {
                $hit = ['to' => $canon, 'code' => 301];
                $via = 'canonical';
            }
            if (!$hit) break;
            $hops[] = ['from' => $raw, 'to' => $hit['to'], 'code' => $hit['code'], 'via' => $via];
            if ($hit['code'] === 404 || $hit['code'] === 410 || preg_match('#^https?://#i', $hit['to'])) break;
            $current = $hit['to'];
        }
        return $hops;
    }

    public static function isPublishedPost(string $slug): bool
    {
        if (!array_key_exists($slug, self::$postCache)) {
            self::$postCache[$slug] = (bool)Db::value(
                "SELECT 1 FROM posts WHERE slug = ? AND status = 'published' AND published_at <= ? LIMIT 1",
                [$slug, Time::nowUtc()]
            );
        }
        return self::$postCache[$slug];
    }

    /** The last /news/ page number (ICM_NEWS_PER_PAGE posts a page). */
    public static function newsLastPage(): int
    {
        if (self::$lastNewsPage === null) {
            $n = (int)Db::value("SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at <= ?", [Time::nowUtc()], 0);
            self::$lastNewsPage = max(1, (int)ceil($n / ICM_NEWS_PER_PAGE));
        }
        return self::$lastNewsPage;
    }
}
