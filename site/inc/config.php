<?php
/* RIELBUILD site settings. PHP 7.4+ compatible. */

/* Contact details */
define('SITE_NAME', 'RIELBUILD');
define('SITE_LEGAL', 'RIELBUILD Construction & Renovation');
define('PHONE', '647-895-4555');
define('PHONE_TEL', '+16478954555');
define('EMAIL', 'contact@rielbuild.ca');
define('REGION', 'Greater Toronto Area');

/* Form handling.
   'demo' : validates and shows the thank-you, sends nothing.
   'mail' : sends every submission to FORM_TO with PHP mail(). */
define('FORM_MODE', 'demo');
define('FORM_TO', 'contact@rielbuild.ca');
define('FORM_FROM', 'no-reply@rielbuild.ca');

/* Search engines. Keep true while this lives as a demo so it never competes
   with the real rielbuild.ca in Google. Set false on the live domain. */
define('NOINDEX', true);

/* Public address of the site, used for canonical, Open Graph and sitemap.
   Leave empty to detect it from the request automatically. */
define('SITE_URL_OVERRIDE', '');

/* Asset version for cache busting. Bump after changing CSS or JS. */
define('ASSET_V', '1.0.0');

/* ---- base path detection (works in any folder, e.g. /demo/rielbuild/) ---- */
function rb_base_path()
{
    static $base = null;
    if ($base !== null) return $base;
    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $doc  = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $doc  = rtrim($doc, '/');
    if ($doc !== '' && strpos($root, $doc) === 0) {
        $base = substr($root, strlen($doc));
    } else {
        /* fallback: derive from the script URL */
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/';
        $rel    = str_replace('\\', '/', substr((string) realpath($_SERVER['SCRIPT_FILENAME']), strlen($root)));
        $base   = ($rel && substr($script, -strlen($rel)) === $rel) ? substr($script, 0, -strlen($rel)) : '';
    }
    $base = '/' . trim($base, '/');
    if ($base !== '/') $base .= '/';
    return $base;
}

function rb_origin()
{
    if (SITE_URL_OVERRIDE !== '') return rtrim(SITE_URL_OVERRIDE, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

/* url('services/') -> /demo/rielbuild/services/ */
function url($path = '')
{
    return rb_base_path() . ltrim($path, '/');
}

/* absolute URL for canonical / OG */
function abs_url($path = '')
{
    return rb_origin() . url($path);
}

/* asset URL with cache busting */
function asset($path)
{
    return url('assets/' . ltrim($path, '/')) . '?v=' . ASSET_V;
}

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
