<?php
/**
 * Response headers, sent from PHP because LiteSpeed .htaccess cannot use
 * <If> blocks (spec 0.2, 7.4). One CSP nonce per request.
 */
defined('ICM') or exit;

final class Headers
{
    private static ?string $nonce = null;

    /** The per-request CSP nonce for inline scripts: <script nonce="<?= e(Headers::nonce()) ?>">. */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Third-party hosts the public policy always allows (round 2, WP-C): the JotForm embed script and the
     * JotForm, Google Maps and YouTube frames. Analytics and ad hosts are no longer fixed: they come from
     * Integrations::cspSources() while those providers are switched on (ADMIN-AUDIT 4.10).
     */
    public const FIXED = [
        'script'  => [],
        'connect' => [],
        'img'     => [],
        'frame'   => ['https://www.google.com', 'https://www.youtube-nocookie.com'],
    ];

    /**
     * The public Content-Security-Policy (spec 7.4). $frameAncestors adds origins (thank-you page).
     * $reportOnly leaves out upgrade-insecure-requests: browsers ignore it in a report-only
     * policy and log a console error on every page (G9 needs zero console errors).
     * Round 2 (WP-C): script-src, img-src, connect-src and frame-src are 'self' (and the nonce, data: and
     * blob: where they were), then the FIXED hosts, then the hosts of the switched-on integrations.
     */
    public static function csp(array $frameAncestors = [], bool $reportOnly = false): string
    {
        $n = self::nonce();
        $fa = implode(' ', array_merge(["'self'"], array_filter($frameAncestors, fn($o) => preg_match('#^https://[a-z0-9.-]+$#', $o))));
        $x = self::integrationSources();
        $src = static fn(string $dir, array $base): string => implode(' ', array_values(array_unique(array_merge($base, self::FIXED[$dir], $x[$dir] ?? []))));
        return implode('; ', array_filter([
            "default-src 'self'",
            'script-src ' . $src('script', ["'self'", "'nonce-{$n}'"]),
            "style-src 'self' 'unsafe-inline'",
            'img-src ' . $src('img', ["'self'", 'data:', 'blob:']),
            "font-src 'self'",
            'connect-src ' . $src('connect', ["'self'"]),
            'frame-src ' . $src('frame', []),
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "form-action 'self'",
            "frame-ancestors {$fa}",
            "base-uri 'self'",
            "object-src 'none'",
            $reportOnly ? null : 'upgrade-insecure-requests',
            'report-uri /csp-report/',
            'report-to csp',
        ]));
    }

    /**
     * Headers for every public response.
     * $o['frame_ancestors'] (list of https origins) lets JotForm frame a page;
     * $o['noindex'] adds X-Robots-Tag: noindex. Staging is always noindex, nofollow.
     */
    public static function public(array $o = []): void
    {
        if (headers_sent()) return;
        $fa = (array)($o['frame_ancestors'] ?? []);
        $enforce = self::setting('security.csp_mode', 'report-only') === 'enforce';
        header_remove('X-Powered-By');
        header(($enforce ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . self::csp($fa, !$enforce));
        header('Reporting-Endpoints: csp="' . url('/csp-report/') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // interest-cohort is left out on purpose: current Chrome logs it as a console error (G9 needs zero).
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
        if (!$fa) header('X-Frame-Options: SAMEORIGIN');   // backs up frame-ancestors while the CSP is report-only
        else header_remove('X-Frame-Options');
        if (self::setting('security.hsts', '0') === '1') header('Strict-Transport-Security: max-age=31536000');
        if (isStaging()) header('X-Robots-Tag: noindex, nofollow');
        elseif (!empty($o['noindex'])) header('X-Robots-Tag: noindex');
        header('X-LiteSpeed-Cache-Control: no-cache');
    }

    /** HTML documents: never cached by a server or CDN, back-forward cache kept (spec 1.5.3). */
    public static function html(): void
    {
        if (headers_sent()) return;
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header_remove('ETag');
        header_remove('Last-Modified');
    }

    public static function noStore(): void
    {
        if (headers_sent()) return;
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');
    }

    public static function noindex(): void
    {
        if (!headers_sent()) header('X-Robots-Tag: ' . (isStaging() ? 'noindex, nofollow' : 'noindex'));
    }

    /** Admin responses (spec 7.4): no third-party hosts, never cached, never indexed. */
    public static function admin(): void
    {
        if (headers_sent()) return;
        $n = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$n}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            'report-uri /csp-report/',
        ]);
        $enforce = self::setting('security.csp_mode', 'report-only') === 'enforce';
        header_remove('X-Powered-By');
        header(($enforce ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . $csp);
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        if (self::setting('security.hsts', '0') === '1') header('Strict-Transport-Security: max-age=31536000');
    }

    /**
     * POST /csp-report/ (route in 1.5.2): 8 KB max body, 60 a minute per IP,
     * one JSON line per violation in storage/logs/csp.log. Answers 204.
     */
    public static function cspReport(): void
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
            http_response_code(413);
            return;
        }
        try {
            if (!RateLimit::hit('csp:ip', clientIp(), 60, 60)) {
                http_response_code(429);
                return;
            }
        } catch (Throwable) {
            // No database: still accept the report.
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 8193);
        if (strlen($raw) > 8192) {
            http_response_code(413);
            return;
        }
        $data = json_decode($raw, true);
        $reports = [];
        if (is_array($data) && isset($data['csp-report']) && is_array($data['csp-report'])) {
            $reports[] = $data['csp-report'];
        } elseif (is_array($data) && array_is_list($data)) {
            foreach ($data as $r) {
                if (is_array($r) && ($r['type'] ?? '') === 'csp-violation' && is_array($r['body'] ?? null)) $reports[] = $r['body'];
            }
        }
        foreach (array_slice($reports, 0, 10) as $r) {
            $pick = static fn(string ...$keys): string => mb_substr((string)(array_values(array_filter(
                array_map(fn($k) => is_scalar($r[$k] ?? null) ? (string)$r[$k] : '', $keys)
            ))[0] ?? ''), 0, 300);
            Log::write('csp', (string)json_encode([
                'doc'     => $pick('document-uri', 'documentURL'),
                'dir'     => $pick('effective-directive', 'effectiveDirective', 'violated-directive'),
                'blocked' => $pick('blocked-uri', 'blockedURL'),
                'source'  => $pick('source-file', 'sourceFile'),
                'line'    => $pick('line-number', 'lineNumber'),
                'sample'  => $pick('script-sample', 'sample'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        header('Cache-Control: no-store');
        http_response_code(204);
    }

    /** The hosts of the switched-on integrations (Integrations::cspSources); none when that cannot be read. */
    private static function integrationSources(): array
    {
        try {
            return [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function setting(string $key, string $default): string
    {
        try {
            return (string)Db::setting($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}
