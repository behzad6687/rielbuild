<?php
/**
 * Settings, one section at a time (?section=): the business, contact details, social links, site-wide
 * wording (the estimate band and form), search defaults, email sending, form recipients, security and
 * maintenance. The owner edits everything (settings.edit, in sudo mode on a remembered device); anyone
 * with pages.edit edits the site-wide wording.
 *
 * Secret settings (the mail password) are masked: leaving the field empty keeps the stored value.
 * Routes: settings, settings/save, settings/mail-test, settings/site-address, settings/preview-site.
 */
defined('ICM') or exit;

$sections = static function (): array {
    $tel = 'Include the area code, for example 647-895-4555.';
    return [
        'identity' => [
            'label' => 'Business', 'perm' => 'settings.edit',
            'intro' => 'The business as it appears in titles, the footer and the search engine data.',
            'saved' => 'Saved. The business details are updated everywhere.',
            'fields' => [
                ['key' => 'org.name', 'label' => 'Name', 'type' => 'text', 'max' => 120, 'required' => true, 'default' => 'RIELBUILD', 'hint' => 'Added to the end of every page title, for example "Kitchen Renovation | RIELBUILD".'],
                ['key' => 'org.legal_name', 'label' => 'Legal name', 'type' => 'text', 'max' => 160, 'hint' => 'Shown in the footer copyright line.'],
                ['key' => 'org.tagline', 'label' => 'Small line under the logo', 'type' => 'text', 'max' => 80, 'default' => 'Renovation & Remodelling'],
                ['key' => 'footer.line', 'label' => 'Footer sentence under the logo', 'type' => 'textarea', 'max' => 300, 'rows' => 2, 'lint' => true],
                ['key' => 'footer.note', 'label' => 'Footer note (bottom right)', 'type' => 'text', 'max' => 200, 'hint' => 'For example "Project imagery on this site is illustrative." Empty hides it.'],
            ],
        ],
        'contact' => [
            'label' => 'Contact', 'perm' => 'settings.edit',
            'intro' => 'The phone number, email and hours shown on every page. Every tap-to-call link uses the main phone.',
            'saved' => 'Saved. Contact details are updated on every page.',
            'fields' => [
                ['key' => 'phone.main', 'label' => 'Main phone', 'type' => 'tel', 'max' => 40, 'required' => true, 'hint' => $tel],
                ['key' => 'email.main', 'label' => 'Main email', 'type' => 'email', 'max' => 191, 'required' => true],
                ['key' => 'hours.text', 'label' => 'Hours, as shown', 'type' => 'text', 'max' => 80, 'default' => 'Mon to Sat, 8am to 6pm'],
                ['key' => 'hours.days', 'label' => 'Open days for search engines', 'type' => 'text', 'max' => 120, 'default' => 'Monday,Tuesday,Wednesday,Thursday,Friday,Saturday', 'hint' => 'Day names in English, separated by commas.'],
                ['key' => 'hours.opens', 'label' => 'Opens at (24-hour time)', 'type' => 'text', 'max' => 5, 'default' => '08:00', 'pattern' => '/^\d{2}:\d{2}$/', 'pattern_msg' => 'Use 24-hour time such as 08:00.'],
                ['key' => 'hours.closes', 'label' => 'Closes at (24-hour time)', 'type' => 'text', 'max' => 5, 'default' => '18:00', 'pattern' => '/^\d{2}:\d{2}$/', 'pattern_msg' => 'Use 24-hour time such as 18:00.'],
                ['key' => 'address.city', 'label' => 'City (for search engines)', 'type' => 'text', 'max' => 80, 'default' => 'Toronto'],
                ['key' => 'area.line', 'label' => 'Service area line in the footer', 'type' => 'text', 'max' => 120, 'default' => 'Serving Toronto and the GTA'],
            ],
        ],
        'social' => [
            'label' => 'Social', 'perm' => 'settings.edit',
            'intro' => 'Full addresses of the social profiles, listed in the footer and given to search engines. An empty field hides that link.',
            'saved' => 'Saved. The social links are updated.',
            'fields' => [
                ['key' => 'social.instagram', 'label' => 'Instagram', 'type' => 'url', 'max' => 255],
                ['key' => 'social.facebook', 'label' => 'Facebook', 'type' => 'url', 'max' => 255],
                ['key' => 'social.houzz', 'label' => 'Houzz', 'type' => 'url', 'max' => 255],
                ['key' => 'social.youtube', 'label' => 'YouTube', 'type' => 'url', 'max' => 255],
                ['key' => 'social.google', 'label' => 'Google Business Profile', 'type' => 'url', 'max' => 255],
            ],
        ],
        'wording' => [
            'label' => 'Site-wide wording', 'perm' => 'pages.edit',
            'intro' => 'Wording that repeats on many pages: the estimate band near the bottom of most pages, the estimate form and the header button.',
            'saved' => 'Saved. Every page shows the new wording.',
            'fields' => [
                ['key' => 'header.cta_label', 'label' => 'Header button', 'type' => 'text', 'max' => 40, 'default' => 'Free estimate'],
                ['key' => 'cta.kicker', 'label' => 'Estimate band: small line', 'type' => 'text', 'max' => 80, 'lint' => true],
                ['key' => 'cta.title', 'label' => 'Estimate band: heading', 'type' => 'text', 'max' => 120, 'lint' => true],
                ['key' => 'cta.points', 'label' => 'Estimate band: ticks, one per line', 'type' => 'textarea', 'max' => 600, 'rows' => 3, 'lint' => true],
                ['key' => 'cta.call', 'label' => 'Estimate band: line before the phone number', 'type' => 'text', 'max' => 80, 'default' => 'Rather talk now?'],
                ['key' => 'form.heading', 'label' => 'Form: heading', 'type' => 'text', 'max' => 80, 'default' => 'Book a free estimate'],
                ['key' => 'form.sub', 'label' => 'Form: line under the heading', 'type' => 'textarea', 'max' => 200, 'rows' => 2, 'lint' => true],
                ['key' => 'form.button', 'label' => 'Form: button', 'type' => 'text', 'max' => 60, 'default' => 'Book my free estimate'],
                ['key' => 'form.note', 'label' => 'Form: note under the button', 'type' => 'text', 'max' => 160, 'lint' => true],
                ['key' => 'form.thanks_title', 'label' => 'Form: thank-you heading', 'type' => 'text', 'max' => 80, 'default' => 'Thanks. We\'ve got it.'],
                ['key' => 'form.thanks_text', 'label' => 'Form: thank-you text', 'type' => 'textarea', 'max' => 300, 'rows' => 2, 'lint' => true],
            ],
        ],
        'seo' => [
            'label' => 'Search defaults', 'perm' => 'settings.edit',
            'intro' => 'Used when a page has no description or image of its own. Each page\'s own title and description are in Pages.',
            'saved' => 'Saved. The defaults apply to pages without their own.',
            'fields' => [
                ['key' => 'seo.default_description', 'label' => 'Default meta description', 'type' => 'textarea', 'max' => 160, 'rows' => 3, 'count' => [70, 160], 'lint' => true],
                ['key' => 'seo.default_og_image', 'label' => 'Default social image', 'type' => 'asset', 'max' => 255, 'hint' => 'A 1200 by 630 JPEG under /assets/ or /uploads/.'],
                ['key' => 'seo.logo_path', 'label' => 'Logo for search engines', 'type' => 'asset', 'max' => 255, 'hint' => 'A square PNG, for example /assets/img/brand/logo.png.'],
            ],
        ],
        'email_tx' => [
            'label' => 'Email sending', 'perm' => 'settings.edit',
            'intro' => 'How the website emails you a new estimate request. Every request is also kept in the Inbox, so nothing is lost while this is being set up.',
            'saved' => 'Saved. New emails use these settings.',
            'fields' => [
                ['key' => 'mail.tx.transport', 'label' => 'How to send', 'type' => 'select', 'default' => 'mail', 'options' => [
                    'mail' => 'The web host\'s own mail (simplest)', 'smtp' => 'SMTP (a mailbox such as contact@rielbuild.ca)', 'log' => 'Do not send, save to a file (testing)', 'disabled' => 'Off (entries stay in the inbox)',
                ]],
                ['key' => 'mail.tx.host', 'label' => 'SMTP host', 'type' => 'text', 'max' => 191],
                ['key' => 'mail.tx.port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'default' => '465'],
                ['key' => 'mail.tx.secure', 'label' => 'Security', 'type' => 'select', 'default' => 'ssl', 'options' => ['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None']],
                ['key' => 'mail.tx.user', 'label' => 'User name', 'type' => 'text', 'max' => 191, 'autocomplete' => 'off'],
                ['key' => 'mail.tx.pass', 'label' => 'Password', 'type' => 'secret', 'max' => 500],
                ['key' => 'mail.tx.from', 'label' => 'From address', 'type' => 'email', 'max' => 191, 'hint' => 'An address on your own domain, for example no-reply@rielbuild.ca.'],
                ['key' => 'mail.tx.from_name', 'label' => 'From name', 'type' => 'text', 'max' => 100, 'default' => 'RIELBUILD website'],
                ['key' => 'mail.tx.daily_cap', 'label' => 'Daily sending cap', 'type' => 'number', 'min' => 1, 'max' => 100000, 'default' => '200'],
            ],
        ],
        'recipients' => [
            'label' => 'Form recipients', 'perm' => 'settings.edit',
            'intro' => 'Who gets an email for each form. Every entry is also kept in the admin inbox.',
            'saved' => 'Saved. New entries are emailed to the addresses listed.',
            'fields' => [],
        ],
        'security' => [
            'label' => 'Security', 'perm' => 'settings.edit',
            'intro' => 'Browser security headers and the admin sign-in rules.',
            'saved' => 'Saved. The security settings apply from the next request.',
            'fields' => [
                ['key' => 'security.hsts', 'label' => 'HSTS (tell browsers to use HTTPS only)', 'type' => 'bool', 'hint' => 'Turn it on after HTTPS has worked for 48 hours on the final address. It is hard to undo.'],
                ['key' => 'security.csp_mode', 'label' => 'Content Security Policy', 'type' => 'select', 'default' => 'report-only', 'options' => ['report-only' => 'Report only (log problems, block nothing)', 'enforce' => 'Enforce (block what the policy does not allow)']],
                ['key' => 'security.require_2fa_roles', 'label' => 'Roles that must use two-step sign-in', 'type' => 'roles', 'default' => ''],
                ['key' => 'security.lockout_account_max', 'label' => 'Failed tries before an account pauses', 'type' => 'number', 'min' => 3, 'max' => 20, 'default' => '5'],
                ['key' => 'security.lockout_ip_max', 'label' => 'Failed tries before a connection is blocked', 'type' => 'number', 'min' => 5, 'max' => 100, 'default' => '10'],
                ['key' => 'security.lockout_minutes', 'label' => 'Pause length (minutes)', 'type' => 'number', 'min' => 1, 'max' => 1440, 'default' => '15'],
            ],
        ],
        'site' => [
            'label' => 'Site and maintenance', 'perm' => 'settings.edit',
            'intro' => 'Maintenance mode shows visitors a "Back soon" page while you keep a preview pass.',
            'saved' => 'Saved.',
            'fields' => [
                ['key' => 'site.maintenance', 'label' => 'Maintenance mode', 'type' => 'bool'],
                ['key' => 'site.maintenance_message', 'label' => 'Maintenance message', 'type' => 'textarea', 'max' => 300, 'rows' => 2, 'default' => 'We are making a few updates to the site. Please check back in a few minutes.', 'lint' => true],
            ],
        ],
    ];
};

/** Sections this user may open, with the dynamic ones filled in. */
$allowed = static function () use ($sections): array {
    $out = [];
    foreach ($sections() as $k => $s) {
        if (!Auth::can($s['perm'])) continue;
        // A field with its own 'perm' shows (and saves) only for users who hold it.
        $s['fields'] = array_values(array_filter($s['fields'], static fn(array $f): bool => empty($f['perm']) || Auth::can((string)$f['perm'])));
        if ($k === 'recipients') {
            foreach (Db::all('SELECT type_key, label, recipients, is_enabled FROM form_types ORDER BY type_key') as $t) {
                $s['fields'][] = ['key' => 'ft.' . $t['type_key'] . '.recipients', 'label' => $t['label'] . ': recipients', 'type' => 'emails', 'max' => 500, 'value' => (string)$t['recipients'], 'hint' => 'Separate several addresses with commas.'];
                $s['fields'][] = ['key' => 'ft.' . $t['type_key'] . '.enabled', 'label' => $t['label'] . ': form is on', 'type' => 'bool', 'value' => (string)$t['is_enabled']];
            }
        }
        $out[$k] = $s;
    }
    return $out;
};

/** Validate one field. @return array{0:string,1:?string} value and error */
$clean = static function (array $f, mixed $raw): array {
    $type = (string)$f['type'];
    if ($type === 'bool') return [!empty($raw) && $raw !== '0' ? '1' : '0', null];
    if ($type === 'roles') return [implode(',', array_values(array_intersect(array_keys(Auth::ROLES), array_map('strval', (array)$raw)))), null];
    $s = Sanitizer::text(is_array($raw) ? '' : $raw, $type === 'textarea', (int)($f['max'] ?? 500));
    if ($s === '') return ['', !empty($f['required']) ? 'This is required.' : null];
    switch ($type) {
        case 'email':
            if (!filter_var($s, FILTER_VALIDATE_EMAIL)) return [$s, 'Enter a valid email address.'];
            $s = strtolower($s);
            break;
        case 'emails':
            $list = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower($s)) ?: [])));
            foreach ($list as $one) {
                if (!filter_var($one, FILTER_VALIDATE_EMAIL)) return [$s, '"' . $one . '" is not a valid email address.'];
            }
            $s = implode(', ', array_unique($list));
            break;
        case 'url':
            if (!filter_var($s, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $s)) return [$s, 'Enter a full address starting with https://'];
            break;
        case 'tel':
            if (strlen((string)preg_replace('/\D/', '', $s)) < 10) return [$s, 'Enter a phone number with at least 10 digits.'];
            break;
        case 'number':
            if (!preg_match('/^-?\d+$/', $s)) return [$s, 'Enter a whole number.'];
            $n = (int)$s;
            if (isset($f['min']) && $n < (int)$f['min']) return [$s, 'Use ' . (int)$f['min'] . ' or more.'];
            if (isset($f['max']) && $n > (int)$f['max']) return [$s, 'Use ' . (int)$f['max'] . ' or less.'];
            $s = (string)$n;
            break;
        case 'date':
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return [$s, 'Enter a date.'];
            break;
        case 'select':
            if (!array_key_exists($s, (array)$f['options'])) return [$s, 'Choose one of the options.'];
            break;
        case 'path':
            if (!preg_match('#^/(?!/)[^\s<>"]*$#', $s) && !preg_match('#^https://[^\s<>"]+$#i', $s)) return [$s, 'Enter a path that starts with / (or a full https:// address).'];
            break;
        case 'asset':
            if (!preg_match('#^/(assets|uploads)/[A-Za-z0-9._/-]+$#', $s) || str_contains($s, '..')) return [$s, 'Enter a path under /assets/ or /uploads/.'];
            break;
    }
    if (!empty($f['pattern']) && !preg_match((string)$f['pattern'], $s)) return [$s, (string)($f['pattern_msg'] ?? 'Check the format.')];
    if (!empty($f['count']) && (mb_strlen($s) < $f['count'][0] || mb_strlen($s) > $f['count'][1])) return [$s, 'Use ' . $f['count'][0] . ' to ' . $f['count'][1] . ' characters.'];
    return [$s, null];
};

/** Current values for a section's fields. */
$values = static function (array $section): array {
    $all = Db::settingsAll();
    $out = [];
    foreach ($section['fields'] as $f) {
        if (array_key_exists('value', $f)) $out[$f['key']] = (string)$f['value'];
        elseif ($f['type'] === 'secret') $out[$f['key']] = '';
        else $out[$f['key']] = (string)($all[$f['key']] ?? ($f['default'] ?? ''));
    }
    return $out;
};

$sudo = static function (array $section, string $next): void {
    if ($section['perm'] === 'settings.edit' && Auth::needsSudo('settings.edit')) {
        admin_redirect(admin_url('account/sudo', ['next' => $next]));
    }
};

$ownerOnly = static function () use ($sudo): void {
    if (!Auth::can('settings.edit')) admin_error(403);
    $sudo(['perm' => 'settings.edit'], '/admin/settings');
};

/** A real SMTP conversation: connect, EHLO, STARTTLS when asked, AUTH LOGIN. Sends nothing. */
$smtpProbe = static function (string $host, int $port, string $secure, string $user, string $pass): array {
    if ($host === '' || $port < 1) return [false, 'Enter the host and port first.'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, 'Could not connect to ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '. Check the host and port, or ask the host whether the port is open.'];
    stream_set_timeout($fp, 8);
    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = static function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };
    try {
        if (!str_starts_with($read(), '220')) return [false, 'The server answered, but not as a mail server.'];
        $me = (string)(parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost');
        if (!str_starts_with($cmd('EHLO ' . $me), '250')) return [false, 'The mail server did not accept the greeting.'];
        if ($secure === 'tls') {
            if (!str_starts_with($cmd('STARTTLS'), '220')) return [false, 'The server refused STARTTLS. Try port 465 with SSL.'];
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return [false, 'The secure connection (TLS) failed.'];
            if (!str_starts_with($cmd('EHLO ' . $me), '250')) return [false, 'The mail server did not accept the greeting after TLS.'];
        }
        if ($user !== '') {
            if (!str_starts_with($cmd('AUTH LOGIN'), '334')) return [false, 'The server does not accept this sign-in method.'];
            if (!str_starts_with($cmd(base64_encode($user)), '334')) return [false, 'The server refused the user name.'];
            $r = $cmd(base64_encode($pass));
            if (!str_starts_with($r, '235')) return [false, 'The user name or password was refused (code ' . substr(trim($r), 0, 3) . ').'];
        }
        $cmd('QUIT');
        return [true, 'Connected to ' . $host . ':' . $port . ($user !== '' ? ' and signed in' : '') . '. Nothing was sent.'];
    } finally {
        fclose($fp);
    }
};

/** Rewrite base_url in an installer-written config.local.php (spec 1.3). Returns an error or null. */
$writeLocalConfig = static function (string $file, array $changes): ?string {
    if (!is_file($file)) return 'The config file is missing.';
    $src = (string)file_get_contents($file);
    // Only a plain data file is rewritten (the same token check the installer uses):
    // a password holding "$" or "function" is fine, real code is refused.
    if (!isPlainConfigSource($src)) {
        return 'This config file was not written by the installer, so it is not changed here. Edit base_url in app/config.local.php by hand.';
    }
    $cfg = (static fn(string $__f): mixed => require $__f)($file);
    if (!is_array($cfg)) return 'The config file could not be read.';
    $cfg = array_replace($cfg, $changes);
    // Short-array export in the installer's format (spec 1.3): keys and scalars go
    // through var_export, so no value ever becomes code.
    $export = static function (array $a, int $depth = 0) use (&$export): string {
        $pad = str_repeat('    ', $depth + 1);
        $s = "[\n";
        foreach ($a as $k => $v) {
            $s .= $pad . var_export($k, true) . ' => ' . (is_array($v) ? $export($v, $depth + 1) : var_export($v, true)) . ",\n";
        }
        return $s . str_repeat('    ', $depth) . ']';
    };
    $out = "<?php defined('ICM') or exit;\nreturn " . $export($cfg) . ";\n";
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $out, LOCK_EX) === false) return 'The app folder is not writable.';
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return 'The config file could not be replaced.';
    }
    if (!@chmod($file, 0600)) @chmod($file, 0640);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    return null;
};

return [
    'nav'    => ['group' => 'Site', 'label' => 'Settings', 'path' => 'settings', 'perm' => 'pages.edit', 'sort' => 30],
    'lib'    => ['writeLocalConfig' => $writeLocalConfig, 'clean' => $clean, 'sections' => $sections],
    'routes' => [
        'settings' => ['perm' => 'dashboard.view', 'methods' => ['GET'], 'handler' => function (array $req) use ($allowed, $values, $sudo) {
            $secs = $allowed();
            if (!$secs) admin_error(403);
            $key = (string)($req['query']['section'] ?? '');
            if (!isset($secs[$key])) $key = (string)array_key_first($secs);
            $sudo($secs[$key], admin_url('settings', ['section' => $key]));
            $sec = $secs[$key];
            if (!empty($sec['moved']) && admin_has_route((string)$sec['moved'])) admin_redirect(admin_url((string)$sec['moved']));
            admin_render('settings/index', [
                'title'    => 'Settings',
                'sections' => $secs,
                'key'      => $key,
                'section'  => $sec,
                'values'   => $values($sec),
                'errors'   => [],
                'secrets'  => array_fill_keys(array_map(fn($f) => $f['key'], array_filter($sec['fields'], fn($f) => $f['type'] === 'secret' && Db::hasSecret($f['key']))), true),
            ]);
        }],

        'settings/save' => ['perm' => 'dashboard.view', 'methods' => ['POST'], 'handler' => function (array $req) use ($allowed, $clean, $values, $sudo) {
            $secs = $allowed();
            $key = (string)($req['post']['section'] ?? '');
            if (!isset($secs[$key])) admin_error(403);
            $sec = $secs[$key];
            $sudo($sec, admin_url('settings', ['section' => $key]));
            if (!empty($sec['moved']) && admin_has_route((string)$sec['moved'])) admin_redirect(admin_url((string)$sec['moved']), (string)$sec['saved']);
            $in = (array)($req['post']['s'] ?? []);
            $clear = (array)($req['post']['c'] ?? []);
            $errors = [];
            $new = [];
            $secretsSet = [];
            foreach ($sec['fields'] as $f) {
                $k = $f['key'];
                if ($f['type'] === 'secret') {
                    $raw = (string)($in[$k] ?? '');
                    if (!empty($clear[$k])) $secretsSet[$k] = null;
                    elseif ($raw !== '') {
                        if (mb_strlen($raw) > (int)($f['max'] ?? 500)) $errors[$k] = 'That is too long.';
                        else $secretsSet[$k] = $raw;
                    }
                    continue;
                }
                [$v, $err] = $clean($f, $in[$k] ?? ($f['type'] === 'bool' ? '0' : ($f['type'] === 'roles' ? [] : '')));
                if ($err !== null) $errors[$k] = $err;
                $new[$k] = $v;
            }
            if ($key === 'notice' && ($new['notice.starts_on'] ?? '') !== '' && ($new['notice.ends_on'] ?? '') !== '' && $new['notice.ends_on'] < $new['notice.starts_on']) {
                $errors['notice.ends_on'] = 'The end date is before the start date.';
            }
            if ($key === 'security' && Auth::requires2fa('owner') && !str_contains($new['security.require_2fa_roles'] ?? '', 'owner')) {
                // Allowed, but say what it means.
                admin_flash('Owners no longer have to use two-step sign-in. That makes the admin easier to break into.', 'bad');
            }
            if ($errors) {
                http_response_code(422);
                admin_render('settings/index', [
                    'title' => 'Settings', 'sections' => $secs, 'key' => $key, 'section' => $sec,
                    'values' => array_replace($values($sec), $new), 'errors' => $errors,
                    'secrets' => array_fill_keys(array_map(fn($f) => $f['key'], array_filter($sec['fields'], fn($f) => $f['type'] === 'secret' && Db::hasSecret($f['key']))), true),
                ]);
            }

            $before = $values($sec);
            $uid = Auth::id();
            if ($key === 'recipients') {
                foreach ($new as $k => $v) {
                    if (!preg_match('/^ft\.([a-z_]+)\.(recipients|enabled)$/', $k, $m)) continue;
                    Db::update('form_types', [$m[2] === 'enabled' ? 'is_enabled' : 'recipients' => $m[2] === 'enabled' ? (int)$v : ($v !== '' ? $v : null), 'updated_at' => Time::nowUtc()], 'type_key = ?', [$m[1]]);
                }
            } else {
                foreach ($new as $k => $v) Db::setSetting($k, $v, $uid);
            }
            foreach ($secretsSet as $k => $v) Db::setSecret($k, $v, $uid);

            $detail = Audit::diff($before, $new);
            foreach ($secretsSet as $k => $v) $detail[$k] = [$v === null ? 'cleared' : 'changed', ''];
            admin_audit('update', 'settings', $key, 'Settings saved: ' . $sec['label'], $detail);

            $msg = (string)$sec['saved'];
            // Round 2 (SiteEvents, ADMIN-AUDIT 4.14): the header and footer read these; the ranking pages read ranking.*.
            if (in_array($key, ['contact', 'social', 'identity', 'wording'], true)) SiteEvents::changed('layout');
            if ($key === 'site') {
                $on = ($new['site.maintenance'] ?? '0') === '1';
                $was = ($before['site.maintenance'] ?? '0') === '1';
                if ($on && !$was) {
                    Router::issueBypassCookie();
                    $msg = 'Maintenance mode is on. Visitors see the "Back soon" page; this browser keeps a 12-hour preview pass.';
                } elseif (!$on && $was) {
                    $msg = 'Maintenance mode is off. The site is open to everyone again.';
                }
            }
            if ($key === 'security' && ($new['security.hsts'] ?? '0') === '1' && !Router::isHttps()) {
                $msg .= ' Note: this request did not come over HTTPS, so check HTTPS works before relying on HSTS.';
            }
            admin_redirect(admin_url('settings', ['section' => $key]), $msg);
        }],

        'settings/mail-test' => ['perm' => 'dashboard.view', 'methods' => ['POST'], 'handler' => function (array $req) use ($ownerOnly, $smtpProbe) {
            $ownerOnly();
            $stream = ($req['post']['stream'] ?? '') === 'news' ? 'news' : 'tx';
            $mode = ($req['post']['mode'] ?? '') === 'send' ? 'send' : 'connect';
            $back = admin_url('settings', ['section' => $stream === 'news' ? 'email_news' : 'email_tx']);

            if ($mode === 'send') {
                $to = (string)$req['user']['email'];
                if (!class_exists('Mailer') || !method_exists('Mailer', 'send')) {
                    admin_redirect($back, 'The mailer is not available in this build yet, so no email was sent. Test the connection instead.', 'bad');
                }
                $text = "This is a test email from the ICM Tennis admin.\n\nIf you can read it, website email works.";
                try {
                    $r = Mailer::send([
                        'to'       => $to,
                        'subject'  => siteName() . ' website: test email',
                        'html'     => '<p>This is a test email from the ' . e(siteName()) . ' website admin.</p><p>If you can read it, website email works.</p>',
                        'text'     => $text,
                        'stream'   => 'test',
                        'priority' => 'high',
                    ]);
                } catch (Throwable $e) {
                    Log::exception($e, 'test email');
                    $r = ['ok' => false, 'deferred' => false, 'info' => $e->getMessage()];
                }
                $ok = !empty($r['ok']);
                admin_audit('send', 'settings', 'mail.tx', 'Test email to ' . Log::maskEmail($to) . ($ok ? ' sent' : (!empty($r['deferred']) ? ' waiting for the daily cap' : ' failed')));
                if (!empty($r['deferred'])) admin_redirect($back, 'The daily cap is reached, so the test email waits and goes out when the cap resets.', 'bad');
                admin_redirect($back, $ok ? 'Test email sent to ' . $to . '. Check the inbox (and the spam folder).' : 'The test email could not be sent (' . mb_substr((string)($r['info'] ?? ''), 0, 120) . '). See System > Logs > mail.', $ok ? 'ok' : 'bad');
            }

            $useNews = $stream === 'news' || Db::setting('mail.tx.transport', 'smtp') === 'relay';
            $p = $useNews ? 'newsletter.' : 'mail.tx.';
            $transport = (string)Db::setting($useNews ? 'newsletter.transport' : 'mail.tx.transport', $useNews ? 'disabled' : 'mail');
            if ($stream === 'tx' && $transport === 'mail') {
                [$ok, $msg] = [true, 'The web host\'s own mail needs no connection test. Press "Send a test email" and check the inbox.'];
            } elseif ($stream === 'tx' && in_array($transport, ['log', 'disabled'], true)) {
                [$ok, $msg] = [true, 'Nothing to test: this transport does not use a mail server.'];
            } else {
                [$ok, $msg] = $smtpProbe(
                    (string)Db::setting($p . 'host', ''),
                    Db::settingInt($p . 'port', 0),
                    (string)Db::setting($p . 'secure', $useNews ? 'tls' : 'ssl'),
                    (string)Db::setting($p . 'user', ''),
                    (string)Db::secret($p . 'pass')
                );
            }
            if ($ok) Db::setSetting(($stream === 'news' ? 'newsletter' : 'mail.tx') . '.test_passed_at', Time::nowUtc());
            admin_audit('status', 'settings', $stream, 'Mail connection test: ' . ($ok ? 'passed' : 'failed'));
            admin_redirect($back, $msg, $ok ? 'ok' : 'bad');
        }],

        'settings/site-address' => ['perm' => 'dashboard.view', 'methods' => ['POST'], 'handler' => function (array $req) use ($ownerOnly, $writeLocalConfig) {
            $ownerOnly();
            $back = admin_url('settings', ['section' => 'site']);
            $new = rtrim(trim((string)($req['post']['base_url'] ?? '')), '/');
            $p = parse_url($new);
            if (!filter_var($new, FILTER_VALIDATE_URL) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['https', 'http'], true)
                || !empty($p['path']) || !empty($p['query']) || !empty($p['fragment']) || !empty($p['user'])) {
                admin_redirect($back, 'Enter just the address, for example https://icmtennis.com (no path).', 'bad');
            }
            $old = (string)cfg('base_url');
            if ($new === rtrim($old, '/')) admin_redirect($back, 'That is already the site address.');
            $err = $writeLocalConfig(APP_DIR . '/config.local.php', ['base_url' => $new]);
            if ($err !== null) admin_redirect($back, $err, 'bad');
            Sitemap::invalidate();
            admin_audit('update', 'config', 'base_url', 'Site address changed', ['base_url' => [$old, $new]]);
            admin_redirect($back, 'The site address is now ' . $new . '. Other addresses now redirect there.');
        }],

        'settings/preview-site' => ['perm' => 'dashboard.view', 'methods' => ['POST'], 'handler' => function (array $req) use ($ownerOnly) {
            $ownerOnly();
            Router::issueBypassCookie();
            admin_audit('status', 'settings', 'site.maintenance', 'Maintenance preview pass issued to this browser');
            admin_redirect('/');
        }],
    ],
];
