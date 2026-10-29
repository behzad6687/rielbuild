<?php
/**
 * System (spec 4.2.14, owner only). Owner: W7.
 * Health, cron (last runs and the hPanel commands), pending migrations with
 * "Apply database update", backups (list, back up now, download), search index,
 * cache, deleting the installer, and the log tails.
 */
defined('ICM') or exit;

$dirSize = static function (string $dir, int $cap = 50000): array {
    $bytes = 0;
    $files = 0;
    if (!is_dir($dir)) return [0, 0, false];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $bytes += $f->getSize();
            if (++$files >= $cap) return [$bytes, $files, true];
        }
    } catch (Throwable) {
    }
    return [$bytes, $files, false];
};

$logChannels = ['php-error', 'error', 'cron', 'csp', 'router', 'migrate', 'admin', 'auth', 'media', 'mail', 'view', 'lint'];

$health = static function (array $req) use ($dirSize, $logChannels): array {
    $mysql = Db::isMysql();
    $required = [
        ($mysql ? 'pdo_mysql' : 'pdo_sqlite') => $mysql ? 'Database (MySQL)' : 'Database (SQLite, development only)',
        'mbstring' => 'Text handling', 'gd' => 'Images', 'fileinfo' => 'File type checks', 'openssl' => 'Encryption and DKIM keys',
        'json' => 'JSON', 'ctype' => 'Text checks', 'sodium' => 'Encrypted settings', 'zlib' => 'Backups (gzip)',
    ];
    $optional = [
        'curl' => 'Relay API and the installer\'s rewrite check', 'zip' => 'Mailchimp ZIP import', 'intl' => 'Better text sorting',
        'exif' => 'Photo orientation (a built-in reader covers it)', 'imap' => 'Bounce mailbox (Hostinger mail only)', 'Zend OPcache' => 'Faster PHP',
    ];
    $ext = [];
    foreach ($required as $e => $why) $ext[] = ['name' => $e, 'why' => $why, 'ok' => extension_loaded($e), 'required' => true];
    $ext[] = ['name' => 'gd WebP', 'why' => 'WebP image variants', 'ok' => function_exists('imagewebp'), 'required' => true];
    foreach ($optional as $e => $why) $ext[] = ['name' => $e, 'why' => $why, 'ok' => extension_loaded($e), 'required' => false];

    $ini = [];
    foreach ([
        'memory_limit' => ['512M', 256], 'upload_max_filesize' => ['16M', 16], 'post_max_size' => ['20M', 20],
        'max_execution_time' => ['120', 60], 'max_input_vars' => ['2000', 1000],
    ] as $k => [$want, $min]) {
        $v = (string)ini_get($k);
        $n = in_array($k, ['max_execution_time', 'max_input_vars'], true) ? (int)$v : (int)floor(Img::toBytes($v) / 1048576);
        $ok = $v === '-1' || $v === '0' && $k === 'max_execution_time' || $n >= $min;
        $ini[] = ['name' => $k, 'value' => $v, 'want' => $want, 'ok' => $ok];
    }

    [$upBytes, $upFiles, $upMore] = $dirSize(ROOT_DIR . '/uploads');
    $priv = privatePath();
    [$pvBytes, $pvFiles, $pvMore] = $dirSize($priv);
    $dbSize = null;
    try {
        $dbSize = $mysql
            ? (int)Db::value('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()', [], 0)
            : (int)@filesize((string)cfg('db.sqlite')) + (int)@filesize((string)cfg('db.sqlite') . '-wal');
    } catch (Throwable) {
    }

    $tasks = [];
    foreach (Cron::TASKS as $t => $default) {
        $tasks[] = [
            'name'     => $t,
            'schedule' => (string)Db::setting('cron.schedule.' . $t, $default),
            'last'     => (string)Db::setting('cron.last.' . $t, ''),
            'result'   => (string)Db::setting('cron.result.' . $t, ''),
            'error'    => (string)Db::setting('cron.error.' . $t, ''),
            'shipped'  => is_file(APP_DIR . '/cron/tasks/' . $t . '.php'),
        ];
    }

    $channel = (string)($req['query']['log'] ?? 'php-error');
    if (!in_array($channel, $logChannels, true)) $channel = 'php-error';

    return [
        'title'      => 'System',
        'php'        => PHP_VERSION,
        'sapi'       => PHP_SAPI,
        'db'         => (static function (): string {
            try {
                return Db::serverVersion();
            } catch (Throwable) {
                return 'unknown';
            }
        })(),
        'ext'        => $ext,
        'ini'        => $ini,
        'uploads'    => [$upBytes, $upFiles, $upMore],
        'private'    => ['rel' => (string)cfg('paths.private', 'storage/private'), 'exists' => is_dir($priv), 'writable' => Backup::writable($priv), 'bytes' => $pvBytes, 'files' => $pvFiles, 'more' => $pvMore],
        'dbSize'     => $dbSize,
        'https'      => Router::isHttps(),
        'ip'         => clientIp(),
        'forwarded'  => isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']),
        'baseUrl'    => (string)cfg('base_url'),
        'staging'    => isStaging(),
        'version'    => appVersion(),
        'now'        => Time::nowLocal()->format('F j, Y, g:i a T'),
        'writable'   => array_map(fn($d) => ['dir' => $d, 'ok' => Backup::writable(ROOT_DIR . '/' . $d)], ['storage/logs', 'storage/cache', 'storage/sessions', 'uploads/img', 'uploads/docs']),
        'cron'       => [
            'last'    => (string)Db::setting('cron.last_run', ''),
            'php'     => (string)Db::setting('cron.php', ''),
            'sapi'    => (string)Db::setting('cron.sapi', ''),
            'binary'  => (string)Db::setting('cron.binary', ''),
            'via'     => (string)Db::setting('cron.via', ''),
            'tasks'   => $tasks,
            'cmds'    => Cron::commands(),
            'web'     => (string)cfg('cron_token') !== '' ? url('/hooks/cron/' . cfg('cron_token') . '/') : '',
        ],
        'applied'    => Migrator::applied(),
        'pending'    => Migrator::pending(),
        'backups'    => Backup::all(),
        'backupInfo' => [
            'last'     => (string)Db::setting('backup.last_at', ''),
            'download' => (string)Db::setting('backup.last_download_at', ''),
            'error'    => (string)Db::setting('backup.last_error', ''),
        ],
        'installer'  => is_dir(ROOT_DIR . '/install'),
        'lock'       => is_file(STORAGE_DIR . '/install.lock'),
        'channels'   => $logChannels,
        'channel'    => $channel,
        'log'        => Log::tail($channel, 200),
    ];
};

$rrmdir = static function (string $dir) use (&$rrmdir): bool {
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_link($p) || is_file($p)) {
            if (!@unlink($p)) return false;
        } elseif (is_dir($p)) {
            if (!$rrmdir($p)) return false;
        }
    }
    return @rmdir($dir);
};

return [
    'nav'    => ['group' => 'Site', 'label' => 'System', 'path' => 'system', 'perm' => 'system.run', 'sort' => 40],
    'routes' => [
        'system' => ['methods' => ['GET'], 'handler' => function (array $req) use ($health) {
            admin_render('system/index', $health($req));
        }],

        'system/migrate' => ['methods' => ['POST'], 'handler' => function (array $req) {
            try {
                $done = Migrator::up();
            } catch (Throwable $e) {
                Log::exception($e, 'apply database update');
                admin_redirect(admin_back('system'), 'The database update failed: ' . $e->getMessage(), 'bad');
            }
            cacheForget('*');
            if (function_exists('opcache_reset')) @opcache_reset();
            Migrator::markOk();
            Sitemap::invalidate();
            admin_audit('update', 'schema_migrations', null, $done ? 'Database updated: ' . implode(', ', $done) : 'Database update checked (nothing pending)');
            admin_redirect(admin_back('system'), $done ? 'Database updated (' . implode(', ', $done) . '). Public pages are open again.' : 'The database was already up to date.');
        }],

        'system/backup' => ['methods' => ['POST'], 'handler' => function (array $req) {
            @set_time_limit(300);
            try {
                $r = Backup::dumpSql(time() + 240);
            } catch (Throwable $e) {
                Log::exception($e, 'backup now');
                admin_redirect('system#backups', 'The backup failed: ' . $e->getMessage(), 'bad');
            }
            admin_audit('create', 'backup', $r['file'], 'Backup made: ' . $r['file'] . ' (' . Backup::human($r['bytes']) . ')');
            admin_redirect('system#backups', 'Backup made: ' . $r['file'] . ', ' . Backup::human($r['bytes']) . ', ' . $r['rows'] . ' rows. Download it to keep a copy off the server.');
        }],

        'system/download' => ['methods' => ['POST'], 'handler' => function (array $req) {
            Backup::stream((string)($req['post']['file'] ?? ''), Auth::id());
        }],

        'system/search' => ['methods' => ['POST'], 'handler' => function (array $req) {
            $n = Search::rebuild();
            admin_audit('update', 'search_index', null, 'Search index rebuilt (' . $n . ' entries)');
            admin_redirect('system', 'The search index is rebuilt: ' . $n . ' entries.');
        }],

        'system/cache' => ['methods' => ['POST'], 'handler' => function (array $req) {
            $n = cacheForget('*');
            if (!Migrator::pending()) Migrator::markOk();
            Sitemap::invalidate();
            if (function_exists('opcache_reset')) @opcache_reset();
            admin_audit('update', 'cache', null, 'Cache cleared (' . $n . ' files)');
            admin_redirect('system', 'The cache is cleared (' . $n . ' files). Pages rebuild it as they are visited.');
        }],

        'system/installer' => ['methods' => ['POST'], 'handler' => function (array $req) use ($rrmdir) {
            $dir = ROOT_DIR . '/install';
            if (!is_dir($dir)) admin_redirect('system', 'The installer folder is already gone.');
            $real = realpath($dir);
            if ($real === false || str_replace('\\', '/', $real) !== str_replace('\\', '/', (string)realpath(ROOT_DIR) . '/install') || is_link($dir)) {
                admin_redirect('system', 'The installer folder looks unusual, so it was not deleted. Delete it in File Manager.', 'bad');
            }
            $ok = $rrmdir($dir);
            admin_audit('delete', 'install', null, $ok ? 'Installer folder deleted' : 'Installer folder could not be fully deleted');
            admin_redirect('system', $ok ? 'The installer is deleted.' : 'Some installer files could not be deleted. Remove the install folder in File Manager.', $ok ? 'ok' : 'bad');
        }],

        'system/cron-run' => ['methods' => ['POST'], 'handler' => function (array $req) {
            $task = (string)($req['post']['task'] ?? '');
            if (!isset(Cron::TASKS[$task])) admin_redirect('system#cron', 'Unknown task.', 'bad');
            @set_time_limit(120);
            $res = Cron::runTask($task, time() + 50);
            admin_audit('status', 'cron', $task, 'Ran the ' . $task . ' task by hand: ' . mb_substr($res, 0, 150));
            admin_redirect('system#cron', 'The ' . $task . ' task ran: ' . $res, str_starts_with($res, 'error') ? 'bad' : 'ok');
        }],
    ],
];
