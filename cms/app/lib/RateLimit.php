<?php
/**
 * Fixed-window rate limits backed by the rate_limits table (spec 5.1).
 * Buckets: form:{type}, login:ip, cert:ip, cert:all, unsub:ip, search:ip,
 * confirm:ip, csp:ip ... (32 characters at most). Keys are HMAC-hashed.
 * The key is usually clientIp() (REMOTE_ADDR only) or a normalised email.
 */
defined('ICM') or exit;

final class RateLimit
{
    private static int $retryAfter = 0;

    /**
     * Count one hit and report whether the caller is still within $max hits per
     * $window seconds. Over the limit: answer 429 with "Too many tries. Please
     * wait a few minutes or call us." (the caller renders it).
     */
    public static function hit(string $bucket, string $key, int $max, int $window): bool
    {
        self::bucket($bucket);
        $window = max(1, $window);
        $now = Time::ts();
        $start = $now - ($now % $window);
        $kh = self::keyHash($key);
        $sql = Db::isMysql()
            ? 'INSERT INTO rate_limits (bucket, key_hash, window_start, hits) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1'
            : 'INSERT INTO rate_limits (bucket, key_hash, window_start, hits) VALUES (?, ?, ?, 1) ON CONFLICT(bucket, key_hash, window_start) DO UPDATE SET hits = hits + 1';
        Db::run($sql, [$bucket, $kh, $start]);
        $hits = (int)Db::value('SELECT hits FROM rate_limits WHERE bucket = ? AND key_hash = ? AND window_start = ?', [$bucket, $kh, $start], 0);
        self::$retryAfter = $start + $window - $now;
        return $hits <= $max;
    }

    /** Hits so far in the current window, without counting one. */
    public static function peek(string $bucket, string $key, int $window): int
    {
        self::bucket($bucket);
        $now = Time::ts();
        $start = $now - ($now % max(1, $window));
        return (int)Db::value('SELECT hits FROM rate_limits WHERE bucket = ? AND key_hash = ? AND window_start = ?', [$bucket, self::keyHash($key), $start], 0);
    }

    /** Seconds until the window of the last hit() resets (for Retry-After). */
    public static function retryAfter(): int
    {
        return max(1, self::$retryAfter);
    }

    public static function clear(string $bucket, string $key): void
    {
        self::bucket($bucket);
        Db::run('DELETE FROM rate_limits WHERE bucket = ? AND key_hash = ?', [$bucket, self::keyHash($key)]);
    }

    /** Housekeeping: delete windows older than $olderThan seconds. */
    public static function prune(int $olderThan = 86400): int
    {
        return Db::run('DELETE FROM rate_limits WHERE window_start < ?', [Time::ts() - $olderThan])->rowCount();
    }

    /**
     * File-backed limiter for code that runs before a database exists
     * (the installer's install-key check, spec 2.1).
     */
    public static function fileHit(string $bucket, string $key, int $max, int $window): bool
    {
        $dir = STORAGE_DIR . '/cache/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/' . hash('sha256', $bucket . '|' . $key) . '.json';
        $fh = @fopen($file, 'c+');
        if (!$fh) return true;
        try {
            flock($fh, LOCK_EX);
            $data = json_decode((string)stream_get_contents($fh), true);
            $now = Time::ts();
            if (!is_array($data) || ($data['start'] ?? 0) + $window <= $now) $data = ['start' => $now, 'hits' => 0];
            $data['hits']++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)json_encode($data));
            self::$retryAfter = $data['start'] + $window - $now;
            return $data['hits'] <= $max;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function keyHash(string $key): string
    {
        return hash_hmac('sha256', $key, (string)cfg('app_secret') ?: 'icm-no-secret');
    }

    private static function bucket(string $bucket): void
    {
        if (!preg_match('/^[a-z0-9_:.-]{1,32}$/', $bucket)) throw new InvalidArgumentException('Bad rate-limit bucket');
    }
}
