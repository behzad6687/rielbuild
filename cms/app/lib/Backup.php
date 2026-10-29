<?php
/**
 * The in-app backup (spec 7.8), adapted from NHF _dev/export-mysql.php.
 *
 * dumpSql() streams every table, in chunks, into
 * {private}/backups/site-YYYYMMDD-HHMM.sql.gz and keeps the latest 14. On
 * MySQL/MariaDB the DDL comes from SHOW CREATE TABLE, so the file imports in
 * phpMyAdmin as it is (restore is manual, DEPLOY.md). On SQLite (development)
 * it writes SQLite DDL instead, for local checks.
 *
 * Secret settings stay encrypted in the dump (a restore onto a new app_secret
 * needs them entered again), config.local.php is never included, and the data
 * of rate_limits and admin_remember_tokens is left out (volatile, and a restore
 * must not bring back signed-out devices). On Sundays an uploads manifest
 * (paths, sizes, SHA-256) is written beside the dump.
 */
defined('ICM') or exit;

final class Backup
{
    public const KEEP = 14;
    public const SKIP_DATA = ['rate_limits', 'admin_remember_tokens'];
    private const CHUNK = 500;
    private const NAME = '/^site-(\d{8})-(\d{4,6})\.sql\.gz$/';

    public static function dir(): string
    {
        $d = privatePath('backups');
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    /**
     * Write one dump. $deadline (unix time) stops a cron run cleanly.
     * @return array{file:string,bytes:int,tables:int,rows:int,seconds:float}
     */
    public static function dumpSql(?int $deadline = null): array
    {
        $lock = Db::lock('backup', 5);
        if (!$lock) throw new RuntimeException('Another backup is running. Try again in a minute.');
        $t0 = microtime(true);
        $dir = self::dir();
        if (!self::writable($dir)) throw new RuntimeException('The private backups folder is missing or not writable.');

        $stamp = Time::nowLocal()->format('Ymd-Hi');
        $name = 'site-' . $stamp . '.sql.gz';
        if (is_file($dir . '/' . $name)) $name = 'site-' . Time::nowLocal()->format('Ymd-His') . '.sql.gz';
        $final = $dir . '/' . $name;
        $tmp = $final . '.part';

        $gz = gzopen($tmp, 'wb6');
        if (!$gz) throw new RuntimeException('Could not write the backup file.');
        $rows = 0;
        $tables = self::tables();
        try {
            $mysql = Db::isMysql();
            self::w($gz, "-- ICM Tennis database backup\n-- Generated " . gmdate('Y-m-d H:i:s') . " UTC (" . Time::nowLocal()->format('Y-m-d H:i') . " Toronto)\n"
                . '-- Source: ' . Db::serverVersion() . ', app ' . appVersion() . "\n"
                . "-- Secret settings are stored encrypted. After a restore onto a new install, enter the mail passwords, API keys,\n"
                . "-- the webhook secret and the DKIM key again in Admin > Settings.\n\n");
            if ($mysql) {
                self::w($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");
            } else {
                self::w($gz, "PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n");
            }
            foreach ($tables as $t) {
                if ($deadline !== null && time() >= $deadline) throw new RuntimeException('The backup stopped at the time limit.');
                self::w($gz, self::ddl($t) . "\n");
                if (in_array($t, self::SKIP_DATA, true)) {
                    self::w($gz, "-- $t: data left out on purpose\n\n");
                    continue;
                }
                $rows += self::dumpRows($gz, $t, $deadline);
                self::w($gz, "\n");
            }
            self::w($gz, $mysql ? "SET FOREIGN_KEY_CHECKS = 1;\n" : "COMMIT;\nPRAGMA foreign_keys = ON;\n");
            self::w($gz, "-- end of backup\n");
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($tmp);
            Db::setSetting('backup.last_error', mb_substr($e->getMessage(), 0, 250));
            throw $e;
        }
        gzclose($gz);
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
            throw new RuntimeException('Could not finish the backup file.');
        }
        @chmod($final, 0600);
        $bytes = (int)filesize($final);
        self::prune();
        Db::setSetting('backup.last_at', Time::nowUtc());
        Db::setSetting('backup.last_file', $name);
        Db::setSetting('backup.last_bytes', (string)$bytes);
        Db::setSetting('backup.last_error', '');
        return ['file' => $name, 'bytes' => $bytes, 'tables' => count($tables), 'rows' => $rows, 'seconds' => round(microtime(true) - $t0, 2)];
    }

    /** The nightly task's step (cron nightly, W1): a dump, plus the uploads manifest on Sundays. */
    public static function nightly(?int $deadline = null): string
    {
        $r = self::dumpSql($deadline);
        $out = $r['file'] . ', ' . self::human($r['bytes']) . ', ' . $r['rows'] . ' rows';
        if (Time::nowLocal()->format('N') === '7') {
            try {
                $n = self::uploadsManifest(preg_replace('/\.sql\.gz$/', '', $r['file']) . '.uploads-manifest.json');
                $out .= ', uploads manifest (' . $n . ' files)';
            } catch (Throwable $e) {
                Log::exception($e, 'uploads manifest');
                $out .= ', uploads manifest failed';
            }
        }
        Audit::log(null, 'backup', 'backup', $r['file'], 'Nightly backup ' . $out);
        return $out;
    }

    /** Write the list of uploaded files with sizes and SHA-256. Returns the file count. */
    public static function uploadsManifest(string $name): int
    {
        if (!preg_match('/^site-[0-9-]+\.uploads-manifest\.json$/', $name)) throw new InvalidArgumentException('Bad manifest name');
        $root = ROOT_DIR . '/uploads';
        $files = [];
        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) continue;
                $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                $files[] = ['path' => $rel, 'bytes' => $f->getSize(), 'sha256' => hash_file('sha256', $f->getPathname())];
            }
        }
        usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
        $json = json_encode(['generated_utc' => Time::nowUtc(), 'count' => count($files), 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $file = self::dir() . '/' . $name;
        file_put_contents($file, (string)$json, LOCK_EX);
        @chmod($file, 0600);
        return count($files);
    }

    /**
     * The kept backups, newest first.
     * @return list<array{file:string,bytes:int,at:string,manifest:?string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/site-*.sql.gz') ?: [] as $f) {
            $b = basename($f);
            if (!preg_match(self::NAME, $b)) continue;
            $m = preg_replace('/\.sql\.gz$/', '', $b) . '.uploads-manifest.json';
            $out[] = [
                'file'     => $b,
                'bytes'    => (int)filesize($f),
                'at'       => gmdate('Y-m-d H:i:s', (int)filemtime($f)),
                'manifest' => is_file(self::dir() . '/' . $m) ? $m : null,
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
        return $out;
    }

    /** Stream one kept backup (or its manifest) as a download, and record it. */
    public static function stream(string $name, ?int $by = null): never
    {
        $ok = false;
        foreach (self::all() as $b) {
            if ($b['file'] === $name || $b['manifest'] === $name) $ok = true;
        }
        $file = self::dir() . '/' . basename($name);
        if (!$ok || !is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            exit;
        }
        Db::setSetting('backup.last_download_at', Time::nowUtc());
        Audit::log($by, 'export', 'backup', $name, 'Backup downloaded: ' . $name);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . (str_ends_with($name, '.gz') ? 'application/gzip' : 'application/json'));
        header('Content-Disposition: attachment; filename="' . basename($name) . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($file);
        exit;
    }

    /** Keep the newest KEEP dumps (and their manifests). @return int files removed */
    public static function prune(): int
    {
        $kept = array_slice(self::all(), 0, self::KEEP);
        $keepNames = [];
        foreach ($kept as $b) {
            $keepNames[$b['file']] = true;
            if ($b['manifest']) $keepNames[$b['manifest']] = true;
        }
        $n = 0;
        foreach (glob(self::dir() . '/site-*') ?: [] as $f) {
            $b = basename($f);
            if (str_ends_with($b, '.part') && filemtime($f) > time() - 3600) continue;
            if (!isset($keepNames[$b]) && preg_match('/^site-[0-9-]+\.(sql\.gz|uploads-manifest\.json|sql\.gz\.part)$/', $b) && @unlink($f)) $n++;
        }
        return $n;
    }

    /**
     * Can PHP create a file in $dir? Tested with a real write, because is_writable()
     * is unreliable for folders on some hosts and on Windows.
     */
    public static function writable(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $probe = rtrim($dir, '/\\') . '/.w7probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, '') === false) return false;
        @unlink($probe);
        return true;
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024) . ' KB';
        return $bytes . ' bytes';
    }

    // -----------------------------------------------------------------

    /** Every table in the database: the schema tables first, then any added later. */
    private static function tables(): array
    {
        $all = Db::isMysql()
            ? Db::column("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
            : Db::column("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $all = array_values(array_filter(array_map('strval', $all), fn($t) => (bool)preg_match('/^[a-z_][a-z0-9_]*$/', $t)));
        $known = array_values(array_intersect(Schema::tableNames(), $all));
        $extra = array_values(array_diff($all, $known));
        sort($extra);
        return array_merge($known, $extra);
    }

    private static function ddl(string $t): string
    {
        if (Db::isMysql()) {
            $row = Db::one('SHOW CREATE TABLE `' . $t . '`');
            $create = (string)($row['Create Table'] ?? array_values($row ?? [])[1] ?? '');
            return "DROP TABLE IF EXISTS `$t`;\n" . $create . ";\n";
        }
        $out = 'DROP TABLE IF EXISTS "' . $t . "\";\n";
        $out .= (string)Db::value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) . ";\n";
        foreach (Db::column("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$t]) as $idx) {
            $out .= $idx . ";\n";
        }
        return $out;
    }

    /** INSERT statements in chunks. Returns the row count. */
    private static function dumpRows($gz, string $t, ?int $deadline): int
    {
        $cols = Db::columns($t);
        if (!$cols) return 0;
        $q = Db::isMysql() ? '`' : '"';
        $colList = implode(', ', array_map(fn($c) => $q . $c . $q, $cols));
        $pdo = Db::pdo();
        $count = 0;
        $byId = in_array('id', $cols, true);
        $pk = $byId ? ['id'] : self::orderColumns($t, $cols);
        $order = implode(', ', $pk);
        $last = 0;
        $offset = 0;
        while (true) {
            if ($deadline !== null && time() >= $deadline) throw new RuntimeException('The backup stopped at the time limit.');
            $rows = $byId
                ? Db::all("SELECT * FROM $t WHERE id > ? ORDER BY id LIMIT " . self::CHUNK, [$last])
                : Db::all("SELECT * FROM $t ORDER BY $order LIMIT " . self::CHUNK . ' OFFSET ' . $offset);
            if (!$rows) break;
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = '(' . implode(',', array_map(static function ($v) use ($pdo): string {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string)$v;
                    return (string)$pdo->quote((string)$v);
                }, array_values($r))) . ')';
                if ($byId) $last = (int)$r['id'];
            }
            self::w($gz, 'INSERT INTO ' . $q . $t . $q . ' (' . $colList . ") VALUES\n" . implode(",\n", $vals) . ";\n");
            $count += count($rows);
            $offset += count($rows);
            if (count($rows) < self::CHUNK) break;
        }
        return $count;
    }

    /** A stable order for tables without an id column (their primary key). */
    private static function orderColumns(string $t, array $cols): array
    {
        $def = Schema::tables()[$t] ?? null;
        if ($def && !empty($def['pk'])) return $def['pk'];
        return [$cols[0]];
    }

    private static function w($gz, string $s): void
    {
        if (gzwrite($gz, $s) === false) throw new RuntimeException('Could not write the backup file (disk full?).');
    }
}
