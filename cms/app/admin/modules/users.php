<?php
/**
 * Users and roles (spec 4.2.14, owner only). Owner: W7.
 *   users              the list
 *   users/new          invite: the account gets a one-time password shown once
 *   users/created      shows that one-time password once
 *   users/edit         name, email, phone, role, active; the last 20 sign-ins
 *   users/password     reset the password (one-time, must change it at sign-in)
 *   users/signout      sign out every device and session
 *   users/twofa        reset two-step sign-in (lost phone)
 *   users/clear-lock   let a paused account sign in again
 * Guards: the last active owner cannot be demoted or deactivated, and nobody
 * deactivates or demotes themselves.
 */
defined('ICM') or exit;

$isMaster = static fn(array $req): bool => ($req['user']['role'] ?? '') === Auth::MASTER;

$load = static function (array $req) use ($isMaster): array {
    $id = (int)($req['query']['id'] ?? $req['post']['id'] ?? 0);
    $u = $id > 0 ? Db::one('SELECT * FROM admin_users WHERE id = ?', [$id]) : null;
    // v3: a master account is invisible to everyone but a master
    if ($u && $u['role'] === Auth::MASTER && !$isMaster($req)) $u = null;
    if (!$u) admin_redirect('users', 'That account no longer exists.', 'bad');
    return $u;
};

$activeOwners = static fn(): int => (int)Db::value("SELECT COUNT(*) FROM admin_users WHERE role IN ('owner', 'master') AND is_active = 1", [], 0);

$showOnce = static function (array $u, string $password, string $why): never {
    $_SESSION['once_password'] = ['id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)$u['email'], 'password' => $password, 'why' => $why, 'at' => Time::ts()];
    admin_redirect('users/created');
};

return [
    'nav'    => ['group' => 'Site', 'label' => 'Users', 'path' => 'users', 'perm' => 'users.manage', 'sort' => 20],
    'routes' => [
        'users' => ['methods' => ['GET'], 'handler' => function (array $req) use ($isMaster) {
            $hideMaster = $isMaster($req) ? '' : " WHERE role <> 'master'";
            admin_render('users/list', [
                'title' => 'Users',
                'rows'  => Db::all('SELECT id, email, name, role, is_active, totp_enabled, locked_until, last_login_at, must_change_password FROM admin_users' . $hideMaster . ' ORDER BY is_active DESC, name'),
            ]);
        }],

        'users/new' => ['methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($showOnce, $isMaster) {
            $errors = [];
            $old = ['name' => '', 'email' => '', 'role' => 'coach'];
            if ($req['method'] === 'POST') {
                $old = [
                    'name'  => Sanitizer::text($req['post']['name'] ?? '', false, 120),
                    'email' => Auth::normEmail(Sanitizer::text($req['post']['email'] ?? '', false, 191)),
                    'role'  => (string)($req['post']['role'] ?? ''),
                ];
                if ($old['role'] === Auth::MASTER && !$isMaster($req)) $old['role'] = 'owner';
                $pw = Auth::oneTimePassword();
                try {
                    $id = Auth::createUser($old['email'], $old['name'], $old['role'], $pw, true, Auth::id());
                    $showOnce((array)Db::one('SELECT * FROM admin_users WHERE id = ?', [$id]), $pw, 'invite');
                } catch (InvalidArgumentException $e) {
                    $errors['_form'] = $e->getMessage();
                }
                http_response_code(422);
            }
            admin_render('users/edit', ['title' => 'Invite someone', 'u' => null, 'old' => $old, 'errors' => $errors, 'logins' => [], 'devices' => 0]);
        }],

        'users/created' => ['methods' => ['GET'], 'handler' => function (array $req) {
            $once = $_SESSION['once_password'] ?? null;
            unset($_SESSION['once_password']);
            if (!is_array($once) || Time::ts() - (int)$once['at'] > 600) admin_redirect('users');
            admin_render('users/created', ['title' => 'One-time password', 'once' => $once]);
        }],

        'users/edit' => ['methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($load, $activeOwners, $isMaster) {
            $u = $load($req);
            $uid = (int)$u['id'];
            $self = $uid === (int)$req['user']['id'];
            $errors = [];
            $old = null;
            if ($req['method'] === 'POST') {
                $old = [
                    'name'      => Sanitizer::text($req['post']['name'] ?? '', false, 120),
                    'email'     => Auth::normEmail(Sanitizer::text($req['post']['email'] ?? '', false, 191)),
                    'phone'     => Sanitizer::text($req['post']['phone'] ?? '', false, 40),
                    'role'      => (string)($req['post']['role'] ?? $u['role']),
                    'is_active' => !empty($req['post']['is_active']) ? 1 : 0,
                ];
                if ($old['name'] === '') $errors['name'] = 'Enter a name.';
                if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
                elseif (Db::value('SELECT 1 FROM admin_users WHERE email = ? AND id <> ?', [$old['email'], $uid])) $errors['email'] = 'Another account uses that email.';
                if (!isset(Auth::ROLES[$old['role']]) || ($old['role'] === Auth::MASTER && !$isMaster($req))) $errors['role'] = 'Choose a role.';
                if ($self && $old['role'] !== $u['role']) $errors['role'] = 'You cannot change your own role. Ask another owner.';
                if ($self && !$old['is_active']) $errors['is_active'] = 'You cannot deactivate your own account.';
                $losingOwner = Auth::isOwner($u['role']) && (int)$u['is_active'] === 1 && (!Auth::isOwner($old['role']) || !$old['is_active']);
                if ($losingOwner && $activeOwners() <= 1) $errors['role'] = 'This is the only active owner. Make someone else an owner first.';
                if ($stale = admin_stale((string)$u['updated_at'], (string)($req['post']['updated_at'] ?? ''))) $errors['_form'] = $stale;
                if (!$errors) {
                    $new = $old + ['updated_at' => Time::nowUtc()];
                    $new['phone'] = $new['phone'] !== '' ? $new['phone'] : null;
                    Db::update('admin_users', $new, 'id = ?', [$uid]);
                    if (!$new['is_active'] && (int)$u['is_active'] === 1) Auth::signOutEverywhere($uid);
                    admin_audit('update', 'admin_users', (string)$uid, 'Account updated: ' . $new['name'], Audit::diff($u, $new));
                    admin_redirect('users/edit?id=' . $uid, $new['is_active'] ? 'Saved.' : 'Saved. ' . $new['name'] . ' is deactivated and signed out everywhere.');
                }
                http_response_code(422);
            }
            admin_render('users/edit', [
                'title'   => $u['name'],
                'u'       => $u,
                'old'     => $old,
                'errors'  => $errors,
                'self'    => $self,
                'logins'  => Audit::logins($uid, 20),
                'devices' => count(Auth::devices($uid)),
            ]);
        }],

        'users/password' => ['methods' => ['POST'], 'handler' => function (array $req) use ($load, $showOnce) {
            $u = $load($req);
            $pw = Auth::oneTimePassword();
            Auth::adminResetPassword((int)$u['id'], $pw, true, false, Auth::id());
            $showOnce($u, $pw, 'reset');
        }],

        'users/signout' => ['methods' => ['POST'], 'handler' => function (array $req) use ($load) {
            $u = $load($req);
            Auth::signOutEverywhere((int)$u['id'], (int)$u['id'] === (int)$req['user']['id']);
            admin_audit('update', 'admin_users', (string)$u['id'], 'Signed out every device of ' . $u['name']);
            admin_redirect('users/edit?id=' . (int)$u['id'], $u['name'] . ' is signed out on every device.');
        }],

        'users/twofa' => ['methods' => ['POST'], 'handler' => function (array $req) use ($load) {
            $u = $load($req);
            Auth::disableTotp((int)$u['id']);
            Auth::signOutEverywhere((int)$u['id'], (int)$u['id'] === (int)$req['user']['id']);
            admin_audit('update', 'admin_users', (string)$u['id'], 'Two-step sign-in reset for ' . $u['name']);
            $again = Auth::requires2fa((string)$u['role']) ? ' They will set it up again at their next sign-in.' : '';
            admin_redirect('users/edit?id=' . (int)$u['id'], 'Two-step sign-in is reset for ' . $u['name'] . '.' . $again);
        }],

        'users/clear-lock' => ['methods' => ['POST'], 'handler' => function (array $req) use ($load) {
            $u = $load($req);
            Db::update('admin_users', ['locked_until' => null, 'updated_at' => Time::nowUtc()], 'id = ?', [(int)$u['id']]);
            admin_audit('update', 'admin_users', (string)$u['id'], 'Sign-in pause cleared for ' . $u['name']);
            admin_redirect('users/edit?id=' . (int)$u['id'], $u['name'] . ' can sign in again.');
        }],
    ],
];
