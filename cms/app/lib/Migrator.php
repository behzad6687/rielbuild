<?php
/**
 * Applies app/migrations/*.php in filename order and records each in
 * schema_migrations (spec 3.3). A migration file returns ['up' => callable].
 *
 * Naming: NNNN_{ws}_{what}.php, basename at most 32 characters, lowercase.
 * Number blocks per workstream keep parallel work from colliding:
 * W1 0001-0199, W2 0200s, W3 0300s, W4 0400s, W5 0500s, W6 0600s, W7 0700s,
 * W8 0800s, W9 0900s. Every migration must be safe to re-run (MySQL DDL
 * auto-commits): use Schema::createTable / addColumn / addIndex, which check first.
 */
defined('ICM') or exit;

final class Migrator
{
    public static function dir(): string
    {
        return APP_DIR . '/migrations';
    }

    /** @return list<string> every migration version on disk, sorted */
    public static function versions(): array
    {
        $out = [];
        foreach (scandir(self::dir()) ?: [] as $f) {
            if (preg_match('/^(\d{4}_[a-z0-9_]{1,27})\.php$/', $f, $m)) $out[] = $m[1];
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /** @return list<string> versions recorded in schema_migrations ([] before install) */
    public static function applied(): array
    {
        if (!Db::tableExists('schema_migrations')) return [];
        return array_map('strval', Db::column('SELECT version FROM schema_migrations ORDER BY version'));
    }

    /** @return list<string> */
    public static function pending(): array
    {
        return array_values(array_diff(self::versions(), self::applied()));
    }

    /**
     * Apply every pending migration under the 'migrate' lock.
     * $progress(string $version, mixed $result) is called after each one
     * (0001 returns the list of tables it created, for the installer).
     * @return list<string> the versions applied by this call
     */
    public static function up(?callable $progress = null): array
    {
        $lock = Db::lock('migrate', 30);
        if (!$lock) throw new RuntimeException('Another process is running migrations. Try again in a minute.');

        try {
            $all = Schema::tables();
            Schema::createTable('schema_migrations', $all['schema_migrations']);

            $done = [];
            foreach (self::pending() as $version) {
                $file = self::dir() . '/' . $version . '.php';
                $mig = require $file;
                $fn = is_array($mig) ? ($mig['up'] ?? null) : null;
                if (!is_callable($fn)) throw new RuntimeException('Migration ' . $version . ' has no callable "up".');

                // SQLite DDL is transactional; MySQL DDL auto-commits, so there it runs bare.
                $result = Db::isSqlite() ? Db::tx($fn) : $fn();

                Db::insert('schema_migrations', ['version' => $version, 'applied_at' => Time::nowUtc()]);
                Db::forgetColumns();
                Log::write('migrate', 'applied ' . $version);
                $done[] = $version;
                if ($progress) $progress($version, $result);
            }
            self::markOk();
            return $done;
        } finally {
            $lock->release();
        }
    }

    /**
     * Front-controller check (spec 1.5.1 step 2): true while any migration on
     * disk is not applied. Cached in storage/cache/schema.ok, keyed by the list
     * of files, so a code upload with a new migration invalidates it by itself.
     */
    public static function isPendingCached(): bool
    {
        $file = STORAGE_DIR . '/cache/schema.ok';
        if (is_file($file) && trim((string)@file_get_contents($file)) === self::signature()) return false;
        try {
            $pending = self::pending();
        } catch (Throwable) {
            return true;
        }
        if ($pending) return true;
        self::markOk();
        return false;
    }

    public static function markOk(): void
    {
        $dir = STORAGE_DIR . '/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/schema.ok', self::signature());
    }

    public static function clearCache(): void
    {
        @unlink(STORAGE_DIR . '/cache/schema.ok');
    }

    private static function signature(): string
    {
        return hash('sha256', implode(',', self::versions()));
    }
}
