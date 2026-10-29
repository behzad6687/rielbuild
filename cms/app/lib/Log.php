<?php
/**
 * File logs under storage/logs/{channel}.log (spec 7.9): emails are masked
 * (j•••@gmail.com) and long hex tokens are redacted before anything is written.
 * Passwords, tokens and card data must never be passed in at all.
 */
defined('ICM') or exit;

final class Log
{
    public const MAX_BYTES = 5242880;   // rotate over 5 MB (nightly task)
    public const KEEP = 10;

    public static function path(string $channel): string
    {
        $channel = preg_match('/^[a-z0-9_-]{1,40}$/', $channel) ? $channel : 'app';
        return STORAGE_DIR . '/logs/' . $channel . '.log';
    }

    public static function write(string $channel, string $message, array $context = []): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . self::redact($message);
        if ($context) {
            $line .= ' ' . self::redact((string)json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $file = self::path($channel);
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($file, str_replace(["\r", "\n"], ' ', $line) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(Throwable $e, string $where = ''): void
    {
        self::write('error', ($where !== '' ? $where . ': ' : '') . get_class($e) . ': ' . $e->getMessage()
            . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    /** Mask emails and long hex tokens. */
    public static function redact(string $s): string
    {
        $s = preg_replace_callback(
            '/([A-Za-z0-9._%+-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            static fn(array $m): string => self::maskEmail($m[0]),
            $s
        ) ?? $s;
        return preg_replace('/\b[a-f0-9]{40,}\b/i', '[redacted]', $s) ?? $s;
    }

    /** "jane.doe@gmail.com" to "j•••@gmail.com". */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) return '•••';
        return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    /**
     * Rotate every *.log over $maxBytes: x.log becomes x.log.1, keeping $keep.
     * @return list<string> the rotated channel files
     */
    public static function rotate(int $maxBytes = self::MAX_BYTES, int $keep = self::KEEP): array
    {
        $done = [];
        foreach (glob(STORAGE_DIR . '/logs/*.log') ?: [] as $file) {
            clearstatcache(true, $file);
            if (@filesize($file) <= $maxBytes) continue;
            @unlink($file . '.' . $keep);
            for ($i = $keep - 1; $i >= 1; $i--) {
                if (is_file($file . '.' . $i)) @rename($file . '.' . $i, $file . '.' . ($i + 1));
            }
            @rename($file, $file . '.1');
            $done[] = basename($file);
        }
        return $done;
    }

    /** The last $lines lines of a channel, redacted again on the way out. */
    public static function tail(string $channel, int $lines = 200): array
    {
        $file = self::path($channel);
        if (!is_file($file)) return [];
        $size = (int)filesize($file);
        $fh = fopen($file, 'rb');
        if (!$fh) return [];
        $chunk = min($size, max(8192, $lines * 400));
        fseek($fh, -$chunk, SEEK_END);
        $data = (string)fread($fh, $chunk);
        fclose($fh);
        $rows = preg_split('/\r?\n/', rtrim($data)) ?: [];
        if ($chunk < $size) array_shift($rows);   // first row is probably partial
        return array_map([self::class, 'redact'], array_slice($rows, -$lines));
    }
}
