<?php
/**
 * The optional task runner behind app/cron/run.php (a cron job every few minutes). The site works
 * without it: scheduled posts are also published on page views. Schedules are in Toronto time and
 * can be overridden per task with the setting cron.schedule.{task}.
 *
 * A task is app/cron/tasks/{name}.php returning a callable:
 *   return function (array $ctx): string { ...; return 'published 2 posts'; };
 * $ctx: deadline (unix time to stop by), now (UTC), today (Toronto date).
 * A missing task file is skipped quietly (its workstream has not shipped it).
 */
defined('ICM') or exit;

final class Cron
{
    /** Task => schedule: every:N (minutes), hourly, daily@HH:MM (Toronto). */
    public const TASKS = [
        'scheduled'    => 'every:5',       // scheduled blog posts go live (also done lazily on page views)
        'housekeeping' => 'hourly',        // deferred emails, old rate-limit rows
        'nightly'      => 'daily@03:10',   // a database backup into storage/backups
    ];

    /** Run every due task under the 'cron' lock, stopping after $maxSeconds of wall time. */
    public static function run(int $maxSeconds = 50, string $via = 'cli'): array
    {
        $start = time();
        $lock = Db::lock('cron', 0);
        if (!$lock) {
            Log::write('cron', 'skipped: another run holds the lock');
            return ['locked' => true];
        }
        try {
            Db::setSetting('cron.php', PHP_VERSION);
            Db::setSetting('cron.sapi', PHP_SAPI);
            Db::setSetting('cron.binary', (string)PHP_BINARY);
            Db::setSetting('cron.via', $via);
            Db::setSetting('cron.last_run', Time::nowUtc());
            $ran = [];
            foreach (self::TASKS as $name => $default) {
                if (time() - $start >= $maxSeconds) {
                    Log::write('cron', 'wall-time cap reached before ' . $name);
                    break;
                }
                if (!is_file(APP_DIR . '/cron/tasks/' . $name . '.php')) continue;   // not shipped yet
                $schedule = (string)Db::setting('cron.schedule.' . $name, $default);
                if (!self::isDue($name, $schedule)) continue;
                $ran[$name] = self::runTask($name, $start + $maxSeconds);
            }
            return ['ran' => $ran];
        } finally {
            $lock->release();
        }
    }

    /** Run one task now, whatever its schedule (tests, "Run now" in System). */
    public static function runTask(string $name, ?int $deadline = null): string
    {
        if (!preg_match('/^[a-z_]{1,40}$/', $name)) throw new InvalidArgumentException('Bad task name');
        $file = APP_DIR . '/cron/tasks/' . $name . '.php';
        if (!is_file($file)) return 'missing';
        $t0 = microtime(true);
        try {
            $fn = require $file;
            if (!is_callable($fn)) throw new RuntimeException('task file must return a callable');
            $res = (string)($fn(['deadline' => $deadline ?? time() + 50, 'now' => Time::nowUtc(), 'today' => Time::today()]) ?? 'ok');
            Db::setSetting('cron.error.' . $name, '');
        } catch (Throwable $e) {
            $res = 'error: ' . $e->getMessage();
            Log::exception($e, 'cron ' . $name);
            Db::setSetting('cron.error.' . $name, mb_substr($e->getMessage(), 0, 250));
        }
        Db::setSetting('cron.last.' . $name, Time::nowUtc());
        Db::setSetting('cron.result.' . $name, mb_substr($res, 0, 250));
        Log::write('cron', sprintf('%s: %s (%.2fs)', $name, $res, microtime(true) - $t0));
        return $res;
    }

    /** Is the task due at $now (unix time, default Time::ts())? */
    public static function isDue(string $name, string $schedule, ?int $now = null): bool
    {
        $now ??= Time::ts();
        $lastRaw = Db::setting('cron.last.' . $name);
        $last = $lastRaw ? (int)strtotime($lastRaw . ' UTC') : 0;

        if (preg_match('/^every:(\d+)$/', $schedule, $m)) {
            $n = max(1, (int)$m[1]) * 60;
            return $last === 0 || intdiv($now, $n) !== intdiv($last, $n);
        }
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(Time::tz());
        $lastLocal = $last ? (new DateTimeImmutable('@' . $last))->setTimezone(Time::tz()) : null;
        if ($schedule === 'hourly') {
            return !$lastLocal || $lastLocal->format('Y-m-d H') !== $local->format('Y-m-d H');
        }
        if (preg_match('/^daily@(\d{2}):(\d{2})$/', $schedule, $m)) {
            $due = $local->setTime((int)$m[1], (int)$m[2]);
            if ($local < $due) return false;
            return !$lastLocal || $lastLocal < $due;
        }
        return false;
    }

    /** Null when this PHP can run the tasks, else the reason (spec 2.5, logged by run.php). */
    public static function checkRuntime(): ?string
    {
        if (PHP_VERSION_ID < 80100) return 'PHP 8.1 or newer is required; cron runs PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ')';
        if (Db::driver() === 'mysql' && !extension_loaded('pdo_mysql')) return 'the pdo_mysql extension is missing for cron PHP ' . PHP_VERSION;
        if (Db::driver() === 'sqlite' && !extension_loaded('pdo_sqlite')) return 'the pdo_sqlite extension is missing';
        return null;
    }

    /** The command to paste into the hosting control panel's cron jobs (every 5 minutes). */
    public static function commands(): array
    {
        $path = str_replace('\\', '/', APP_DIR . '/cron/run.php');
        return ['php ' . $path, '/usr/local/bin/php ' . $path];
    }

    /** A system audit entry (user_id NULL). */
    public static function audit(string $action, string $entity, ?string $entityId, string $summary): void
    {
        Db::insert('audit_log', [
            'user_id'    => null,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'summary'    => mb_substr($summary, 0, 255),
            'created_at' => Time::nowUtc(),
        ]);
    }
}
