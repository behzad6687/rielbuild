<?php
/**
 * First-time setup, shared by the web installer (/install/) and the command line (php app/cli.php install):
 * check the server, write app/config.local.php (site address, secrets, the SQLite database file),
 * create the tables, write the starter content and the owner's account.
 */
defined('ICM') or exit;

final class Installer
{
    /** True once the config exists and an admin account does too (the installer then refuses to run). */
    public static function installed(): bool
    {
        if (!is_file(APP_DIR . '/config.local.php')) return false;
        try {
            return Db::tableExists('admin_users') && (int)Db::value('SELECT COUNT(*) FROM admin_users', [], 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array{0:string,1:bool,2:string}> [check, passed, what to do] */
    public static function requirements(): array
    {
        $writable = static function (string $dir): bool {
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            return is_dir($dir) && is_writable($dir);
        };
        return [
            ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, 'Choose PHP 8.1, 8.2 or 8.3 for this site in the hosting control panel. This server runs PHP ' . PHP_VERSION . '.'],
            ['SQLite database (pdo_sqlite)', extension_loaded('pdo_sqlite'), 'Turn on the pdo_sqlite extension in the PHP settings.'],
            ['Image processing (GD with WebP)', extension_loaded('gd') && function_exists('imagewebp'), 'Turn on the gd extension in the PHP settings.'],
            ['mbstring', extension_loaded('mbstring'), 'Turn on the mbstring extension in the PHP settings.'],
            ['The app folder can be written (config)', is_writable(APP_DIR), 'Allow the web server to write to the app folder (permissions 755).'],
            ['The storage folder can be written', $writable(STORAGE_DIR), 'Allow the web server to write to the storage folder (permissions 755).'],
            ['The uploads folder can be written', $writable(ROOT_DIR . '/uploads'), 'Allow the web server to write to the uploads folder (permissions 755).'],
        ];
    }

    /** Write app/config.local.php. $baseUrl is scheme://host (no path). */
    public static function writeConfig(string $baseUrl, string $liveUrl = ''): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $baseUrl)) throw new InvalidArgumentException('The site address must look like https://example.com (no folder after it).');
        $cfg = [
            'base_url'   => $baseUrl,
            'live_url'   => rtrim($liveUrl, '/'),
            'db'         => ['driver' => 'sqlite', 'sqlite' => 'storage/site.sqlite'],
            'app_secret' => bin2hex(random_bytes(32)),
            'cron_token' => bin2hex(random_bytes(32)),
            'debug'      => false,
        ];
        $src = "<?php defined('ICM') or exit;\nreturn " . self::export($cfg) . ";\n";
        $file = APP_DIR . '/config.local.php';
        if (@file_put_contents($file, $src, LOCK_EX) === false) throw new RuntimeException('Could not write app/config.local.php. Check the folder permissions.');
        @chmod($file, 0600);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    }

    /**
     * Create the folders, the tables and the starter content, then the owner's account.
     * Runs in the request that wrote the config, so the config is loaded here first.
     */
    public static function run(string $name, string $email, string $password, ?callable $say = null): int
    {
        $say ??= static fn(string $s) => null;
        $GLOBALS['cfg'] = require APP_DIR . '/config.php';
        self::resolveSqlitePath();
        Db::disconnect();
        foreach (['logs', 'sessions', 'cache', 'private', 'mail-outbox', 'backups'] as $d) {
            if (!is_dir(STORAGE_DIR . '/' . $d)) @mkdir(STORAGE_DIR . '/' . $d, 0775, true);
        }
        self::protect(STORAGE_DIR);
        $say('tables: ' . implode(', ', Migrator::up()));
        require_once APP_DIR . '/seed/RbSeed.php';
        RbSeed::run($say);
        $id = Auth::createUser($email, $name, 'owner', $password);
        $say('owner account: ' . $email);
        Migrator::markOk();
        return $id;
    }

    /** config 'db.sqlite' may be relative to the site folder (the installer writes it so); make it absolute. */
    public static function resolveSqlitePath(): void
    {
        $f = (string)cfg('db.sqlite');
        if ($f !== '' && $f[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $f)) $GLOBALS['cfg']['db']['sqlite'] = ROOT_DIR . '/' . ltrim($f, '/');
    }

    /** A deny-all .htaccess (Apache and LiteSpeed) in a private folder. */
    public static function protect(string $dir): void
    {
        $f = rtrim($dir, '/') . '/.htaccess';
        if (!is_file($f)) @file_put_contents($f, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }

    private static function export(array $a, int $depth = 1): string
    {
        $pad = str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $val = is_array($v) ? self::export($v, $depth + 1) : (is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true));
            $out .= $pad . var_export($k, true) . ' => ' . $val . ",\n";
        }
        return $out . str_repeat('    ', $depth - 1) . ']';
    }
}
