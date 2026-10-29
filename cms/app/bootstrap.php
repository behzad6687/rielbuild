<?php
/**
 * Boots the application: config, PHP runtime settings, the autoloader and the
 * helpers every template and class leans on (the ICM/NHF framework, adapted for RIELBUILD).
 *
 * The database is NOT connected here. Db connects lazily on the first query,
 * so the installer, the rewrite probe and the maintenance page work before a
 * database exists.
 *
 * Time: PHP runs in UTC (DATETIME columns store UTC). Use Time:: and
 * formatDate() for anything shown to people, which is always America/Toronto.
 */
defined('ICM') or exit;
if (defined('APP_DIR')) return;

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));          // public_html, the deploy root
// Round 2 dev isolation (CONTRACTS 0, V2.0.2): each work package's own storage folder, only under the CLI and
// the built-in server and only when ICM_STORAGE is set. Production never sets it.
define('STORAGE_DIR', (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && (string)getenv('ICM_STORAGE') !== '')
    ? rtrim(str_replace('\\', '/', (string)getenv('ICM_STORAGE')), '/') : ROOT_DIR . '/storage');
define('ICM_NEWS_PER_PAGE', 9);                // /blog/ and /blog/page/{n}/

$GLOBALS['cfg'] = require APP_DIR . '/config.php';
// The SQLite file may be given relative to the site folder (the installer writes 'storage/site.sqlite').
if (is_string($GLOBALS['cfg']['db']['sqlite'] ?? null) && $GLOBALS['cfg']['db']['sqlite'] !== '' && !str_starts_with($GLOBALS['cfg']['db']['sqlite'], '/')
    && !preg_match('#^[A-Za-z]:[\\/]#', $GLOBALS['cfg']['db']['sqlite'])) {
    $GLOBALS['cfg']['db']['sqlite'] = ROOT_DIR . '/' . ltrim($GLOBALS['cfg']['db']['sqlite'], '/');
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Errors never reach visitors on the live site, whatever hPanel's PHP options say (spec 2.5).
error_reporting(E_ALL);
ini_set('display_errors', !empty($GLOBALS['cfg']['debug']) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_DIR . '/logs/php-error.log');

// Sessions are admin only (spec 7.2). These settings have no effect until Auth starts one.
ini_set('session.save_path', STORAGE_DIR . '/sessions');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');

spl_autoload_register(static function (string $class): void {
    static $map = ['Lock' => 'Db', 'StripeError' => 'Stripe'];            // classes declared inside another lib file
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) return;
    $file = APP_DIR . '/lib/' . ($map[$class] ?? $class) . '.php';
    if (is_file($file)) require_once $file;
});

// ---------------------------------------------------------------------------
// Config, URLs and paths
// ---------------------------------------------------------------------------

/** Read a config value by dotted key: cfg('db.driver'). */
function cfg(string $key, mixed $default = null): mixed
{
    $node = $GLOBALS['cfg'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) return $default;
        $node = $node[$part];
    }
    return $node;
}

/** Escape for HTML text and attribute values. Use it on every dynamic value. */
function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Absolute URL on base_url (never built from the Host header): url('/about-us/'). */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim((string)cfg('base_url'), '/') . '/' . ltrim($path, '/');
}

/**
 * Root-relative page path with the WordPress-style trailing slash:
 * path('about-us') is '/about-us/'. File paths keep no slash: path('sitemap.xml').
 * A ?query or #fragment is kept after the slash.
 */
function path(string $p = ''): string
{
    $suffix = '';
    if (preg_match('/^([^?#]*)([?#].*)$/', $p, $m)) {
        $p = $m[1];
        $suffix = $m[2];
    }
    $p = '/' . trim($p, '/');
    $last = substr($p, (int)strrpos($p, '/') + 1);
    if ($p !== '/' && !str_contains($last, '.')) $p .= '/';
    return $p . $suffix;
}

/** Versioned static asset URL: asset('css/site.css') gives /assets/css/site.css?v=123456. */
function asset(string $p): string
{
    $p = ltrim($p, '/');
    $file = ROOT_DIR . '/assets/' . $p;
    $v = is_file($file) ? substr((string)filemtime($file), -6) : '1';
    return '/assets/' . $p . '?v=' . $v;
}

/**
 * True unless base_url is the live address (config live_url). A demo or staging copy is
 * noindex everywhere; set live_url to the real domain when the site goes live.
 */
function isStaging(): bool
{
    $live = rtrim((string)cfg('live_url', ''), '/');
    return $live === '' || rtrim((string)cfg('base_url'), '/') !== $live;
}

/**
 * Is this app/config.local.php source a plain data file in the installer's format?
 * The installer's header, then nothing but array syntax and literals. Checked by PHP
 * token, so a database password holding "$" or a word such as "include" or
 * "function" inside a quoted string is fine, while real code (variables, getenv,
 * require, closures) is refused. The installer and Settings > Site address both use
 * it before they rewrite the file.
 */
function isPlainConfigSource(string $src): bool
{
    if (!preg_match("/^<\\?php defined\\('ICM'\\) or exit;\\s*\\nreturn (\\[|array \\()/", $src)) return false;
    foreach (token_get_all($src) as $t) {
        if (!is_array($t)) {
            if (!in_array($t, ['(', ')', '[', ']', ',', ';', '-'], true)) return false;
            continue;
        }
        if (in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
                             T_DOUBLE_ARROW, T_RETURN, T_LOGICAL_OR, T_EXIT, T_ARRAY], true)) continue;
        if ($t[0] === T_STRING && in_array(strtolower($t[1]), ['defined', 'true', 'false', 'null'], true)) continue;
        return false;
    }
    return true;
}

/** The build id from app/version.php (written by build-zip), or 'dev'. */
function appVersion(): string
{
    static $v = null;
    if ($v !== null) return $v;
    $file = APP_DIR . '/version.php';
    $data = is_file($file) ? require $file : null;
    return $v = (is_array($data) && !empty($data['build'])) ? (string)$data['build'] : 'dev';
}

/** Absolute path inside storage/. */
function storagePath(string $rel = ''): string
{
    return STORAGE_DIR . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
}

/**
 * Absolute path inside private storage. paths.private is stored RELATIVE to
 * public_html (spec 2.5) and resolved here at runtime, never saved absolute.
 */
function privatePath(string $rel = ''): string
{
    $base = trim((string)cfg('paths.private', 'storage/private'), '/\\');
    $abs = realpath(ROOT_DIR . '/' . $base) ?: ROOT_DIR . '/' . $base;
    $abs = str_replace('\\', '/', $abs);
    return $abs . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
}

/** Delete cache files matching a glob inside storage/cache: cacheForget('finder-*.json'). */
function cacheForget(string $glob): int
{
    if (str_contains($glob, '..') || str_contains($glob, '/')) return 0;
    $n = 0;
    foreach (glob(STORAGE_DIR . '/cache/' . $glob) ?: [] as $f) {
        if (is_file($f) && @unlink($f)) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Settings shortcuts
// ---------------------------------------------------------------------------

/** A non-secret setting as a string, '' (or $default) when empty or missing. */
function site(string $key, string $default = ''): string
{
    return (string)Db::setting($key, $default);
}

/** The business name (Settings > Identity), for titles and copy. */
function siteName(): string
{
    try {
        return site('org.name', (string)cfg('site_name', 'RIELBUILD'));
    } catch (Throwable) {
        return (string)cfg('site_name', 'RIELBUILD');
    }
}

/** A heading as plain text: the *stars* that mark the orange accent words removed. */
function plainText(?string $s): string
{
    return trim(str_replace('*', '', (string)$s));
}

// ---------------------------------------------------------------------------
// Text, dates, money, phones
// ---------------------------------------------------------------------------

function slugify(string $s): string
{
    $s = Search::normalize($s);
    $s = str_replace(['&', '+'], ' and ', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/** Plain-text excerpt cut at a word boundary near $len characters. */
function excerpt(string $html, int $len = 165): string
{
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if (mb_strlen($t) <= $len) return $t;
    $cut = mb_substr($t, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, ' ,.;:') . '...';
}

/**
 * Canadian long date: "September 13, 2026". A DATE (YYYY-MM-DD) is shown as is;
 * a DATETIME is read as UTC and shown in Toronto time.
 */
function formatDate(?string $value, bool $withTime = false): string
{
    return Time::long($value, $withTime);
}

/** Integer cents to "$149" or "$149.50". */
function money(?int $cents, bool $forceCents = false): string
{
    if ($cents === null) return '';
    $neg = $cents < 0;
    $cents = abs($cents);
    $s = ($cents % 100 === 0 && !$forceCents) ? number_format(intdiv($cents, 100)) : number_format($cents / 100, 2);
    return ($neg ? '-' : '') . '$' . $s;
}

/** "+1 647-787-0704" to "+16477870704". */
function e164(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) === 10) $d = '1' . $d;
    return $d === '' ? '' : '+' . $d;
}

function telHref(string $phone): string
{
    return 'tel:' . e164($phone);
}

/** sms: link with an optional prefilled body. site.js rewrites the separator for iOS (spec 9.4). */
function smsHref(string $phone, string $body = ''): string
{
    return 'sms:' . e164($phone) . ($body !== '' ? '?body=' . rawurlencode($body) : '');
}

// ---------------------------------------------------------------------------
// HTML helpers
// ---------------------------------------------------------------------------

/** Build HTML attributes: attrs(['href' => $u, 'hidden' => true, 'data-x' => null]). */
function attrs(array $a): string
{
    $out = '';
    foreach ($a as $k => $v) {
        if ($v === null || $v === false || !preg_match('/^[a-z][a-z0-9:_.-]*$/i', (string)$k)) continue;
        $out .= $v === true ? ' ' . $k : ' ' . $k . '="' . e($v) . '"';
    }
    return $out;
}

/** JSON for an inline <script type="application/json"> or JSON-LD block (spec 7.4). */
function jsonScript(mixed $data): string
{
    return (string)json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

/**
 * The status pill (design brief 2.5): status by shape, never by colour alone.
 * $status is an effective course or location status.
 */
function statusPill(string $status, ?string $opensOn = null, ?string $label = null): string
{
    [$cls, $word] = match ($status) {
        'open'         => ['open', 'Open'],
        'fully_booked' => ['full', 'Fully booked'],
        'closed'       => ['closed', 'Closed'],
        'password'     => ['approval', 'Director approval'],
        'opens_on', 'coming_soon' => ['opens', $opensOn ? 'Opens ' . Time::long($opensOn) : 'Coming soon'],
        default        => ['', ''],
    };
    if ($cls === '') return '';
    return '<span class="pill pill--' . $cls . '"><span class="pill__glyph" aria-hidden="true"></span>'
        . e($label ?? $word) . '</span>';
}

/**
 * The one call to action. The label is "Find your level" everywhere, forever.
 * Without JavaScript it links to the hub's finder; site.js opens the dialog.
 * $opts: city (preselect), home (true on the homepage), class (extra classes).
 */
function findYourLevel(array $opts = []): string
{
    $href = !empty($opts['home']) ? '#finder' : '/all-online-registrations/#finder';
    return '<a' . attrs([
        'class'            => trim('btn btn--cta ' . ($opts['class'] ?? '')),
        'href'             => $href,
        'data-finder-open' => true,
        'data-city'        => $opts['city'] ?? null,
    ]) . '>Find your level</a>';
}

/** One media row, memoised. */
function mediaRow(int $id): ?array
{
    static $cache = [];
    if ($id <= 0) return null;
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }
    return $cache[$id];
}

/**
 * URL of a media variant. Images: $variant is a width (480|800|1200|1600),
 * 'og' (1200x630 JPEG) or 'thumb' (320 square WebP). PDFs and docs ignore it.
 */
function mediaUrl(int|array|null $media, int|string $variant = 1200, string $ext = 'jpg', bool $absolute = false): string
{
    $m = is_array($media) ? $media : ($media ? mediaRow((int)$media) : null);
    if (!$m || empty($m['path'])) return '';
    $base = '/' . ltrim((string)$m['path'], '/');
    if (($m['kind'] ?? 'image') !== 'image') {
        $u = $base;
    } elseif ($variant === 'og') {
        $u = $base . '-og.jpg';
    } elseif ($variant === 'thumb') {
        $u = $base . '-thumb.webp';
    } else {
        // Only widths that were derived exist on disk (Img never upscales past 1.25x),
        // so ask for the nearest stored width at or below the request, else the smallest.
        $want = (int)$variant;
        $variants = json_decode((string)($m['variants_json'] ?? ''), true);
        $widths = array_values(array_filter(array_map('intval', (array)($variants['widths'] ?? [])), fn($w) => $w > 0));
        if ($widths) {
            sort($widths);
            $pick = $widths[0];
            foreach ($widths as $w) {
                if ($w <= $want) $pick = $w;
            }
            $want = $pick;
        }
        $u = $base . '-' . $want . '.' . ($ext === 'webp' ? 'webp' : 'jpg');
    }
    return $absolute ? url($u) : $u;
}

/**
 * <picture> over the media table (spec 8.6): WebP srcset plus JPEG fallback,
 * width and height from the stored dimensions, lazy by default.
 * Refuses (returns '') an image without alt text unless $opts['decorative'].
 * $opts: sizes, eager (the LCP image only), decorative, class, img_class, widths.
 */
function picture(int|array|null $media, ?string $alt = null, array $opts = []): string
{
    $m = is_array($media) ? $media : ($media ? mediaRow((int)$media) : null);
    if (!$m || ($m['kind'] ?? 'image') !== 'image' || empty($m['path'])) return '';

    $decorative = !empty($opts['decorative']);
    $alt = $decorative ? '' : trim((string)($alt ?? ($m['alt'] ?? '')));
    if (!$decorative && $alt === '') {
        logLine('view', 'picture() refused media #' . ($m['id'] ?? '?') . ': no alt text');
        return '';
    }

    $variants = json_decode((string)($m['variants_json'] ?? ''), true);
    $widths = array_map('intval', (array)($variants['widths'] ?? [480, 800, 1200, 1600]));
    if (!empty($opts['widths'])) {
        $widths = array_values(array_intersect($widths, array_map('intval', $opts['widths']))) ?: $widths;
    }
    $widths = array_values(array_filter($widths, fn($w) => $w > 0));
    sort($widths);
    if (!$widths) return '';

    $base = '/' . ltrim((string)$m['path'], '/');
    $max = (int)end($widths);
    $w0 = (int)($m['width'] ?? 0);
    $h0 = (int)($m['height'] ?? 0);
    $w = ($w0 > 0 && $w0 < $max) ? $w0 : $max;
    $h = ($w0 > 0 && $h0 > 0) ? (int)round($w * $h0 / $w0) : null;
    $sizes = (string)($opts['sizes'] ?? '100vw');
    $srcset = static fn(string $ext): string => implode(', ', array_map(
        static fn(int $x): string => $base . '-' . $x . '.' . $ext . ' ' . $x . 'w',
        $widths
    ));
    $fallback = in_array(1200, $widths, true) ? 1200 : $max;
    $eager = !empty($opts['eager']);

    return '<picture' . attrs(['class' => $opts['class'] ?? null]) . '>'
        . '<source type="image/webp"' . attrs(['srcset' => $srcset('webp'), 'sizes' => $sizes]) . '>'
        . '<img' . attrs([
            'src'           => $base . '-' . $fallback . '.jpg',
            'srcset'        => $srcset('jpg'),
            'sizes'         => $sizes,
            'alt'           => $alt,
            'role'          => $decorative ? 'presentation' : null,
            'width'         => $w,
            'height'        => $h,
            'loading'       => $eager ? 'eager' : 'lazy',
            'decoding'      => 'async',
            'fetchpriority' => $eager ? 'high' : null,
            'class'         => $opts['img_class'] ?? null,
        ]) . '>'
        . '</picture>';
}

// ---------------------------------------------------------------------------
// Requests and responses
// ---------------------------------------------------------------------------

/** The visitor's IP. REMOTE_ADDR only; X-Forwarded-For is never trusted (spec 2.5). */
function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** HMAC of the IP with app_secret, for everything except CASL consent evidence. */
function ipHash(?string $ip = null): string
{
    return hash_hmac('sha256', $ip ?? clientIp(), (string)cfg('app_secret'));
}

/**
 * Redirect and stop. Paths stay relative unless $absolute (then built from
 * base_url, which public redirects and form 303s must use).
 */
function redirect(string $to, int $code = 302, bool $absolute = false): never
{
    if ($absolute && str_starts_with($to, '/') && !str_starts_with($to, '//')) $to = url($to);
    if (preg_match('/[\r\n]/', $to)) $to = '/';
    header('Location: ' . $to, true, $code);
    exit;
}

/** JSON response and stop. Never cached. */
function respondJson(mixed $data, int $code = 200, array $headers = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-LiteSpeed-Cache-Control: no-cache');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Admin session CSRF token (starts no session by itself on public pages). */
function csrfToken(): string
{
    return Csrf::token();
}

function csrfCheck(?string $token): bool
{
    return Csrf::check($token);
}

function csrfField(): string
{
    return Csrf::field();
}

/** Append one line to storage/logs/{channel}.log, emails and tokens redacted. */
function logLine(string $channel, string $message): void
{
    Log::write($channel, $message);
}
