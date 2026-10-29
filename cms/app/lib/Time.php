<?php
/**
 * Time rules (spec 0.2): DATETIME columns store UTC, DATE columns store the
 * local calendar date, and display is always America/Toronto. PHP runs in
 * UTC (bootstrap), so nothing here depends on the server's timezone.
 *
 * Tests may fix the clock with Time::setNow('2026-09-13 16:00:00') (UTC), or,
 * in debug mode only, with the ICM_NOW environment variable.
 */
defined('ICM') or exit;

final class Time
{
    public const TZ = 'America/Toronto';

    private static ?int $fixed = null;
    private static ?DateTimeZone $tz = null;

    /** Fix "now" for tests (a UTC 'Y-m-d H:i:s' string), or null to release. */
    public static function setNow(?string $utc): void
    {
        self::$fixed = $utc === null ? null : (int)strtotime($utc . ' UTC');
    }

    /** Current unix time (honours setNow and, in debug, ICM_NOW). */
    public static function ts(): int
    {
        if (self::$fixed !== null) return self::$fixed;
        $env = getenv('ICM_NOW');
        if ($env && cfg('debug')) {
            $t = strtotime($env . ' UTC');
            if ($t !== false) return $t;
        }
        return time();
    }

    public static function tz(): DateTimeZone
    {
        return self::$tz ??= new DateTimeZone(self::TZ);
    }

    /** 'Y-m-d H:i:s' in UTC, for DATETIME columns. */
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s', self::ts());
    }

    /** UTC 'Y-m-d H:i:s' $seconds ago (negative for the future). */
    public static function utcAgo(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', self::ts() - $seconds);
    }

    public static function nowLocal(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . self::ts()))->setTimezone(self::tz());
    }

    /** Today's date in Toronto, 'Y-m-d'. Compare DATE columns with this. */
    public static function today(): string
    {
        return self::nowLocal()->format('Y-m-d');
    }

    /** The current Toronto time of day, 'H:i'. */
    public static function localClock(): string
    {
        return self::nowLocal()->format('H:i');
    }

    /** A UTC DATETIME string as a Toronto DateTimeImmutable. */
    public static function toLocal(?string $utc): ?DateTimeImmutable
    {
        if (!$utc) return null;
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::tz());
        } catch (Throwable) {
            return null;
        }
    }

    /** A Toronto local date-time ('Y-m-d H:i[:s]') as a UTC 'Y-m-d H:i:s' string. */
    public static function toUtc(string $local): string
    {
        $d = new DateTimeImmutable($local, self::tz());
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Canadian long form: "September 13, 2026". A 'Y-m-d' DATE is shown as is;
     * anything longer is a UTC DATETIME shown in Toronto time. With $withTime:
     * "September 13, 2026, 5:15 pm".
     */
    public static function long(?string $value, bool $withTime = false): string
    {
        if (!$value) return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::tz());
            return $d ? $d->format('F j, Y') : '';
        }
        $d = self::toLocal($value);
        if (!$d) return '';
        return $d->format('F j, Y') . ($withTime ? ', ' . $d->format('g:i a') : '');
    }

    /** '17:15' to '5:15 pm'. */
    public static function clock(?string $hhmm): string
    {
        if (!$hhmm || !preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) return '';
        $h = (int)$m[1];
        return (($h % 12) ?: 12) . ':' . $m[2] . ' ' . ($h < 12 ? 'am' : 'pm');
    }

    /** ISO 8601 with the Toronto offset, from a UTC DATETIME (JSON-LD, feeds). */
    public static function iso(?string $utc): string
    {
        $d = self::toLocal($utc);
        return $d ? $d->format('c') : '';
    }

    /** ISO 8601 with the Toronto offset for a local DATE plus an optional 'H:i'. */
    public static function isoLocal(string $date, ?string $hhmm = null): string
    {
        try {
            $d = new DateTimeImmutable($date . ' ' . ($hhmm ?: '00:00'), self::tz());
        } catch (Throwable) {
            return '';
        }
        return $hhmm ? $d->format('c') : $d->format('Y-m-d');
    }

    /** Add days to a 'Y-m-d' date. */
    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, self::tz()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** ISO weekday of a 'Y-m-d' date: 1 = Monday ... 7 = Sunday. */
    public static function isoWeekday(string $date): int
    {
        return (int)(new DateTimeImmutable($date, self::tz()))->format('N');
    }
}
