<?php
/**
 * PDO wrapper that speaks MySQL/MariaDB (production) and SQLite (local work)
 * through one API, so the same code and schema run in both (spec 1.8).
 *
 * Connects lazily from cfg('db') on first use. Identifiers passed to insert(),
 * update(), delete() and upsert() are validated, never quoted from input.
 * Every value is a bound parameter; ints bind as PARAM_INT (safe for LIMIT).
 */
defined('ICM') or exit;

final class Db
{
    public const LIKE_ESCAPE = " ESCAPE '!'";
    private const IDENT = '/^[a-z_][a-z0-9_]*$/';

    private static ?PDO $pdo = null;
    private static string $driver = '';
    private static int $txDepth = 0;
    /** @var array<string,array{0:?string,1:int}>|null */
    private static ?array $settings = null;
    private static array $columns = [];

    // -------------------------------------------------------------------
    // Connection
    // -------------------------------------------------------------------

    /** Connect (or reconnect with an explicit config). */
    public static function connect(?array $cfg = null): PDO
    {
        if ($cfg === null && self::$pdo instanceof PDO) return self::$pdo;
        $cfg ??= (array)cfg('db', []);
        self::disconnect();

        $driver = ($cfg['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        if ($driver === 'sqlite') {
            $file = (string)($cfg['sqlite'] ?? STORAGE_DIR . '/icm.sqlite');
            $dir = dirname($file);
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $pdo = new PDO('sqlite:' . $file, null, null, $opt);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } else {
            if (empty($cfg['name'])) throw new RuntimeException('The database is not configured yet.');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'] ?? 'localhost',
                (int)($cfg['port'] ?? 3306) ?: 3306,
                $cfg['name'],
                $cfg['charset'] ?? 'utf8mb4'
            );
            $pdo = new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), $opt);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }

        self::$driver = $driver;
        return self::$pdo = $pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$driver = '';
        self::$txDepth = 0;
        self::$settings = null;
        self::$columns = [];
    }

    public static function connected(): bool
    {
        return self::$pdo instanceof PDO;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::connect();
    }

    /** 'mysql' or 'sqlite' (the configured driver when not yet connected). */
    public static function driver(): string
    {
        if (self::$driver !== '') return self::$driver;
        return cfg('db.driver') === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    /** "MariaDB 11.8.x", "MySQL 8.0.x" or "SQLite 3.x". */
    public static function serverVersion(): string
    {
        if (self::isSqlite()) return 'SQLite ' . (string)self::value('SELECT sqlite_version()');
        $v = (string)self::value('SELECT VERSION()');
        return (stripos($v, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', $v);
    }

    // -------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------

    /**
     * Prepare, bind and execute. $args is a list for "?" placeholders or a map
     * for ":named" ones. ints and bools bind as integers, null as NULL.
     */
    public static function run(string $sql, array $args = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        if ($args) {
            $list = array_is_list($args);
            foreach ($args as $k => $v) {
                $param = $list ? $k + 1 : (str_starts_with((string)$k, ':') ? (string)$k : ':' . $k);
                if (is_bool($v)) $v = (int)$v;
                $type = match (true) {
                    is_int($v)  => PDO::PARAM_INT,
                    $v === null => PDO::PARAM_NULL,
                    default     => PDO::PARAM_STR,
                };
                $st->bindValue($param, (is_int($v) || $v === null) ? $v : (string)$v, $type);
            }
        }
        $st->execute();
        return $st;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $args = []): ?array
    {
        $row = self::run($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    /** First column of the first row, or $default. */
    public static function value(string $sql, array $args = [], mixed $default = null): mixed
    {
        $v = self::run($sql, $args)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** First column of every row. */
    public static function column(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /** Two-column result as [col1 => col2]. */
    public static function pairs(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Insert one row; returns the new auto-increment id (0 for non-integer keys). */
    public static function insert(string $table, array $row): int
    {
        self::ident($table);
        $cols = array_keys($row);
        array_walk($cols, [self::class, 'ident']);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    /**
     * Update rows. $where uses "?" with a list $args, or ":name" with a map $args.
     * Db::update('courses', ['status' => 'open'], 'id = ?', [$id]);
     */
    public static function update(string $table, array $data, string $where, array $args = []): int
    {
        self::ident($table);
        if (!$data) return 0;
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'ident']);
        if (!$args || array_is_list($args)) {
            $set = implode(', ', array_map(fn($c) => $c . ' = ?', $cols));
            $params = array_merge(array_values($data), $args);
        } else {
            $set = implode(', ', array_map(fn($c) => $c . ' = :__s_' . $c, $cols));
            $params = $args;
            foreach ($data as $c => $v) $params['__s_' . $c] = $v;
        }
        return self::run('UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $where, $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $args = []): int
    {
        self::ident($table);
        return self::run('DELETE FROM ' . $table . ' WHERE ' . $where, $args)->rowCount();
    }

    /**
     * Insert or update on a unique key (driver aware). $unique names the
     * columns of ONE unique index or primary key (SQLite needs the exact
     * target; MySQL fires on any unique key). $update defaults to every
     * non-unique column. Returns the row's id when the table has an `id`
     * column, else 0.
     */
    public static function upsert(string $table, array $unique, array $row, ?array $update = null): int
    {
        self::ident($table);
        $cols = array_keys($row);
        array_walk($cols, [self::class, 'ident']);
        array_walk($unique, [self::class, 'ident']);
        foreach ($unique as $u) {
            if (!array_key_exists($u, $row)) throw new InvalidArgumentException("upsert: unique column $u missing from row");
        }
        $update ??= array_values(array_diff($cols, $unique));
        array_walk($update, [self::class, 'ident']);

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        if (self::isMysql()) {
            $set = $update
                ? implode(', ', array_map(fn($c) => $c . ' = VALUES(' . $c . ')', $update))
                : $unique[0] . ' = ' . $unique[0];
            $sql .= ' ON DUPLICATE KEY UPDATE ' . $set;
        } else {
            $sql .= ' ON CONFLICT(' . implode(', ', $unique) . ') DO '
                . ($update ? 'UPDATE SET ' . implode(', ', array_map(fn($c) => $c . ' = excluded.' . $c, $update)) : 'NOTHING');
        }
        self::run($sql, array_values($row));

        if (!self::hasColumn($table, 'id')) return 0;
        $where = implode(' AND ', array_map(fn($c) => $c . ' = ?', $unique));
        return (int)self::value('SELECT id FROM ' . $table . ' WHERE ' . $where, array_map(fn($c) => $row[$c], $unique), 0);
    }

    /** INSERT that silently skips a duplicate key. Returns the new id or 0. */
    public static function insertOrIgnore(string $table, array $row): int
    {
        self::ident($table);
        $cols = array_keys($row);
        array_walk($cols, [self::class, 'ident']);
        $sql = self::ignoreVerb() . ' INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $n = self::run($sql, array_values($row))->rowCount();
        return $n > 0 ? (int)self::pdo()->lastInsertId() : 0;
    }

    /** 'INSERT IGNORE' or 'INSERT OR IGNORE', for INSERT ... SELECT statements. */
    public static function ignoreVerb(): string
    {
        return self::isMysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    }

    /** Placeholders for IN (...): "?, ?, ?". An empty list gives "NULL" (matches nothing). */
    public static function in(array $values): string
    {
        return $values ? implode(', ', array_fill(0, count($values), '?')) : 'NULL';
    }

    /** Escape a value for LIKE; append Db::LIKE_ESCAPE after the pattern placeholder. */
    public static function like(string $s): string
    {
        return strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * Page through a SELECT. Returns rows, total, page, per and pages.
     * $sql must not carry its own LIMIT.
     */
    public static function paginate(string $sql, array $args, int $page, int $per = 20): array
    {
        $per = max(1, min(500, $per));
        $total = (int)self::value('SELECT COUNT(*) FROM (' . $sql . ') pg__count', $args, 0);
        $pages = max(1, (int)ceil($total / $per));
        $page = max(1, min($page, $pages));
        $rows = self::all($sql . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'pages' => $pages];
    }

    /**
     * Run $fn inside one transaction; nested calls join the outer one.
     * Rolls back and rethrows on any Throwable. MySQL DDL auto-commits, so
     * migrations must still be safe to re-run.
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$txDepth > 0) {
            self::$txDepth++;
            try {
                return $fn();
            } finally {
                self::$txDepth--;
            }
        }
        $pdo->beginTransaction();
        self::$txDepth = 1;
        try {
            $result = $fn();
            if ($pdo->inTransaction()) $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            self::$txDepth = 0;
        }
    }

    public static function nowUtc(): string
    {
        return Time::nowUtc();
    }

    // -------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------

    public static function tableExists(string $table): bool
    {
        if (!preg_match(self::IDENT, $table)) return false;
        return self::isSqlite()
            ? (bool)self::value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
            : (bool)self::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
    }

    /** @return list<string> column names, cached per request */
    public static function columns(string $table): array
    {
        self::ident($table);
        if (!isset(self::$columns[$table])) {
            self::$columns[$table] = self::isSqlite()
                ? array_column(self::all('PRAGMA table_info(' . $table . ')'), 'name')
                : self::column('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$table]);
        }
        return self::$columns[$table];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, self::columns($table), true);
    }

    public static function indexExists(string $table, string $index): bool
    {
        return self::isSqlite()
            ? (bool)self::value("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?", [$index])
            : (bool)self::value('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1', [$table, $index]);
    }

    /** Drop the column cache after DDL. */
    public static function forgetColumns(): void
    {
        self::$columns = [];
    }

    // -------------------------------------------------------------------
    // Locks (spec 1.8): one flock implementation for cron (CLI) and web
    // -------------------------------------------------------------------

    /**
     * Exclusive lock on storage/cache/locks/{name}.lock, retried every 100 ms
     * for up to $waitSeconds. Returns null when it could not be taken.
     * KEEP the returned object in a variable: the lock is released by
     * Lock::release(), when the object is destroyed, or at process exit.
     */
    public static function lock(string $name, int $waitSeconds = 0): ?Lock
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $name)) throw new InvalidArgumentException('Bad lock name');
        $dir = STORAGE_DIR . '/cache/locks';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/' . $name . '.lock';
        $fh = @fopen($path, 'c');
        if (!$fh) return null;
        $deadline = microtime(true) + max(0, $waitSeconds);
        while (true) {
            if (flock($fh, LOCK_EX | LOCK_NB)) return new Lock($fh, $path);
            if (microtime(true) >= $deadline) {
                fclose($fh);
                return null;
            }
            usleep(100000);
        }
    }

    // -------------------------------------------------------------------
    // Settings (dotted keys, see _dev/CONTRACTS.md)
    // -------------------------------------------------------------------

    private static function loadSettings(): void
    {
        if (self::$settings !== null) return;
        self::$settings = [];
        try {
            foreach (self::all('SELECT skey, svalue, is_secret FROM settings') as $r) {
                self::$settings[(string)$r['skey']] = [$r['svalue'] === null ? null : (string)$r['svalue'], (int)$r['is_secret']];
            }
        } catch (Throwable) {
            // Not installed yet, or no database: every setting falls back to its default.
        }
    }

    /** A non-secret setting; $default when missing, NULL or ''. Secret keys always give $default. */
    public static function setting(string $key, mixed $default = null): mixed
    {
        self::loadSettings();
        $row = self::$settings[$key] ?? null;
        if ($row === null || $row[1] === 1 || $row[0] === null || $row[0] === '') return $default;
        return $row[0];
    }

    public static function settingInt(string $key, int $default = 0): int
    {
        $v = self::setting($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function settingBool(string $key, bool $default = false): bool
    {
        $v = self::setting($key);
        return $v === null ? $default : in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
    }

    /** JSON-valued setting, decoded. */
    public static function settingArray(string $key, array $default = []): array
    {
        $raw = self::setting($key);
        if (!$raw) return $default;
        $out = json_decode((string)$raw, true);
        return is_array($out) ? $out : $default;
    }

    /** Every non-secret setting as [key => value]. */
    public static function settingsAll(): array
    {
        self::loadSettings();
        $out = [];
        foreach (self::$settings as $k => [$v, $secret]) {
            if ($secret !== 1) $out[$k] = $v;
        }
        return $out;
    }

    /** Store a non-secret setting. Arrays are stored as JSON. */
    public static function setSetting(string $key, mixed $value, ?int $by = null): void
    {
        self::settingKey($key);
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_bool($value)) $value = $value ? '1' : '0';
        $value = $value === null ? null : (string)$value;
        self::upsert('settings', ['skey'], [
            'skey' => $key, 'svalue' => $value, 'is_secret' => 0, 'updated_at' => Time::nowUtc(), 'updated_by' => $by,
        ]);
        self::loadSettings();
        self::$settings[$key] = [$value, 0];
    }

    /** Decrypted secret setting, or null. */
    public static function secret(string $key): ?string
    {
        self::loadSettings();
        $row = self::$settings[$key] ?? null;
        if ($row === null || $row[1] !== 1 || $row[0] === null || $row[0] === '') return null;
        return self::unseal($row[0], 'settings');
    }

    public static function hasSecret(string $key): bool
    {
        self::loadSettings();
        $row = self::$settings[$key] ?? null;
        return $row !== null && $row[1] === 1 && (string)$row[0] !== '';
    }

    /**
     * Store a secret setting, encrypted (spec 1.3). null or '' clears it.
     * The admin's "blank keeps the stored value" rule is the caller's job.
     */
    public static function setSecret(string $key, ?string $plain, ?int $by = null): void
    {
        self::settingKey($key);
        $sealed = ($plain === null || $plain === '') ? null : self::seal($plain, 'settings');
        self::upsert('settings', ['skey'], [
            'skey' => $key, 'svalue' => $sealed, 'is_secret' => 1, 'updated_at' => Time::nowUtc(), 'updated_by' => $by,
        ]);
        self::loadSettings();
        self::$settings[$key] = [$sealed, 1];
    }

    /** Drop the per-request settings cache (after bulk writes by another process). */
    public static function flushSettings(): void
    {
        self::$settings = null;
    }

    // -------------------------------------------------------------------
    // Sealed values: libsodium secretbox, keyed by sha256(app_secret|purpose)
    // -------------------------------------------------------------------

    /**
     * Encrypt a value. Uses libsodium crypto_secretbox ("s1:" prefix), as
     * production requires. Where sodium is missing (the local portable PHP),
     * falls back to AES-256-GCM ("g1:" prefix); both decrypt through unseal().
     */
    public static function seal(string $plain, string $purpose = 'settings'): string
    {
        $key = self::sealKey($purpose);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new RuntimeException('Encryption failed');
        return 'g1:' . base64_encode($iv . $tag . $ct);
    }

    public static function unseal(?string $sealed, string $purpose = 'settings'): ?string
    {
        if ($sealed === null || $sealed === '') return null;
        $key = self::sealKey($purpose);
        $raw = base64_decode(substr($sealed, 3), true);
        if ($raw === false) return null;
        if (str_starts_with($sealed, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $out = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
            return $out === false ? null : $out;
        }
        if (str_starts_with($sealed, 'g1:') && strlen($raw) > 28) {
            $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $out === false ? null : $out;
        }
        return null;
    }

    private static function sealKey(string $purpose): string
    {
        $secret = (string)cfg('app_secret');
        if ($secret === '') throw new RuntimeException('app_secret is not set');
        return hash('sha256', $secret . '|' . $purpose, true);
    }

    // -------------------------------------------------------------------

    private static function ident(string $name): void
    {
        if (!preg_match(self::IDENT, $name)) throw new InvalidArgumentException('Invalid SQL identifier: ' . substr($name, 0, 40));
    }

    private static function settingKey(string $key): void
    {
        if (!preg_match('/^[a-z0-9_.-]{1,191}$/', $key)) throw new InvalidArgumentException('Invalid setting key');
    }
}

/** An held file lock from Db::lock(). */
final class Lock
{
    /** @param resource $fh */
    public function __construct(private mixed $fh, public readonly string $path)
    {
    }

    public function release(): void
    {
        if (is_resource($this->fh)) {
            flock($this->fh, LOCK_UN);
            fclose($this->fh);
        }
        $this->fh = null;
    }

    public function held(): bool
    {
        return is_resource($this->fh);
    }

    public function __destruct()
    {
        $this->release();
    }
}
