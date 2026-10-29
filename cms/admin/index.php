<?php
/**
 * Admin front controller (spec 4.1, adapted from NHF admin/index.php).
 *
 * NHF's single switch becomes a module map: every app/admin/modules/{name}.php
 * returns ['nav' => ..., 'routes' => [...]] (contract 11), so workstreams add
 * screens without touching this file. Before a handler runs this controller has:
 *   - resolved the session (Auth::user(), the remember cookie on GET);
 *   - sent unauthenticated API calls a JSON 401 (never an HTML redirect);
 *   - forced a password change or the two-step setup when due;
 *   - checked the route's permission (403) and sudo mode for owner tools;
 *   - for every POST and API write, checked CONTENT_LENGTH against post_max_size
 *     and the CSRF token plus a same-origin request.
 * Handlers end with admin_render(), admin_json(), admin_redirect() or admin_csv().
 *
 * Also here: sign-in and sign-out, and the draft preview at
 * /admin/preview/{page|post}/{id} (signed links work without an account).
 */
define('ICM', 1);
require dirname(__DIR__) . '/app/bootstrap.php';

@set_time_limit(120);

$GLOBALS['ICM_ADMIN'] = ['route' => '', 'method' => 'GET', 'is_api' => false, 'routes' => [], 'nav' => [], 'loaded' => false];

/** Nav groups in sidebar order (spec 4.1.1). */
const ADMIN_GROUPS = ['Dashboard', 'Inbox', 'Content', 'Site'];

// =============================================================================
// Helpers for modules (contract section 11, plus the W7 additions)
// =============================================================================

/** '/admin/' . route . '?' . query. The dashboard is admin_url(''). */
function admin_url(string $route, array $query = []): string
{
    $route = trim($route, '/');
    $q = array_filter($query, fn($v) => $v !== null && $v !== '');
    return '/admin/' . $route . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

/** A versioned admin asset URL: admin_asset('admin.js'). */
function admin_asset(string $file): string
{
    $file = ltrim($file, '/');
    $abs = ROOT_DIR . '/admin/assets/' . $file;
    return '/admin/assets/' . $file . '?v=' . (is_file($abs) ? substr((string)filemtime($abs), -6) : '1');
}

/**
 * A module's own stylesheet (round 2, V2.0): admin_style('menus.css') in a handler before admin_render(),
 * or in the module's view. It links admin/assets/{file} with its ?v= stamp in <head>, through the view's
 * head() list that admin/layout.php prints, so modules never edit admin/assets/admin.css.
 */
function admin_style(string $file): void
{
    $file = ltrim($file, '/');
    if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*\.css$#', $file)) return;
    $GLOBALS['ICM_ADMIN']['styles'][$file] = true;
    $view = $GLOBALS['ICM_ADMIN']['view'] ?? null;
    if ($view instanceof View) $view->head('<link rel="stylesheet" href="' . e(admin_asset($file)) . '">');
}

/**
 * Render app/views/admin/{template}.php inside app/views/admin/layout.php.
 * Templates receive $view, $me, $flash, $csrf, $route plus $data.
 * $data['bare'] = true drops the navigation (sign-in screens).
 */
function admin_render(string $template, array $data = []): never
{
    $A = $GLOBALS['ICM_ADMIN'];
    $view = new View();
    $view->admin();
    $view->layout('admin/layout');
    // admin_style() calls made before this render, then the ones the template makes while it renders
    foreach (array_keys($A['styles'] ?? []) as $css) $view->head('<link rel="stylesheet" href="' . e(admin_asset((string)$css)) . '">');
    $GLOBALS['ICM_ADMIN']['view'] = $view;
    $active = session_status() === PHP_SESSION_ACTIVE;
    $vars = $data + [
        'me'    => $active ? Auth::publicUser() : null,
        'flash' => $active ? admin_take_flash() : [],
        'csrf'  => $active ? Csrf::token() : '',
        'route' => (string)$A['route'],
        'title' => '',
        'bare'  => false,
    ];
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();
    try {
        $view->render('admin/' . $template, $vars);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $html = (string)ob_get_clean();
    header('Cache-Control: no-store');          // View::render sends no-cache; the admin is never stored
    echo $html;
    exit;
}

/** JSON response with the admin headers. */
function admin_json(mixed $data, int $code = 200): never
{
    while (ob_get_level() > 0) ob_end_clean();
    Headers::admin();
    respondJson($data, $code);
}

/**
 * Redirect (303 after a POST, 302 otherwise). $to is relative to /admin/
 * ('courses/edit?id=4', '' for the dashboard) or a root path ('/admin/...', '/').
 */
function admin_redirect(string $to, ?string $flash = null, string $kind = 'ok'): never
{
    if ($flash !== null && $flash !== '') admin_flash($flash, $kind);
    if (preg_match('#^https?://#i', $to) || str_starts_with($to, '//')) $to = '/admin/';
    $url = str_starts_with($to, '/') ? $to : '/admin/' . ltrim($to, '/');
    while (ob_get_level() > 0) ob_end_clean();
    Headers::admin();
    redirect($url, $GLOBALS['ICM_ADMIN']['method'] === 'GET' ? 302 : 303);
}

/** A one-shot message for the next page. $kind: ok | bad. */
function admin_flash(string $message, string $kind = 'ok'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['_flash'][] = ['msg' => $message, 'kind' => $kind === 'bad' ? 'bad' : 'ok'];
}

/** @return list<array{msg:string,kind:string}> */
function admin_take_flash(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($f) ? array_values($f) : [];
}

function admin_can(string $perm): bool
{
    return Auth::can($perm);
}

/** One audit_log entry for the signed-in user. Never pass secrets. */
function admin_audit(string $action, string $entity, ?string $entityId, string $summary, array $detail = []): void
{
    Audit::log(Auth::id(), $action, $entity, $entityId, $summary, $detail);
}

/** Include app/views/admin/partials/{name}.php with $vars (image field, SEO panel, pager, field). */
function admin_partial(string $name, array $vars = []): void
{
    if (!preg_match('#^[a-z0-9_/-]+$#', $name)) return;
    $file = APP_DIR . '/views/admin/partials/' . $name . '.php';
    if (!is_file($file)) return;
    (static function (string $__file, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        include $__file;
    })($file, $vars);
}

/**
 * Optimistic locking (spec 4.1.3): compare the row's updated_at with the one the
 * form carried. Returns the refusal message, or null when the save may go ahead.
 */
function admin_stale(?string $stored, ?string $posted): ?string
{
    $stored = (string)$stored;
    if ($stored === '' || $stored === (string)$posted) return null;
    $d = Time::toLocal($stored);
    $when = $d ? $d->format('g:i a') : 'a moment ago';
    if ($d && $d->format('Y-m-d') !== Time::today()) $when .= ' on ' . $d->format('F j');
    return 'Someone else saved this at ' . $when . '. Reload to see their change.';
}

/** A CSV cell with the formula-injection guard (spec 4.2.9). */
function admin_csv_cell(mixed $v): string
{
    $s = is_bool($v) ? ($v ? '1' : '0') : (string)($v ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) $s = "'" . $s;
    return $s;
}

/** Stream a CSV download: UTF-8 with a BOM (Excel), every cell guarded. */
function admin_csv(string $filename, array $header, iterable $rows): never
{
    $filename = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export.csv';
    while (ob_get_level() > 0) ob_end_clean();
    Headers::admin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('admin_csv_cell', $header), ',', '"', '');
    foreach ($rows as $r) fputcsv($out, array_map('admin_csv_cell', array_values((array)$r)), ',', '"', '');
    fclose($out);
    exit;
}

/**
 * The draft preview address (spec 4.1.3). $signed gives an absolute link that works
 * without an account for $days (at most 7), for ICM reviewers.
 */
function admin_preview_url(string $kind, int $id, bool $signed = false, int $days = 7): string
{
    $kind = $kind === 'post' ? 'post' : 'page';
    $path = '/admin/preview/' . $kind . '/' . $id;
    if (!$signed) return $path;
    $exp = Time::ts() + max(1, min(7, $days)) * 86400;
    return url($path . '?exp=' . $exp . '&sig=' . admin_preview_sig($kind, $id, $exp));
}

function admin_preview_sig(string $kind, int $id, int $exp): string
{
    return hash_hmac('sha256', 'preview|' . $kind . '|' . $id . '|' . $exp, (string)cfg('app_secret'));
}

/** A safe "back" address: the same-origin admin Referer, else $fallback (or this route). */
function admin_back(string $fallback = ''): string
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && Csrf::sameOrigin()) {
        $p = (string)parse_url($ref, PHP_URL_PATH);
        $q = (string)parse_url($ref, PHP_URL_QUERY);
        if (preg_match('#^/admin(/|$)#', $p) && !preg_match('#^/admin/(login|logout)#', $p)) return $p . ($q !== '' ? '?' . $q : '');
    }
    return $fallback !== '' ? $fallback : admin_url((string)$GLOBALS['ICM_ADMIN']['route']);
}

/** An error page (or JSON for API routes). */
function admin_error(int $code, string $message = ''): never
{
    $A = $GLOBALS['ICM_ADMIN'];
    if (!empty($A['is_api'])) {
        $key = [401 => 'auth', 403 => 'forbidden', 404 => 'not_found', 405 => 'method', 413 => 'too_large', 500 => 'server'][$code] ?? 'error';
        admin_json(['error' => $key, 'message' => $message], $code);
    }
    $titles = [403 => 'Not available for your role', 404 => 'Page not found', 405 => 'That action is not allowed here', 500 => 'Something went wrong'];
    http_response_code($code);
    try {
        admin_render('errors', ['code' => $code, 'title' => $titles[$code] ?? 'Error', 'message' => $message, 'bare' => Auth::id() === null]);
    } catch (Throwable $e) {
        Log::exception($e, 'admin error page');
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/plain; charset=utf-8');
        echo ($titles[$code] ?? 'Error') . "\n";
        exit;
    }
}

/**
 * The navigation the current user may see, grouped in sidebar order:
 * [group => [['label','path','perm','badge','sort','module'], ...]].
 */
function admin_nav(): array
{
    $out = [];
    foreach ($GLOBALS['ICM_ADMIN']['nav'] as $n) {
        if (!Auth::can((string)$n['perm'])) continue;
        $out[(string)$n['group']][] = $n;
    }
    foreach ($out as &$items) {
        usort($items, fn($a, $b) => [(int)$a['sort'], (string)$a['label']] <=> [(int)$b['sort'], (string)$b['label']]);
    }
    unset($items);
    $ordered = [];
    foreach (ADMIN_GROUPS as $g) {
        if (isset($out[$g])) $ordered[$g] = $out[$g];
    }
    foreach ($out as $g => $items) {
        if (!isset($ordered[$g])) $ordered[$g] = $items;
    }
    return $ordered;
}

/** A nav item's badge count (0 when it has none or it fails). */
function admin_badge(array $item): int
{
    if (!isset($item['badge']) || !is_callable($item['badge'])) return 0;
    try {
        return max(0, (int)($item['badge'])());
    } catch (Throwable $e) {
        Log::exception($e, 'admin badge ' . ($item['path'] ?? '?'));
        return 0;
    }
}

/** Does a route exist (modules loaded)? */
function admin_has_route(string $route): bool
{
    return isset($GLOBALS['ICM_ADMIN']['routes'][trim($route, '/')]);
}

// =============================================================================
// Internals
// =============================================================================

function admin_load_modules(): void
{
    $A = &$GLOBALS['ICM_ADMIN'];
    if ($A['loaded']) return;
    $A['loaded'] = true;
    foreach (glob(APP_DIR . '/admin/modules/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) continue;
        try {
            $mod = (static fn(string $__f): mixed => require $__f)($file);
        } catch (Throwable $e) {
            Log::exception($e, 'admin module ' . $name);
            continue;
        }
        if (!is_array($mod)) continue;
        $navs = $mod['nav'] ?? [];
        if ($navs && !array_is_list($navs)) $navs = [$navs];
        $defaultPerm = (string)($navs[0]['perm'] ?? 'dashboard.view');
        foreach ($navs as $n) {
            if (!is_array($n) || empty($n['label']) || !array_key_exists('path', $n)) continue;
            $A['nav'][] = $n + ['group' => 'Content', 'perm' => $defaultPerm, 'sort' => 50, 'module' => $name];
        }
        foreach ((array)($mod['routes'] ?? []) as $key => $r) {
            $key = strtolower(trim((string)$key, '/'));
            if (isset($A['routes'][$key])) {
                Log::write('admin', 'duplicate admin route "' . $key . '" in module ' . $name . ' (kept the first)');
                continue;
            }
            if (!(is_array($r) && isset($r['handler']))) $r = ['handler' => $r];
            if (!is_callable($r['handler'])) continue;
            $A['routes'][$key] = [
                'handler' => $r['handler'],
                'perm'    => (string)($r['perm'] ?? $defaultPerm),
                'methods' => array_map('strtoupper', (array)($r['methods'] ?? ['GET', 'POST'])),
                'module'  => $name,
            ];
        }
    }
}

/** Refuse a body larger than post_max_size before CSRF (spec 2.5). */
function admin_check_size(): void
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = Img::toBytes((string)ini_get('post_max_size'));
    if ($max <= 0 || $len <= $max) return;
    $msg = 'That file is too large (limit ' . Media::uploadLimitMb() . ' MB).';
    if (headers_sent()) {
        // PHP already printed its own startup warning (display_errors on), so no redirect is possible.
        echo '<!doctype html><html lang="en-CA"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Too large | Admin</title></head>'
            . '<body><main><h1>Too large</h1><p>' . e($msg) . '</p><p><a href="' . e(admin_back()) . '">Go back</a></p></main></body></html>';
        exit;
    }
    if (!empty($GLOBALS['ICM_ADMIN']['is_api'])) admin_json(['error' => 'too_large', 'message' => $msg], 413);
    admin_redirect(admin_back(), $msg, 'bad');
}

function admin_csrf_fail(): never
{
    if (!empty($GLOBALS['ICM_ADMIN']['is_api'])) admin_json(['error' => 'csrf', 'token' => Csrf::token()], 419);
    admin_redirect(admin_back(), 'Your session expired. Please try that again.', 'bad');
}

/** Where to go after signing in: the page asked for, when it was an admin page. */
function admin_after_login(?string $force = null): string
{
    $to = (string)($_SESSION['after_login'] ?? '');
    unset($_SESSION['after_login']);
    if ($force !== null) return $force;
    if ($to !== '' && preg_match('#^/admin(/[a-z0-9/_.-]*)?(\?[^\s<>"]*)?$#i', $to) && !preg_match('#^/admin/(login|logout|api/)#', $to)) return $to;
    return '';
}

function admin_need_login(): never
{
    $A = $GLOBALS['ICM_ADMIN'];
    if ($A['is_api']) admin_json(['error' => 'auth', 'login' => '/admin/login'], 401);
    if ($A['method'] === 'GET') {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/');
        if (strlen($uri) < 500) $_SESSION['after_login'] = $uri;
        admin_redirect('login');
    }
    admin_redirect('login', 'Your session expired. Please sign in again.', 'bad');
}

function admin_login(string $method): never
{
    if (Auth::user()) admin_redirect(admin_after_login());
    $error = '';
    $email = '';
    if ($method === 'POST') {
        admin_check_size();
        $email = Sanitizer::text($_POST['email'] ?? '', false, 191);
        if (!Csrf::verifyRequest()) {
            $error = 'Your session expired. Please try that again.';
        } else {
            $r = Auth::attempt($email, (string)($_POST['password'] ?? ''), !empty($_POST['remember']));
            if (!$r['ok']) {
                $error = (string)$r['error'];
            } elseif ($r['next'] === '2fa') {
                admin_redirect('login/2fa');
            } else {
                admin_redirect(admin_after_login($r['next'] === 'enrol' ? 'account/2fa' : null));
            }
        }
        http_response_code(422);
    }
    admin_render('login', ['bare' => true, 'error' => $error, 'email' => $email, 'signedOut' => isset($_GET['out'])]);
}

function admin_login_2fa(string $method): never
{
    $u = Auth::pendingUser();
    if (!$u) admin_redirect('login', 'Please sign in again.', 'bad');
    $error = '';
    if ($method === 'POST') {
        if (!Csrf::verifyRequest()) {
            $error = 'Your session expired. Please try that again.';
        } else {
            $code = trim((string)($_POST['recovery'] ?? '')) !== '' ? (string)$_POST['recovery'] : (string)($_POST['code'] ?? '');
            $r = Auth::verifyPending($code);
            if ($r['ok']) admin_redirect(admin_after_login(Auth::mustEnrol() ? 'account/2fa' : null));
            if (!empty($r['restart'])) admin_redirect('login', (string)$r['error'], 'bad');
            $error = (string)$r['error'];
        }
        http_response_code(422);
    }
    admin_render('login-2fa', ['bare' => true, 'error' => $error, 'name' => (string)$u['name']]);
}

function admin_logout(string $method): never
{
    if ($method === 'POST') {
        if (!Csrf::verifyRequest()) admin_redirect('', 'Your session expired. Please try that again.', 'bad');
        Auth::logout();
        while (ob_get_level() > 0) ob_end_clean();
        Headers::admin();
        redirect('/admin/login?out=1', 303);
    }
    if (!Auth::user()) admin_redirect('login');
    admin_render('logout', ['bare' => true, 'title' => 'Sign out']);
}

/** Render a page or post draft with the public template (spec 4.1.3). */
function admin_preview(string $kind, int $id): never
{
    $row = $id > 0 ? Db::one('SELECT * FROM ' . ($kind === 'post' ? 'posts' : 'pages') . ' WHERE id = ?', [$id]) : null;
    if (!$row) admin_error(404, 'That draft no longer exists.');
    $v = new View();
    $v->preview(true);
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();
    try {
        if ($kind === 'post') {
            View::setRequestPath('/blog/' . $row['slug'] . '/');
            $v->render('pages/post', ['post' => $row, 'route' => 'post', 'params' => [(string)$row['slug']]]);
        } else {
            $tpl = (string)$row['template'];
            if (!preg_match('/^[a-z0-9_]+$/', $tpl) || !is_file(APP_DIR . '/views/pages/' . $tpl . '.php')) {
                throw new RuntimeException('Unknown template ' . $tpl);
            }
            View::setRequestPath((string)$row['path']);
            $v->render('pages/' . $tpl, ['page' => $row, 'route' => $tpl]);
        }
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $html = (string)ob_get_clean();
    header('Cache-Control: no-store');
    echo $html;
    exit;
}

function admin_setup_page(): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="en-CA"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Admin | Set up</title></head><body>'
        . '<main><h1>The site is not set up yet</h1><p>Open <a href="/install/">the installer</a> to set it up.</p></main></body></html>';
    exit;
}

// =============================================================================
// The request
// =============================================================================

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'HEAD') $method = 'GET';
$uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?? '/admin');
$route = strtolower(trim((string)preg_replace('#^/admin#i', '', $uriPath), '/'));
$GLOBALS['ICM_ADMIN']['route'] = $route;
$GLOBALS['ICM_ADMIN']['method'] = $method;
$GLOBALS['ICM_ADMIN']['is_api'] = str_starts_with($route, 'api/');

if (!preg_match('#^[a-z0-9/_-]*$#', $route)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}
if (!is_file(APP_DIR . '/config.local.php')) admin_setup_page();

try {
    // Draft previews: /admin/preview/page/12 (or ?id=12). A valid signature needs no account.
    $previewKind = null;
    $previewId = 0;
    if (preg_match('#^preview/(page|post)(?:/(\d+))?$#', $route, $pm)) {
        $previewKind = $pm[1];
        $previewId = (int)($pm[2] ?? ($_GET['id'] ?? 0));
        $GLOBALS['ICM_ADMIN']['route'] = $route = 'preview/' . $previewKind;
        if (isset($_GET['sig'])) {
            $exp = (string)($_GET['exp'] ?? '');
            $sig = (string)$_GET['sig'];
            $valid = ctype_digit($exp) && (int)$exp >= Time::ts() && (int)$exp <= Time::ts() + 8 * 86400
                && hash_equals(admin_preview_sig($previewKind, $previewId, (int)$exp), $sig);
            if ($valid) admin_preview($previewKind, $previewId);
            admin_error(404, 'This preview link is not valid or has expired. Ask the site owner for a new one.');
        }
    }

    Auth::start();

    if ($route === 'login') admin_login($method);
    if ($route === 'login/2fa') admin_login_2fa($method);
    if ($route === 'logout') admin_logout($method);

    $me = Auth::user();
    if (!$me) admin_need_login();

    // Steps that must happen before anything else.
    if ((int)$me['must_change_password'] === 1 && !in_array($route, ['account/password', 'logout'], true)) {
        if ($GLOBALS['ICM_ADMIN']['is_api']) admin_json(['error' => 'password'], 403);
        admin_redirect('account/password', 'Choose a new password before you continue.', 'bad');
    }
    if (Auth::mustEnrol($me) && !in_array($route, ['account/2fa', 'account/password', 'logout'], true)) {
        if ($GLOBALS['ICM_ADMIN']['is_api']) admin_json(['error' => '2fa'], 403);
        admin_redirect('account/2fa', 'Set up two-step sign-in before you continue. It is required for your role.', 'bad');
    }

    admin_load_modules();

    if ($previewKind !== null) {
        if (!Auth::can($previewKind === 'post' ? 'posts.edit' : 'pages.edit')) admin_error(403);
        admin_preview($previewKind, $previewId);
    }

    $def = $GLOBALS['ICM_ADMIN']['routes'][$route] ?? null;
    if (!$def) admin_error(404);
    if (!in_array($method, $def['methods'], true)) {
        header('Allow: ' . implode(', ', $def['methods']));
        admin_error(405);
    }
    if (!Auth::can($def['perm'])) admin_error(403);
    if (Auth::needsSudo($def['perm'])) {
        if ($GLOBALS['ICM_ADMIN']['is_api']) admin_json(['error' => 'sudo', 'login' => '/admin/account/sudo'], 403);
        $next = $method === 'GET' ? (string)($_SERVER['REQUEST_URI'] ?? '/admin/') : admin_url($route);
        admin_redirect(admin_url('account/sudo', ['next' => $next]));
    }
    if ($method !== 'GET') {
        admin_check_size();
        if (!Csrf::verifyRequest()) admin_csrf_fail();
    }

    $json = null;
    if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $raw = (string)file_get_contents('php://input', false, null, 0, 2097152);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = null;
    }
    $req = [
        'method' => $method,
        'route'  => $route,
        'query'  => $_GET,
        'post'   => $_POST,
        'files'  => $_FILES,
        'json'   => $json,
        'user'   => Auth::publicUser(),
        'is_api' => $GLOBALS['ICM_ADMIN']['is_api'],
    ];

    $result = ($def['handler'])($req);
    if (is_array($result)) admin_json($result);
    Log::write('admin', 'handler for "' . $route . '" returned without a response');
    http_response_code(204);
    exit;
} catch (Throwable $e) {
    Log::exception($e, 'admin ' . $route);
    $detail = cfg('debug') ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '';
    admin_error(500, $detail);
}
