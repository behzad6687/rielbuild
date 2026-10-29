<?php
/**
 * The public request lifecycle (spec 1.5.1). index.php calls Router::handle().
 *
 * Order: rewrite probe, host check, junk 404s, not-installed, maintenance,
 * exempt prefixes (dispatched as-is), canonicalise, query rules at '/',
 * redirects (table, then rules X4 to X14), the normalisation 301, pages,
 * pattern routes and functional routes from app/routes.php, then the 404.
 * A POST is never redirected. Every Location is absolute on base_url.
 */
defined('ICM') or exit;

final class Router
{
    /** Used as-is: no case or slash normalisation, dispatched straight to routes (step 3). */
    public const EXEMPT = ['/forms/'];
    /** Never blocked by maintenance or pending migrations (step 2); /robots.txt too. */
    public const MAINT_EXEMPT = [];
    /** Page templates that accept POST (none: forms post to /forms/{type}/). */
    public const POST_TEMPLATES = [];
    /** The installer's rewrite probe. Works before any database exists. */
    public const PROBE = '/__rewrite-probe/';

    private static ?array $routes = null;
    private static string $method = 'GET';
    private static string $path = '/';
    private static int $obBase = 0;

    /** GET, HEAD, POST ... of the current request. */
    public static function method(): string
    {
        return self::$method;
    }

    /** The canonical path of the current request (the raw path for exempt prefixes). */
    public static function path(): string
    {
        return self::$path;
    }

    /** The route table (app/routes.php). */
    public static function routes(): array
    {
        return self::$routes ??= require APP_DIR . '/routes.php';
    }

    /**
     * Step 4: lowercase (percent-escapes too), collapse repeated slashes,
     * '/index.php' to '/', and a trailing slash when the last segment has no '.'.
     */
    public static function canonicalise(string $raw): string
    {
        $p = strtolower($raw);
        $p = (string)preg_replace('#/{2,}#', '/', $p);
        if ($p === '' || $p[0] !== '/') $p = '/' . $p;
        if ($p === '/index.php') return '/';
        $last = substr($p, (int)strrpos($p, '/') + 1);
        if (!str_ends_with($p, '/') && !str_contains($last, '.')) $p .= '/';
        return $p;
    }

    public static function handle(): void
    {
        self::$obBase = ob_get_level();
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        self::$method = $method;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        [$raw] = explode('?', $uri, 2);
        $raw = (string)preg_replace('/#.*$/', '', $raw);
        if ($raw === '' || $raw[0] !== '/') $raw = '/' . $raw;
        $query = (string)($_SERVER['QUERY_STRING'] ?? (explode('?', $uri, 2)[1] ?? ''));
        self::$path = $raw;
        View::setRequestPath($raw);

        try {
            if ($raw === self::PROBE) respondJson(['ok' => true, 'probe' => 'icm']);

            self::hostCheck($method, $raw, $query);                       // 1b
            if (self::isJunk($raw)) self::plain404();

            if (!is_file(APP_DIR . '/config.local.php')) self::unavailable('setup');

            if (!self::maintenanceExempt($raw) && !self::hasBypass()) {  // 2
                if (Db::settingBool('site.maintenance') || Migrator::isPendingCached()) self::unavailable('maintenance');
            }

            foreach (self::EXEMPT as $prefix) {                           // 3
                if (str_starts_with($raw, $prefix)) {
                    if (self::dispatch($method, $raw)) return;
                    self::notFound(false);
                }
            }

            $canon = self::canonicalise($raw);                            // 4
            self::$path = $canon;
            View::setRequestPath($canon);

            if (($raw === '/' || strtolower($raw) === '/index.php') && $query !== '') {   // 5
                parse_str($query, $q);
                if ($hit = Redirects::queryRules($q)) Redirects::send($hit);
            }

            if ($hit = Redirects::resolve($canon)) Redirects::send($hit); // 6

            if ($canon !== $raw) {                                        // 7
                if ($method === 'GET' || $method === 'HEAD') {
                    Headers::public();
                    redirect(url($canon) . ($query !== '' ? '?' . $query : ''), 301);
                }
                self::notFound(false);
            }

            Content::publishDue();                                        // scheduled blog posts whose time has come
            $page = Db::one("SELECT * FROM pages WHERE path = ? AND status = 'published' LIMIT 1", [$canon]);   // 8a
            if ($page) {
                $acceptsPost = in_array($page['template'], self::POST_TEMPLATES, true);
                if (!in_array($method, ['GET', 'HEAD'], true) && !($method === 'POST' && $acceptsPost)) {
                    self::methodNotAllowed($acceptsPost ? 'GET, HEAD, POST' : 'GET, HEAD');
                }
                View::page($page);
                return;
            }

            if (self::dispatch($method, $canon)) return;                  // 8b, 8c, 8d, 9

            self::notFound(true);                                         // 10
        } catch (Throwable $e) {
            Log::exception($e, 'router ' . self::$path);
            self::serverError($e);
        }
    }

    /**
     * Match the route table. Returns true when a handler produced the response.
     * Sends 405 when a pattern matches but the method does not.
     */
    public static function dispatch(string $method, string $path): bool
    {
        $allowed = [];
        foreach (self::routes() as $r) {
            [$methods, $pattern, $handler] = $r;
            $flags = $r[3] ?? [];
            if (array_intersect(['page', 'static', 'doc'], $flags)) continue;   // documentation rows, never dispatched
            if (!preg_match($pattern, $path, $m)) continue;
            $ms = explode('|', $methods);
            if (in_array('GET', $ms, true)) $ms[] = 'HEAD';
            if (!in_array($method, $ms, true)) {
                $allowed = array_merge($allowed, $ms);
                continue;
            }
            array_shift($m);
            if (self::call($handler, $m, $flags)) return true;
        }
        if ($allowed) self::methodNotAllowed(implode(', ', array_unique($allowed)));
        return false;
    }

    /** Run one handler. Special handlers start with '@'. */
    private static function call(array $handler, array $args, array $flags): bool
    {
        $kind = (string)($handler[0] ?? '');
        switch ($kind) {
            case '@post':
                $post = Db::one(
                    "SELECT * FROM posts WHERE slug = ? AND status = 'published' AND published_at <= ? LIMIT 1",
                    [$args[0] ?? '', Time::nowUtc()]
                );
                if (!$post) return false;
                if (self::$method === 'GET') {
                    try {
                        Db::run('UPDATE posts SET views = views + 1 WHERE id = ?', [(int)$post['id']]);
                    } catch (Throwable) {
                    }
                }
                (new View())->render('pages/' . $handler[1], ['post' => $post, 'route' => $handler[1], 'params' => $args]);
                return true;

            case '@newspage':
                $news = Db::one("SELECT * FROM pages WHERE path = '/blog/' AND status = 'published' LIMIT 1");
                if (!$news) return false;
                View::page($news, ['pageNum' => (int)($args[0] ?? 1), 'params' => $args]);
                return true;

            case '@file':
                $file = ROOT_DIR . '/' . ltrim((string)$handler[1], '/');
                if (!is_file($file)) self::unavailable('building');
                require $file;
                return true;
        }

        [$class, $fn] = $handler + [null, null];
        if (!is_string($class) || !is_string($fn) || !class_exists($class) || !method_exists($class, $fn)) {
            Log::write('router', 'handler not built yet: ' . (is_string($class) ? $class : '?') . '::' . (is_string($fn) ? $fn : '?'));
            self::unavailable('building');
        }
        Headers::public(['noindex' => in_array('noindex', $flags, true)]);
        $class::$fn(...$args);
        return true;
    }

    // -----------------------------------------------------------------
    // Responses that stop the request
    // -----------------------------------------------------------------

    /** The designed 404 page (logs the path for the admin 404 log unless $log is false). */
    public static function notFound(bool $log = true): never
    {
        while (ob_get_level() > self::$obBase) ob_end_clean();
        http_response_code(404);
        if ($log && in_array(self::$method, ['GET', 'HEAD'], true)) self::log404(self::$path);
        try {
            (new View())->render('pages/404', ['route' => '404']);
        } catch (Throwable $e) {
            Log::exception($e, '404 page');
            header('Content-Type: text/plain; charset=utf-8');
            echo "Page not found\n";
        }
        exit;
    }

    /** A tiny 404 with no page render and no log (probes for wp-login.php and friends). */
    public static function plain404(): never
    {
        while (ob_get_level() > self::$obBase) ob_end_clean();
        http_response_code(404);
        Headers::public(['noindex' => true]);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        echo "Not found\n";
        exit;
    }

    public static function methodNotAllowed(string $allow): never
    {
        while (ob_get_level() > self::$obBase) ob_end_clean();
        http_response_code(405);
        Headers::public(['noindex' => true]);
        header('Allow: ' . $allow);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Method not allowed\n";
        exit;
    }

    /**
     * 503 with Retry-After: 'maintenance' (settings or pending migrations),
     * 'setup' (no config.local.php yet) or 'building' (a handler not written yet).
     */
    public static function unavailable(string $why): never
    {
        while (ob_get_level() > self::$obBase) ob_end_clean();
        http_response_code(503);
        header('Retry-After: 3600');
        // Headers::public sends X-Robots-Tag: noindex (noindex, nofollow on staging, spec 2.5).
        Headers::public(['noindex' => true]);
        Headers::html();
        $msg = match ($why) {
            'setup'    => 'This site is being set up. Please check back soon.',
            'building' => 'This part of the site is not ready yet. Please check back soon.',
            default    => self::maintenanceMessage(),
        };
        $phone = '647-895-4555';
        if ($why !== 'setup') {
            try {
                $phone = site('phone.main', $phone);
            } catch (Throwable) {
            }
        }
        $display = preg_replace('/^\+1\s*/', '', $phone);
        echo '<!doctype html><html lang="en-CA"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Back soon | ' . e(siteName()) . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f4f4;color:#222;'
            . 'font:17px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px}main{max-width:36rem}'
            . 'h1{font-size:2.2rem;line-height:1.05;margin:0 0 .5em}a{color:#17161a}</style></head>'
            . '<body><main><h1>Back soon</h1><p>' . e($msg) . '</p>'
            . '<p>Call or text <a href="' . e(telHref($phone)) . '">' . e($display) . '</a></p></main></body></html>';
        exit;
    }

    public static function serverError(?Throwable $e = null): never
    {
        while (ob_get_level() > self::$obBase) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $detail = ($e && cfg('debug')) ? '<pre>' . e(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) . '</pre>' : '';
        echo '<!doctype html><html lang="en-CA"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>Something went wrong | ' . e(siteName()) . '</title></head><body><main><h1>Something went wrong</h1>'
            . '<p>Something went wrong on our side. Please try again in a minute.</p>' . $detail . '</main></body></html>';
        exit;
    }

    // -----------------------------------------------------------------
    // Maintenance bypass cookie (set by the admin, Settings > Maintenance)
    // -----------------------------------------------------------------

    /** True when the request carries a valid, unexpired icm_bypass cookie. */
    public static function hasBypass(): bool
    {
        $c = $_COOKIE['rb_bypass'] ?? '';
        if (!is_string($c) || !preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $c, $m)) return false;
        if ((int)$m[1] < Time::ts()) return false;
        $secret = (string)cfg('app_secret');
        return $secret !== '' && hash_equals(hash_hmac('sha256', 'bypass|' . $m[1], $secret), $m[2]);
    }

    /** Admin only: set the 12-hour maintenance bypass cookie (HMAC-signed, HttpOnly, path /). */
    public static function issueBypassCookie(): void
    {
        $exp = Time::ts() + 43200;
        $value = $exp . '.' . hash_hmac('sha256', 'bypass|' . $exp, (string)cfg('app_secret'));
        setcookie('rb_bypass', $value, [
            'expires' => $exp, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function clearBypassCookie(): void
    {
        setcookie('rb_bypass', '', ['expires' => 1, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** HTTPS as seen by PHP (forwarded headers are not trusted). */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    // -----------------------------------------------------------------

    private static function hostCheck(string $method, string $raw, string $query): void
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = (string)preg_replace('/:\d+$/', '', $host);
        if ($host === '') return;
        $base = strtolower((string)parse_url((string)cfg('base_url'), PHP_URL_HOST));
        if ($host === $base || $host === 'localhost' || str_starts_with($host, '127.') || $host === '[::1]') return;
        if ($method === 'GET' || $method === 'HEAD') {
            Headers::public();
            redirect(url(self::canonicalise($raw)) . ($query !== '' ? '?' . $query : ''), 301);
        }
        self::plain404();
    }

    /** WordPress probes and script paths: plain 404, no page, no log (spec 1.7.2). */
    private static function isJunk(string $raw): bool
    {
        $l = strtolower($raw);
        if (preg_match('#^/(app|storage|vendor)(/|$)#', $l)) return true;
        if (preg_match('#^/(wp-admin|wp-includes|wp-json)(/|$)#', $l)) return true;
        if (preg_match('#^/wp-content/(?!uploads/)#', $l)) return true;
        if (in_array($l, ['/wp-login.php', '/xmlrpc.php'], true)) return true;
        if (preg_match('#(^|/)\.(?!well-known/)#', $l)) return true;
        return str_ends_with($l, '.php') && $l !== '/index.php';
    }

    private static function maintenanceExempt(string $raw): bool
    {
        if ($raw === '/robots.txt') return true;
        foreach (self::MAINT_EXEMPT as $p) {
            if (str_starts_with($raw, $p)) return true;
        }
        return false;
    }

    private static function maintenanceMessage(): string
    {
        $default = 'We are making a few updates to the site. Please check back in a few minutes.';
        try {
            return site('site.maintenance_message', $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /** Upsert the 404 log (path_hash), except for already-quiet probe paths. */
    private static function log404(string $path): void
    {
        try {
            $now = Time::nowUtc();
            $ref = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255) ?: null;
            $args = [hash('sha256', $path), mb_substr($path, 0, 500), $now, $now, $ref];
            $sql = 'INSERT INTO not_found_log (path_hash, path, hits, first_seen, last_seen, last_referrer) VALUES (?, ?, 1, ?, ?, ?)';
            $sql .= Db::isMysql()
                ? ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), last_referrer = COALESCE(VALUES(last_referrer), last_referrer)'
                : ' ON CONFLICT(path_hash) DO UPDATE SET hits = hits + 1, last_seen = excluded.last_seen, last_referrer = COALESCE(excluded.last_referrer, not_found_log.last_referrer)';
            Db::run($sql, $args);
        } catch (Throwable $e) {
            Log::exception($e, '404 log');
        }
    }
}
