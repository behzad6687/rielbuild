<?php
/**
 * CSRF (spec 7.3).
 *
 * Admin: a session token in every form (_token) and in the X-CSRF-Token
 * header of JSON calls, plus a same-origin Origin check. The admin session
 * must already be started by Auth::start(); this class never starts one, so
 * a public page can never set a cookie through it by accident.
 *
 * Public forms: stateless signed tokens, no session, no cookie (spec 5.1):
 * _t = base64url(ts . '.' . form_type . '.' . hmac_sha256(ts|form_type, app_secret)).
 */
defined('ICM') or exit;

final class Csrf
{
    public const MIN_AGE_DEFAULT = 3;      // seconds; newsletter and unsubscribe use 1
    public const MAX_AGE = 43200;          // 12 hours

    // ---------------- admin (session) ----------------

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('Csrf::token() needs the admin session (Auth::start() first).');
        }
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function check(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $real = $_SESSION['_csrf'] ?? '';
        return is_string($token) && is_string($real) && $real !== '' && hash_equals($real, $token);
    }

    /** <input type="hidden" name="_token" ...> for admin forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    /** Admin POST or JSON request: a valid _token or X-CSRF-Token, and a same-origin request. */
    public static function verifyRequest(): bool
    {
        $t = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return self::check(is_string($t) ? $t : null) && self::sameOrigin();
    }

    /**
     * Origin (or, without it, Referer) must name this host. Both absent passes
     * (older clients); the literal "null" origin fails.
     */
    public static function sameOrigin(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $base = strtolower((string)parse_url((string)cfg('base_url'), PHP_URL_HOST));
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $src = $origin !== '' ? $origin : (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($src === '') return true;
        if ($src === 'null') return false;
        $h = strtolower((string)parse_url($src, PHP_URL_HOST));
        $p = parse_url($src, PHP_URL_PORT);
        $hp = $h . ($p ? ':' . $p : '');
        return $h !== '' && ($hp === $host || $h === $host || $h === $base);
    }

    // ---------------- public forms (stateless) ----------------

    /** A fresh signed token for a public form type. */
    public static function publicToken(string $formType, ?int $ts = null): string
    {
        self::formType($formType);
        $ts ??= Time::ts();
        $sig = hash_hmac('sha256', $ts . '|' . $formType, self::secret());
        return rtrim(strtr(base64_encode($ts . '.' . $formType . '.' . $sig), '+/', '-_'), '=');
    }

    /**
     * Check a public token. Returns:
     *   'ok'      valid and within the age window;
     *   'young'   signature valid but younger than $minAge (re-render with values, 422);
     *   'expired' signature valid but older than $maxAge (re-render with values, 422);
     *   'forged'  missing, malformed, wrong form type or bad signature (400).
     */
    public static function checkPublic(?string $token, string $formType, int $minAge = self::MIN_AGE_DEFAULT, int $maxAge = self::MAX_AGE): string
    {
        if (!is_string($token) || $token === '' || strlen($token) > 400) return 'forged';
        $raw = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4), true);
        if ($raw === false) return 'forged';
        $parts = explode('.', $raw);
        if (count($parts) !== 3) return 'forged';
        [$ts, $type, $sig] = $parts;
        if (!ctype_digit($ts) || $type !== $formType) return 'forged';
        $want = hash_hmac('sha256', $ts . '|' . $type, self::secret());
        if (!hash_equals($want, $sig)) return 'forged';
        $age = Time::ts() - (int)$ts;
        if ($age < $minAge) return 'young';
        if ($age > $maxAge) return 'expired';
        return 'ok';
    }

    private static function secret(): string
    {
        $s = (string)cfg('app_secret');
        if ($s === '') throw new RuntimeException('app_secret is not set');
        return $s;
    }

    private static function formType(string $t): void
    {
        if (!preg_match('/^[a-z_]{1,32}$/', $t)) throw new InvalidArgumentException('Bad form type');
    }
}
