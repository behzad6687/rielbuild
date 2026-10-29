<?php
/**
 * Admin authentication (spec 4.1.2, 7.2), adapted from NHF.
 *
 * - Session `icm_admin`, cookie path /admin, Secure under HTTPS, HttpOnly, SameSite=Lax,
 *   saved in storage/sessions. New id at sign-in and when the role changes.
 *   8-hour idle timeout, 7-day absolute lifetime.
 * - Throttling is database backed (admin_login_attempts): 10 failures from one IP in
 *   15 minutes block that IP for 15 minutes; 5 failures on one account set locked_until.
 *   Every failure spends the password_verify time and shows the same message.
 * - Remember device: cookie icm_rd = selector:validator (30 days, path /admin), the
 *   database keeps sha256(validator), the validator rotates on use, and a mismatch
 *   deletes every token of that user. Honoured on GET only.
 * - Sudo: a remembered-device session must re-enter the password (and the code) before
 *   users.manage, settings.edit or system.run, for 15 minutes.
 * - Two-step codes (Totp) are required for the roles in security.require_2fa_roles.
 *   No email password reset exists by design: owners reset other users.
 */
defined('ICM') or exit;

final class Auth
{
    /** v3 (2026-10-06): 'master' sits above the owner (the agency): every permission, invisible to the others, and it
     *  decides which admin sections every other role may see (Admin > Access control, setting access.hidden). */
    public const ROLES = ['master' => 'Master admin', 'owner' => 'Owner', 'manager' => 'Manager', 'editor' => 'Editor'];
    public const MASTER = 'master';
    public const IDLE = 28800;             // 8 hours
    public const ABSOLUTE = 604800;        // 7 days
    public const REMEMBER_DAYS = 30;
    public const SUDO_SECONDS = 900;       // 15 minutes
    public const SUDO_PERMS = ['users.manage', 'settings.edit', 'system.run', 'integrations.edit', 'code.edit'];
    public const PENDING_SECONDS = 600;    // time allowed between password and code
    public const MSG_FAIL = 'That email and password did not match. After 5 tries sign-in pauses for 15 minutes.';
    public const MSG_IP_BLOCK = 'Too many tries from this connection. Please wait 15 minutes, then try again.';
    public const MSG_CODE_LOCK = 'Too many wrong codes. Sign-in is paused for 15 minutes.';

    private const COOKIE = 'icm_rd';
    private const GRACE = 120;             // seconds a just-rotated validator still works
    /** A real bcrypt hash of a random string: failures cost the same time as a real check. */
    private const DUMMY_HASH = '$2y$10$ECREyEEmGPd4/MqsURHOQuSq9ypGczVKw9cB0K25RFgA9M3E2XXdm';

    /** Permission => roles (spec 4.1.2). */
    public const MATRIX = [
        'dashboard.view'     => ['owner', 'manager', 'coach', 'editor'],
        'rankings.enter'     => ['owner', 'manager', 'coach'],
        'rankings.publish'   => ['owner', 'manager', 'coach'],
        'courses.edit'       => ['owner', 'manager'],
        'locations.edit'     => ['owner', 'manager'],
        'finder.edit'        => ['owner', 'manager'],
        'calendar.edit'      => ['owner', 'manager'],
        'inbox.view'         => ['owner', 'manager', 'coach'],
        'inbox.edit'         => ['owner', 'manager', 'coach'],
        'inbox.export'       => ['owner', 'manager', 'coach'],
        'posts.edit'         => ['owner', 'manager', 'editor'],
        'pages.edit'         => ['owner', 'manager', 'editor'],
        'media.edit'         => ['owner', 'manager', 'editor'],
        'faqs.edit'          => ['owner', 'manager', 'editor'],
        'jobs.edit'          => ['owner', 'manager', 'editor'],
        'staff.edit'         => ['owner', 'manager', 'editor'],
        'pathways.edit'      => ['owner', 'manager', 'editor'],
        'testimonials.edit'  => ['owner', 'manager', 'editor'],
        'certificates.edit'  => ['owner', 'manager', 'coach'],
        'subscribers.view'   => ['owner', 'manager'],
        'subscribers.edit'   => ['owner', 'manager'],
        'subscribers.import' => ['owner', 'manager'],
        'subscribers.export' => ['owner', 'manager'],
        'campaigns.edit'     => ['owner', 'manager'],
        'campaigns.send'     => ['owner', 'manager'],
        'redirects.edit'     => ['owner', 'manager', 'editor'],
        'users.manage'       => ['owner'],
        'settings.edit'      => ['owner'],
        'system.run'         => ['owner'],
        // Round 2 (V2-PLAN 3, step 0.4; ADMIN-AUDIT 4.13). integrations.edit and code.edit also need sudo.
        'menus.edit'         => ['owner', 'manager', 'editor'],
        'layout.edit'        => ['owner', 'manager'],
        'home.edit'          => ['owner', 'manager', 'editor'],
        'sliders.edit'       => ['owner', 'manager', 'editor'],
        'programs.edit'      => ['owner', 'manager'],
        'integrations.edit'  => ['owner'],
        'code.edit'          => ['owner'],
        'rankings.import'    => ['owner', 'manager'],
        // v3 step 3: registrations and payments (Enrol). Refunds move money, so they are their own permission.
        'registrations.view' => ['owner', 'manager'],
        'registrations.edit' => ['owner', 'manager'],
        'payments.refund'    => ['owner'],
        // v3 step 4: coaches take attendance on their phones, see the date-range report and post the monthly lesson updates.
        'attendance.take'    => ['owner', 'manager', 'coach'],
        'attendance.report'  => ['owner', 'manager', 'coach'],
        'lessons.edit'       => ['owner', 'manager', 'coach'],
        // v3 (2026-10-19): the shared task list between ICM and the agency
        'tasks.use'          => ['owner', 'manager'],
        // v3: the master's own screen
        'access.manage'      => ['master'],
    ];

    /** Coaches see only these inbox form types (spec 4.1.2: "ranking_signup only"). */
    public const INBOX_SCOPE = ['coach' => ['ranking_signup']];

    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?array $commonCache = null;

    // =================================================================
    // Session
    // =================================================================

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $dir = STORAGE_DIR . '/sessions';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        ini_set('session.gc_maxlifetime', (string)(self::IDLE + 3600));
        session_name('icm_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/admin',
            'secure'   => Router::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** The signed-in admin_users row (full), or null. Honours the remember cookie on GET. */
    public static function user(): ?array
    {
        if (self::$resolved) return self::$user;
        self::$resolved = true;
        self::start();

        $uid = (int)($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $now = Time::ts();
            $idle = $now - (int)($_SESSION['last_seen'] ?? 0);
            $age = $now - (int)($_SESSION['started_at'] ?? 0);
            if ($idle > self::IDLE || $age > self::ABSOLUTE) {
                self::endSession('You were signed out after a long time without activity. Please sign in again.');
            } else {
                $row = Db::one('SELECT * FROM admin_users WHERE id = ?', [$uid]);
                if (!$row || (int)$row['is_active'] !== 1 || (int)($row['session_epoch'] ?? 0) !== (int)($_SESSION['epoch'] ?? 0)) {
                    self::endSession('You were signed out. Please sign in again.');
                } else {
                    if ((string)$row['role'] !== (string)($_SESSION['role'] ?? '')) {
                        session_regenerate_id(true);
                        $_SESSION['role'] = (string)$row['role'];
                    }
                    $_SESSION['last_seen'] = $now;
                    return self::$user = $row;
                }
            }
        }

        if (self::isGet()) {
            $u = self::fromRemember();
            if ($u) return self::$user = $u;
        }
        return self::$user = null;
    }

    /** The user row safe to hand to templates and handlers (no hashes, no secrets). */
    public static function publicUser(?array $u = null): ?array
    {
        $u ??= self::user();
        if (!$u) return null;
        unset($u['pass_hash'], $u['totp_secret_enc']);
        return $u;
    }

    public static function id(): ?int
    {
        $u = self::$resolved ? self::$user : self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** How this session signed in: 'password' or 'remember'. */
    public static function via(): string
    {
        return (string)($_SESSION['via'] ?? 'password');
    }

    /** Forget the resolved user (tests, or after a change to the session). */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }

    // =================================================================
    // Roles
    // =================================================================

    public static function can(string $perm, ?array $user = null): bool
    {
        $user ??= self::user();
        if (!$user) return false;
        $roles = self::MATRIX[$perm] ?? null;
        if ($roles === null) {
            Log::write('auth', 'unknown permission ' . $perm);
            return false;
        }
        $role = (string)$user['role'];
        if ($role === self::MASTER) return true;
        if (in_array($perm, self::hiddenFor($role), true)) return false;
        return in_array($role, $roles, true);
    }

    /** Owner-level checks: the owner and the master. */
    public static function isOwner(?string $role): bool
    {
        return $role === 'owner' || $role === self::MASTER;
    }

    /** Permissions the master has hidden from a role (setting access.hidden: {role: [perm, ...]}). */
    public static function hiddenFor(string $role): array
    {
        static $map = null;
        if ($map === null) {
            $d = json_decode((string)(function_exists('site') ? site('access.hidden', '') : ''), true);
            $map = is_array($d) ? $d : [];
        }
        return array_values(array_filter((array)($map[$role] ?? []), 'is_string'));
    }

    /** Inbox form types this user may see, or null for all (spec 4.1.2). */
    public static function inboxScope(?array $user = null): ?array
    {
        $user ??= self::user();
        return $user ? (self::INBOX_SCOPE[(string)$user['role']] ?? null) : [];
    }

    /** Roles that must use two-step sign-in (setting security.require_2fa_roles, default owner,manager). */
    public static function twoFactorRoles(): array
    {
        // An empty value means "no role must use it"; only a missing key falls back to the default.
        $all = Db::settingsAll();
        $raw = array_key_exists('security.require_2fa_roles', $all) ? (string)$all['security.require_2fa_roles'] : 'master,owner,manager';
        return array_values(array_intersect(array_keys(self::ROLES), array_map('trim', explode(',', $raw))));
    }

    public static function requires2fa(string $role): bool
    {
        return in_array($role, self::twoFactorRoles(), true);
    }

    /** True when this user must enrol in two-step sign-in before doing anything else. */
    public static function mustEnrol(?array $user = null): bool
    {
        $user ??= self::user();
        return $user && (int)$user['totp_enabled'] !== 1 && self::requires2fa((string)$user['role']);
    }

    // =================================================================
    // Sign-in
    // =================================================================

    /**
     * Check an email and password. Returns
     *   ['ok' => false, 'error' => message]            on any failure (one message for all);
     *   ['ok' => true,  'next' => '2fa']               password right, code needed (session pending);
     *   ['ok' => true,  'next' => 'done' | 'enrol']    signed in ('enrol' when 2FA must be set up now).
     */
    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        self::start();
        $email = self::normEmail($email);
        $ipHash = ipHash();
        $emailHash = self::emailHash($email);
        $window = Time::utcAgo(self::lockMinutes() * 60);

        $ipFails = (int)Db::value(
            'SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash = ? AND success = 0 AND created_at >= ?',
            [$ipHash, $window],
            0
        );
        if ($ipFails >= self::ipMax()) {
            password_verify($password, self::DUMMY_HASH);
            return ['ok' => false, 'error' => self::MSG_IP_BLOCK];
        }

        $user = ($email !== '' && strlen($email) <= 191) ? Db::one('SELECT * FROM admin_users WHERE email = ?', [$email]) : null;
        $pwOk = password_verify($password, (string)($user['pass_hash'] ?? self::DUMMY_HASH));
        $locked = $user && !empty($user['locked_until']) && (string)$user['locked_until'] > Time::nowUtc();
        $ok = $user && $pwOk && (int)$user['is_active'] === 1 && !$locked;

        if (!$ok) {
            self::recordAttempt($emailHash, $ipHash, false);
            if ($user && !$locked) self::maybeLock($user, $emailHash);
            Audit::log(
                $user ? (int)$user['id'] : null,
                'login_fail',
                'admin_users',
                $user ? (string)$user['id'] : null,
                $user ? ('Sign-in failed' . ($locked ? ' while the account was paused' : '')) : ('Sign-in failed for ' . Log::maskEmail($email))
            );
            return ['ok' => false, 'error' => self::MSG_FAIL];
        }

        if (password_needs_rehash((string)$user['pass_hash'], PASSWORD_DEFAULT)) {
            Db::update('admin_users', ['pass_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int)$user['id']]);
        }

        if ((int)$user['totp_enabled'] === 1 && !self::rememberedFor((int)$user['id'])) {
            // The password alone is not a successful sign-in: nothing is recorded as a
            // success until the code is right, so wrong codes keep counting toward the
            // account lock across fresh password entries (spec 7.2).
            session_regenerate_id(true);
            $_SESSION['pending'] = ['uid' => (int)$user['id'], 'at' => Time::ts(), 'remember' => $remember, 'tries' => 0];
            return ['ok' => true, 'next' => '2fa'];
        }

        self::recordAttempt($emailHash, $ipHash, true);
        self::completeLogin($user, 'password', $remember);
        return ['ok' => true, 'next' => self::mustEnrol($user) ? 'enrol' : 'done'];
    }

    /** Is a password-checked sign-in waiting for its code? */
    public static function pendingUser(): ?array
    {
        self::start();
        $p = $_SESSION['pending'] ?? null;
        if (!is_array($p) || Time::ts() - (int)($p['at'] ?? 0) > self::PENDING_SECONDS) {
            unset($_SESSION['pending']);
            return null;
        }
        $u = Db::one('SELECT * FROM admin_users WHERE id = ? AND is_active = 1', [(int)$p['uid']]);
        return $u ?: null;
    }

    /**
     * Second step: a 6-digit code or a recovery code. Every wrong code counts toward
     * the account lock (5 failures pause sign-in for 15 minutes, spec 7.2), and a
     * paused account cannot use a pending code step.
     */
    public static function verifyPending(string $code): array
    {
        $user = self::pendingUser();
        if (!$user) return ['ok' => false, 'error' => 'That took too long. Please sign in again.', 'restart' => true];
        $emailHash = self::emailHash((string)$user['email']);
        if (self::isLocked($user)) {
            unset($_SESSION['pending']);
            return ['ok' => false, 'error' => self::MSG_CODE_LOCK, 'restart' => true];
        }
        $p = $_SESSION['pending'];
        $p['tries'] = (int)($p['tries'] ?? 0) + 1;
        $_SESSION['pending'] = $p;
        if ($p['tries'] > 5) {
            unset($_SESSION['pending']);
            self::recordAttempt($emailHash, ipHash(), false);
            self::maybeLock($user, $emailHash);
            return ['ok' => false, 'error' => 'Too many wrong codes. Please sign in again.', 'restart' => true];
        }
        if (!self::checkSecondFactor($user, $code)) {
            self::recordAttempt($emailHash, ipHash(), false);
            Audit::log((int)$user['id'], 'login_fail', 'admin_users', (string)$user['id'], 'Wrong two-step code');
            if (self::maybeLock($user, $emailHash)) {
                unset($_SESSION['pending']);
                return ['ok' => false, 'error' => self::MSG_CODE_LOCK, 'restart' => true];
            }
            return ['ok' => false, 'error' => 'That code did not work. Check the app and try the newest code.'];
        }
        unset($_SESSION['pending']);
        self::recordAttempt($emailHash, ipHash(), true);
        self::completeLogin($user, 'password', !empty($p['remember']));
        return ['ok' => true];
    }

    /** Is sign-in paused for this account right now? */
    public static function isLocked(array $user): bool
    {
        $until = (string)Db::value('SELECT locked_until FROM admin_users WHERE id = ?', [(int)$user['id']], '');
        return $until !== '' && $until > Time::nowUtc();
    }

    /** Check a code (or a recovery code, used up) for a user; records totp_last_step. */
    public static function checkSecondFactor(array $user, string $code): bool
    {
        $code = trim($code);
        if ($code === '') return false;
        if (Totp::looksLikeRecovery($code)) return self::useRecoveryCode((int)$user['id'], $code);
        $secret = Db::unseal((string)($user['totp_secret_enc'] ?? ''), 'totp');
        if (!$secret) return false;
        $last = $user['totp_last_step'] === null ? null : (int)$user['totp_last_step'];
        $step = Totp::verify($secret, $code, $last);
        if ($step === null) return false;
        Db::update('admin_users', ['totp_last_step' => $step], 'id = ?', [(int)$user['id']]);
        return true;
    }

    /** Mark the session signed in. */
    public static function completeLogin(array $user, string $via, bool $remember): void
    {
        self::start();
        session_regenerate_id(true);
        $keep = ['after_login' => $_SESSION['after_login'] ?? null, '_flash' => $_SESSION['_flash'] ?? null];
        $_SESSION = array_filter($keep, fn($v) => $v !== null);
        $now = Time::ts();
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['role'] = (string)$user['role'];
        $_SESSION['epoch'] = (int)($user['session_epoch'] ?? 0);
        $_SESSION['started_at'] = $now;
        $_SESSION['last_seen'] = $now;
        $_SESSION['via'] = $via;
        Db::update('admin_users', ['last_login_at' => Time::nowUtc(), 'locked_until' => null], 'id = ?', [(int)$user['id']]);
        Audit::log((int)$user['id'], 'login', 'admin_users', (string)$user['id'], $via === 'remember' ? 'Signed in (remembered device)' : 'Signed in');
        if ($remember) self::issueRemember((int)$user['id']);
        self::$user = Db::one('SELECT * FROM admin_users WHERE id = ?', [(int)$user['id']]);
        self::$resolved = true;
    }

    /** Sign out: forget this device's remember token and end the session. */
    public static function logout(): void
    {
        self::start();
        $uid = (int)($_SESSION['uid'] ?? 0);
        [$selector] = self::parseCookie();
        if ($selector !== null) Db::delete('admin_remember_tokens', 'selector = ?', [$selector]);
        self::clearRememberCookie();
        if ($uid) Audit::log($uid, 'logout', 'admin_users', (string)$uid, 'Signed out');
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => 1, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        self::reset();
    }

    // =================================================================
    // Sudo mode
    // =================================================================

    /** Does this permission need sudo right now? (remembered-device sessions only) */
    public static function needsSudo(string $perm): bool
    {
        if (!in_array($perm, self::SUDO_PERMS, true)) return false;
        if (self::via() !== 'remember') return false;
        return (int)($_SESSION['sudo_until'] ?? 0) < Time::ts();
    }

    /** Re-enter the password (and the code when two-step is on) for 15 minutes of sudo. */
    public static function confirmSudo(string $password, string $code = ''): ?string
    {
        $u = self::user();
        if (!$u) return 'Please sign in again.';
        $ipHash = ipHash();
        if ((int)Db::value('SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash = ? AND success = 0 AND created_at >= ?', [$ipHash, Time::utcAgo(self::lockMinutes() * 60)], 0) >= self::ipMax()) {
            return self::MSG_IP_BLOCK;
        }
        if (!password_verify($password, (string)$u['pass_hash'])) {
            self::recordAttempt(self::emailHash((string)$u['email']), $ipHash, false);
            self::maybeLock($u, self::emailHash((string)$u['email']));
            return 'That password did not match.';
        }
        if ((int)$u['totp_enabled'] === 1 && !self::checkSecondFactor($u, $code)) {
            self::recordAttempt(self::emailHash((string)$u['email']), $ipHash, false);
            self::maybeLock($u, self::emailHash((string)$u['email']));
            return 'That code did not work. Try the newest code in the app.';
        }
        $_SESSION['sudo_until'] = Time::ts() + self::SUDO_SECONDS;
        Audit::log((int)$u['id'], 'sudo', 'admin_users', (string)$u['id'], 'Confirmed the password for owner tools');
        return null;
    }

    // =================================================================
    // Remember device
    // =================================================================

    public static function issueRemember(int $uid): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = Time::ts() + self::REMEMBER_DAYS * 86400;
        Db::insert('admin_remember_tokens', [
            'user_id'        => $uid,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'user_agent'     => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            'expires_at'     => gmdate('Y-m-d H:i:s', $expires),
            'created_at'     => Time::nowUtc(),
            'last_used_at'   => Time::nowUtc(),
        ]);
        self::setRememberCookie($selector . ':' . $validator, $expires);
    }

    /** The remember tokens (devices) of a user. */
    public static function devices(int $uid): array
    {
        return Db::all('SELECT id, user_agent, created_at, last_used_at, expires_at, selector FROM admin_remember_tokens WHERE user_id = ? ORDER BY last_used_at DESC', [$uid]);
    }

    /** The selector of this browser's remember cookie (to mark "this device"). */
    public static function currentSelector(): ?string
    {
        return self::parseCookie()[0];
    }

    public static function revokeDevice(int $uid, int $tokenId): bool
    {
        return Db::delete('admin_remember_tokens', 'id = ? AND user_id = ?', [$tokenId, $uid]) > 0;
    }

    /**
     * Sign a user out everywhere: delete every remember token and end every live
     * session (the session epoch). $keepCurrent keeps the calling session alive.
     */
    public static function signOutEverywhere(int $uid, bool $keepCurrent = false): void
    {
        Db::delete('admin_remember_tokens', 'user_id = ?', [$uid]);
        if (Db::hasColumn('admin_users', 'session_epoch')) {
            Db::run('UPDATE admin_users SET session_epoch = session_epoch + 1, updated_at = ? WHERE id = ?', [Time::nowUtc(), $uid]);
            if ($keepCurrent && (int)($_SESSION['uid'] ?? 0) === $uid) {
                $_SESSION['epoch'] = (int)Db::value('SELECT session_epoch FROM admin_users WHERE id = ?', [$uid], 0);
                self::clearRememberCookie();
            }
        }
    }

    /** Use the remember cookie to open a session (GET only). */
    private static function fromRemember(): ?array
    {
        [$selector, $validator] = self::parseCookie();
        if ($selector === null) return null;
        $row = Db::one('SELECT * FROM admin_remember_tokens WHERE selector = ?', [$selector]);
        if (!$row) {
            self::clearRememberCookie();
            return null;
        }
        if ((string)$row['expires_at'] < Time::nowUtc()) {
            Db::delete('admin_remember_tokens', 'id = ?', [(int)$row['id']]);
            self::clearRememberCookie();
            return null;
        }
        $hash = hash('sha256', $validator);
        $grace = !empty($row['prev_validator_hash']) && !empty($row['rotated_at'])
            && hash_equals((string)$row['prev_validator_hash'], $hash)
            && (string)$row['rotated_at'] >= Time::utcAgo(self::GRACE);

        if (hash_equals((string)$row['validator_hash'], $hash)) {
            $new = bin2hex(random_bytes(32));
            $upd = ['validator_hash' => hash('sha256', $new), 'last_used_at' => Time::nowUtc()];
            if (Db::hasColumn('admin_remember_tokens', 'prev_validator_hash')) {
                $upd['prev_validator_hash'] = (string)$row['validator_hash'];
                $upd['rotated_at'] = Time::nowUtc();
            }
            Db::update('admin_remember_tokens', $upd, 'id = ?', [(int)$row['id']]);
            self::setRememberCookie($selector . ':' . $new, (int)strtotime((string)$row['expires_at'] . ' UTC'));
        } elseif (!$grace) {
            // A stolen or replayed cookie: end every remembered device of this user.
            Db::delete('admin_remember_tokens', 'user_id = ?', [(int)$row['user_id']]);
            Audit::log((int)$row['user_id'], 'security', 'admin_users', (string)$row['user_id'], 'A remember-device cookie did not match; every remembered device was signed out');
            self::clearRememberCookie();
            return null;
        }

        $user = Db::one('SELECT * FROM admin_users WHERE id = ?', [(int)$row['user_id']]);
        if (!$user || (int)$user['is_active'] !== 1 || (!empty($user['locked_until']) && (string)$user['locked_until'] > Time::nowUtc())) {
            self::clearRememberCookie();
            return null;
        }
        self::completeLogin($user, 'remember', false);
        return self::$user;
    }

    /** Is there a valid remember cookie for this user (checked without rotating)? */
    private static function rememberedFor(int $uid): bool
    {
        [$selector, $validator] = self::parseCookie();
        if ($selector === null) return false;
        $row = Db::one('SELECT * FROM admin_remember_tokens WHERE selector = ? AND user_id = ?', [$selector, $uid]);
        if (!$row || (string)$row['expires_at'] < Time::nowUtc()) return false;
        return hash_equals((string)$row['validator_hash'], hash('sha256', $validator));
    }

    /** @return array{0:?string,1:?string} selector and validator of the cookie */
    private static function parseCookie(): array
    {
        $c = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($c) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) return [null, null];
        return [$m[1], $m[2]];
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        $_COOKIE[self::COOKIE] = $value;
        if (headers_sent()) return;
        setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => '/admin', 'secure' => Router::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    private static function clearRememberCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (headers_sent()) return;
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/admin', 'secure' => Router::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    // =================================================================
    // Two-step enrolment and recovery codes
    // =================================================================

    /** Save a confirmed secret, switch two-step on and return 8 new recovery codes (shown once). */
    public static function enableTotp(int $uid, string $secretB32, int $step): array
    {
        Db::update('admin_users', [
            'totp_secret_enc' => Db::seal($secretB32, 'totp'),
            'totp_enabled'    => 1,
            'totp_last_step'  => $step,
            'updated_at'      => Time::nowUtc(),
        ], 'id = ?', [$uid]);
        return self::newRecoveryCodes($uid);
    }

    public static function disableTotp(int $uid): void
    {
        Db::update('admin_users', ['totp_secret_enc' => null, 'totp_enabled' => 0, 'totp_last_step' => null, 'updated_at' => Time::nowUtc()], 'id = ?', [$uid]);
        if (Db::tableExists('admin_recovery_codes')) Db::delete('admin_recovery_codes', 'user_id = ?', [$uid]);
    }

    /** Replace a user's recovery codes. Returns the plain codes (shown once). */
    public static function newRecoveryCodes(int $uid): array
    {
        if (!Db::tableExists('admin_recovery_codes')) return [];
        $codes = Totp::recoveryCodes(8);
        Db::tx(function () use ($uid, $codes): void {
            Db::delete('admin_recovery_codes', 'user_id = ?', [$uid]);
            foreach ($codes as $c) {
                Db::insert('admin_recovery_codes', ['user_id' => $uid, 'code_hash' => Totp::hashRecovery($c), 'created_at' => Time::nowUtc()]);
            }
        });
        return $codes;
    }

    public static function recoveryCodesLeft(int $uid): int
    {
        if (!Db::tableExists('admin_recovery_codes')) return 0;
        return (int)Db::value('SELECT COUNT(*) FROM admin_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$uid], 0);
    }

    private static function useRecoveryCode(int $uid, string $code): bool
    {
        if (!Db::tableExists('admin_recovery_codes')) return false;
        $n = Db::run(
            'UPDATE admin_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [Time::nowUtc(), $uid, Totp::hashRecovery($code)]
        )->rowCount();
        if ($n > 0) Audit::log($uid, 'security', 'admin_users', (string)$uid, 'Signed in with a recovery code');
        return $n > 0;
    }

    // =================================================================
    // Accounts and passwords
    // =================================================================

    /** Why a password is not allowed, or null (spec 7.2: 12+, not common, not the email). */
    public static function passwordProblem(string $pw, string $email = ''): ?string
    {
        if (mb_strlen($pw) < 12) return 'Use at least 12 characters.';
        if (strlen($pw) > 72) return 'Use 72 characters or fewer.';
        if ($email !== '' && strtolower(trim($pw)) === self::normEmail($email)) return 'The password cannot be your email address.';
        if (self::isCommon($pw)) return 'That password is on the list of common passwords. Choose another.';
        return null;
    }

    /** Create an account. Throws InvalidArgumentException with a plain message. */
    public static function createUser(string $email, string $name, string $role, string $password, bool $mustChange = false, ?int $by = null): int
    {
        $email = self::normEmail($email);
        $name = Sanitizer::text($name, false, 120);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) throw new InvalidArgumentException('Enter a valid email address.');
        if ($name === '') throw new InvalidArgumentException('Enter a name.');
        if (!isset(self::ROLES[$role])) throw new InvalidArgumentException('Choose a role.');
        if ($p = self::passwordProblem($password, $email)) throw new InvalidArgumentException($p);
        if (Db::value('SELECT 1 FROM admin_users WHERE email = ?', [$email])) throw new InvalidArgumentException('An account with that email already exists.');
        $id = Db::insert('admin_users', [
            'email'                => $email,
            'pass_hash'            => password_hash($password, PASSWORD_DEFAULT),
            'name'                 => $name,
            'role'                 => $role,
            'is_active'            => 1,
            'must_change_password' => $mustChange ? 1 : 0,
            'created_at'           => Time::nowUtc(),
            'updated_at'           => Time::nowUtc(),
        ]);
        Audit::log($by, 'create', 'admin_users', (string)$id, 'Account created for ' . $name . ' (' . self::ROLES[$role] . ')');
        return $id;
    }

    /** Change a password (the user's own change, after the current one was checked). */
    public static function setPassword(int $uid, string $password, bool $mustChange = false): void
    {
        Db::update('admin_users', [
            'pass_hash'            => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => $mustChange ? 1 : 0,
            'updated_at'           => Time::nowUtc(),
        ], 'id = ?', [$uid]);
    }

    /**
     * An owner (or the installer's owner reset, spec 2.1) sets a new password for someone:
     * the lock is cleared, every device and session of that user ends, and the two-step
     * setup is cleared when $clearTotp.
     */
    public static function adminResetPassword(int $uid, string $password, bool $mustChange = true, bool $clearTotp = false, ?int $by = null): void
    {
        Db::update('admin_users', [
            'pass_hash'            => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => $mustChange ? 1 : 0,
            'locked_until'         => null,
            'updated_at'           => Time::nowUtc(),
        ], 'id = ?', [$uid]);
        self::signOutEverywhere($uid);
        if ($clearTotp) self::disableTotp($uid);
        Audit::log($by, 'update', 'admin_users', (string)$uid, 'Password reset' . ($clearTotp ? ' and two-step sign-in cleared' : ''));
    }

    /** A readable one-time password: 4 groups of 4, no look-alike characters. */
    public static function oneTimePassword(): string
    {
        $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = [];
        for ($g = 0; $g < 4; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
            $out[] = $s;
        }
        return implode('-', $out);
    }

    public static function normEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    // =================================================================
    // Throttling
    // =================================================================

    private static function recordAttempt(string $emailHash, string $ipHash, bool $success): void
    {
        Db::insert('admin_login_attempts', ['email_hash' => $emailHash, 'ip_hash' => $ipHash, 'success' => $success ? 1 : 0, 'created_at' => Time::nowUtc()]);
    }

    /**
     * 5 failures on one account in the window (since its last complete sign-in) pause
     * it for 15 minutes. Wrong passwords and wrong two-step codes both count; a right
     * password waiting for its code is not a success. Returns true when it locked.
     */
    private static function maybeLock(array $user, string $emailHash): bool
    {
        $since = Time::utcAgo(self::lockMinutes() * 60);
        $lastOk = (string)Db::value('SELECT MAX(created_at) FROM admin_login_attempts WHERE email_hash = ? AND success = 1', [$emailHash], '');
        if ($lastOk !== '' && $lastOk > $since) $since = $lastOk;
        $fails = (int)Db::value('SELECT COUNT(*) FROM admin_login_attempts WHERE email_hash = ? AND success = 0 AND created_at >= ?', [$emailHash, $since], 0);
        if ($fails < self::accountMax()) return false;

        $uid = (int)$user['id'];
        Db::update('admin_users', ['locked_until' => Time::utcAgo(-self::lockMinutes() * 60)], 'id = ?', [$uid]);
        Audit::log($uid, 'lock', 'admin_users', (string)$uid, 'Sign-in paused for ' . self::lockMinutes() . ' minutes after ' . $fails . ' failed tries');
        $locksToday = (int)Db::value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'lock' AND entity = 'admin_users' AND entity_id = ? AND created_at >= ?",
            [(string)$uid, Time::utcAgo(86400)],
            0
        );
        if ($locksToday === 3) {
            self::alertOwners(
                siteName() . ' admin: an account was paused 3 times today',
                'The admin account for ' . $user['name'] . ' was paused 3 times in the last 24 hours after failed sign-in attempts. '
                . 'If this was not them, reset the password in Admin > Users.'
            );
        }
        return true;
    }

    /** Email every active owner through W4's Notify::owners() (it falls back to email.main). */
    private static function alertOwners(string $subject, string $text): void
    {
        if (!class_exists('Notify') || !method_exists('Notify', 'owners')) {
            Log::write('auth', 'owner alert not sent (no mailer): ' . $subject);
            return;
        }
        try {
            if (!Notify::owners($subject, $text)) Log::write('auth', 'owner alert could not be sent: ' . $subject);
        } catch (Throwable $e) {
            Log::exception($e, 'owner alert');
        }
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', 'email|' . self::normEmail($email), (string)cfg('app_secret'));
    }

    private static function lockMinutes(): int
    {
        return max(1, Db::settingInt('security.lockout_minutes', 15));
    }

    private static function accountMax(): int
    {
        return max(3, Db::settingInt('security.lockout_account_max', 5));
    }

    private static function ipMax(): int
    {
        return max(5, Db::settingInt('security.lockout_ip_max', 10));
    }

    private static function isCommon(string $pw): bool
    {
        if (self::$commonCache === null) {
            self::$commonCache = [];
            $file = APP_DIR . '/seed/common-passwords.txt';
            $list = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            if (!$list) {
                $list = ['123456789012', '1234567890123', 'password1234', 'password12345', 'passwordpassword', 'qwertyuiopas',
                    'qwerty123456', '111111111111', '000000000000', 'iloveyou1234', 'abcdefghijkl', 'abc123456789', 'letmein12345',
                    'welcome12345', 'administrator', 'adminadmin12', 'rielbuild123', 'rielbuild2026', 'renovation12', 'changeme1234'];
            }
            foreach ($list as $w) self::$commonCache[strtolower(trim($w))] = true;
        }
        return isset(self::$commonCache[strtolower($pw)]);
    }

    private static function endSession(string $message): void
    {
        $keep = $_SESSION['_csrf'] ?? null;
        $_SESSION = [];
        session_regenerate_id(true);
        if ($keep) $_SESSION['_csrf'] = $keep;
        $_SESSION['_flash'][] = ['msg' => $message, 'kind' => 'bad'];
    }

    private static function isGet(): bool
    {
        return in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true);
    }
}
