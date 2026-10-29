<?php
/**
 * My account (spec 4.2.14, 7.2). Owner: W7.
 *   account           name and phone, devices, recent sign-ins
 *   account/password  change the password (the current one is required)
 *   account/2fa       set up, confirm or turn off two-step sign-in; new recovery codes
 *   account/devices   forget one remembered device, or every other device
 *   account/sudo      re-enter the password (and code) before owner tools
 * Every signed-in role may use these screens.
 */
defined('ICM') or exit;

$row = static fn(int $id): array => (array)Db::one('SELECT * FROM admin_users WHERE id = ?', [$id]);

$safeNext = static function (string $next): string {
    return preg_match('#^/admin(/[a-z0-9/_.-]*)?(\?[^\s<>"]*)?$#i', $next) && !preg_match('#^/admin/(login|logout|account/sudo)#', $next) ? $next : '/admin/';
};

return [
    'routes' => [
        'account' => ['perm' => 'dashboard.view', 'methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($row) {
            $u = $row((int)$req['user']['id']);
            $errors = [];
            $old = null;
            if ($req['method'] === 'POST') {
                $name = Sanitizer::text($req['post']['name'] ?? '', false, 120);
                $phone = Sanitizer::text($req['post']['phone'] ?? '', false, 40);
                $old = ['name' => $name, 'phone' => $phone];
                if ($name === '') $errors['name'] = 'Enter your name.';
                if ($phone !== '' && strlen((string)preg_replace('/\D/', '', $phone)) < 10) $errors['phone'] = 'Enter a phone number with at least 10 digits, or leave it empty.';
                if ($stale = admin_stale((string)$u['updated_at'], (string)($req['post']['updated_at'] ?? ''))) $errors['_form'] = $stale;
                if (!$errors) {
                    $new = ['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'updated_at' => Time::nowUtc()];
                    Db::update('admin_users', $new, 'id = ?', [(int)$u['id']]);
                    admin_audit('update', 'admin_users', (string)$u['id'], 'Updated own profile', Audit::diff($u, $new));
                    admin_redirect('account', 'Saved. Your details are updated.');
                }
                http_response_code(422);
            }
            admin_render('account/index', [
                'title'    => 'My account',
                'u'        => $u,
                'old'      => $old,
                'errors'   => $errors,
                'devices'  => Auth::devices((int)$u['id']),
                'current'  => Auth::currentSelector(),
                'logins'   => Audit::logins((int)$u['id'], 10),
                'codesLeft' => Auth::recoveryCodesLeft((int)$u['id']),
            ]);
        }],

        'account/password' => ['perm' => 'dashboard.view', 'methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($row) {
            $u = $row((int)$req['user']['id']);
            $errors = [];
            if ($req['method'] === 'POST') {
                $current = (string)($req['post']['current'] ?? '');
                $new = (string)($req['post']['new'] ?? '');
                $confirm = (string)($req['post']['confirm'] ?? '');
                if (!password_verify($current, (string)$u['pass_hash'])) $errors['current'] = 'That is not your current password.';
                if ($p = Auth::passwordProblem($new, (string)$u['email'])) $errors['new'] = $p;
                elseif ($new === $current) $errors['new'] = 'Choose a password you have not used here before.';
                if (!isset($errors['new']) && $new !== $confirm) $errors['confirm'] = 'The two new passwords do not match.';
                if (!$errors) {
                    Auth::setPassword((int)$u['id'], $new, false);
                    Auth::signOutEverywhere((int)$u['id'], true);
                    admin_audit('update', 'admin_users', (string)$u['id'], 'Changed own password (other devices signed out)');
                    admin_redirect(Auth::mustEnrol() ? 'account/2fa' : '', 'Password changed. Any other devices were signed out.');
                }
                http_response_code(422);
            }
            admin_render('account/password', ['title' => 'Change password', 'errors' => $errors, 'forced' => (int)$u['must_change_password'] === 1, 'u' => $u]);
        }],

        'account/2fa' => ['perm' => 'dashboard.view', 'methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($row) {
            $u = $row((int)$req['user']['id']);
            $uid = (int)$u['id'];
            $required = Auth::requires2fa((string)$u['role']);
            $error = '';

            if ($req['method'] === 'POST') {
                $action = (string)($req['post']['action'] ?? '');
                if ($action === 'confirm' && (int)$u['totp_enabled'] !== 1) {
                    $setup = $_SESSION['totp_setup'] ?? null;
                    $step = is_array($setup) ? Totp::verify((string)$setup['secret'], (string)($req['post']['code'] ?? '')) : null;
                    if ($step === null) {
                        $error = 'That code did not work. Check the time on your phone is set automatically, then try the newest code.';
                    } else {
                        $codes = Auth::enableTotp($uid, (string)$setup['secret'], $step);
                        unset($_SESSION['totp_setup']);
                        $_SESSION['show_codes'] = $codes;
                        admin_audit('update', 'admin_users', (string)$uid, 'Two-step sign-in turned on');
                        admin_redirect('account/2fa', 'Two-step sign-in is on. Save your recovery codes now.');
                    }
                } elseif (in_array($action, ['disable', 'codes'], true) && (int)$u['totp_enabled'] === 1) {
                    if (!password_verify((string)($req['post']['password'] ?? ''), (string)$u['pass_hash'])) {
                        $error = 'That password did not match.';
                    } elseif ($action === 'disable') {
                        if ($required) {
                            $error = 'Two-step sign-in is required for your role, so it cannot be turned off.';
                        } else {
                            Auth::disableTotp($uid);
                            admin_audit('update', 'admin_users', (string)$uid, 'Two-step sign-in turned off');
                            admin_redirect('account', 'Two-step sign-in is off.');
                        }
                    } else {
                        $_SESSION['show_codes'] = Auth::newRecoveryCodes($uid);
                        admin_audit('update', 'admin_users', (string)$uid, 'New recovery codes made (the old ones stopped working)');
                        admin_redirect('account/2fa', 'New recovery codes are ready. The old ones no longer work.');
                    }
                } elseif ($action === 'restart') {
                    unset($_SESSION['totp_setup']);
                    admin_redirect('account/2fa');
                }
                http_response_code(422);
            }

            $codes = $_SESSION['show_codes'] ?? null;
            unset($_SESSION['show_codes']);
            $setup = null;
            if ((int)$u['totp_enabled'] !== 1) {
                $setup = $_SESSION['totp_setup'] ?? null;
                if (!is_array($setup) || Time::ts() - (int)($setup['at'] ?? 0) > 1800) {
                    $setup = ['secret' => Totp::newSecret(), 'at' => Time::ts()];
                    $_SESSION['totp_setup'] = $setup;
                }
            }
            admin_render('account/2fa', [
                'title'     => 'Two-step sign-in',
                'u'         => $u,
                'required'  => $required,
                'error'     => $error,
                'codes'     => is_array($codes) ? $codes : null,
                'codesLeft' => Auth::recoveryCodesLeft($uid),
                'secret'    => $setup['secret'] ?? null,
                'qr'        => $setup ? Totp::qrSvg(Totp::uri((string)$setup['secret'], (string)$u['email'])) : '',
            ]);
        }],

        'account/devices' => ['perm' => 'dashboard.view', 'methods' => ['POST'], 'handler' => function (array $req) {
            $uid = (int)$req['user']['id'];
            if (($req['post']['action'] ?? '') === 'others') {
                Auth::signOutEverywhere($uid, true);
                admin_audit('update', 'admin_users', (string)$uid, 'Signed out every other device');
                admin_redirect('account', 'Every other device is signed out. This one stays signed in.');
            }
            $ok = Auth::revokeDevice($uid, (int)($req['post']['id'] ?? 0));
            if ($ok) admin_audit('delete', 'admin_remember_tokens', (string)(int)($req['post']['id'] ?? 0), 'Forgot a remembered device');
            admin_redirect('account', $ok ? 'That device is forgotten. It will ask for the password next time.' : 'That device was already forgotten.');
        }],

        'account/sudo' => ['perm' => 'dashboard.view', 'methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($safeNext) {
            $next = $safeNext((string)($req['post']['next'] ?? $req['query']['next'] ?? '/admin/'));
            $error = '';
            if ($req['method'] === 'POST') {
                $error = (string)Auth::confirmSudo((string)($req['post']['password'] ?? ''), (string)($req['post']['code'] ?? ''));
                if ($error === '') admin_redirect($next);
                http_response_code(422);
            }
            admin_render('account/sudo', ['title' => 'Confirm it is you', 'next' => $next, 'error' => $error, 'needsCode' => (int)$req['user']['totp_enabled'] === 1]);
        }],
    ],
];
