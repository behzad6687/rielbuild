<?php
/**
 * Shared helpers for the W7b content admin modules (spec 4.1.3, 4.2):
 * request parsing, optimistic locking, CSV with the formula-injection guard,
 * image and PDF fields (through W7a's Media and Img: re-encoded, renamed,
 * EXIF dropped), the copy linter (Plain), sort order and small formatting
 * helpers. Stateless; every method is static and testable.
 */
defined('ICM') or exit;

final class AdminKit
{
    private const IDENT = '/^[a-z_][a-z0-9_]*$/';

    // ------------------------------------------------------------------
    // Request values
    // ------------------------------------------------------------------

    /** A cleaned string from $src (control characters stripped, trimmed, cut to $max). */
    public static function str(array $src, string $key, int $max = 0, bool $multiline = false): string
    {
        return Sanitizer::text($src[$key] ?? '', $multiline, $max);
    }

    /** Like str() but null when empty. */
    public static function nstr(array $src, string $key, int $max = 0, bool $multiline = false): ?string
    {
        $s = self::str($src, $key, $max, $multiline);
        return $s === '' ? null : $s;
    }

    public static function int(array $src, string $key, ?int $default = null): ?int
    {
        $v = trim((string)(is_scalar($src[$key] ?? null) ? $src[$key] : ''));
        return preg_match('/^-?\d{1,10}$/', $v) ? (int)$v : $default;
    }

    public static function flag(array $src, string $key): int
    {
        $v = $src[$key] ?? null;
        return (is_scalar($v) && in_array((string)$v, ['1', 'on', 'yes', 'true'], true)) ? 1 : 0;
    }

    /** A valid 'Y-m-d' date or null. */
    public static function date(array $src, string $key): ?string
    {
        return self::validDate(is_scalar($src[$key] ?? null) ? (string)$src[$key] : '');
    }

    public static function validDate(string $v): ?string
    {
        $v = trim($v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
    }

    /** A valid 'H:i' time or null. Accepts '9:05' and '09:05:00'. */
    public static function time(array $src, string $key): ?string
    {
        return self::validTime(is_scalar($src[$key] ?? null) ? (string)$src[$key] : '');
    }

    public static function validTime(string $v): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($v), $m)) return null;
        $h = (int)$m[1];
        $i = (int)$m[2];
        return ($h < 24 && $i < 60) ? sprintf('%02d:%02d', $h, $i) : null;
    }

    /** $v when it is one of $allowed, else $default. */
    public static function pick(mixed $v, array $allowed, string $default): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        return in_array($v, $allowed, true) ? $v : $default;
    }

    /** "149", "$1,200.50", "149.5" to cents; '' to null; nonsense to null. */
    public static function cents(mixed $v): ?int
    {
        $s = str_replace([',', '$', ' ', "\u{00a0}"], '', trim((string)(is_scalar($v) ? $v : '')));
        if ($s === '') return null;
        if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $s, $m)) return null;
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
    }

    /** Cents to an input value: 14900 gives "149", 1250 gives "12.50". */
    public static function moneyInput(?int $cents): string
    {
        if ($cents === null) return '';
        return $cents % 100 === 0 ? (string)intdiv($cents, 100) : number_format($cents / 100, 2, '.', '');
    }

    /** A list of positive integer ids from an array or a comma string. */
    public static function ids(mixed $v): array
    {
        if (is_string($v)) $v = explode(',', $v);
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $x) {
            if (is_scalar($x) && preg_match('/^\d{1,10}$/', trim((string)$x)) && (int)$x > 0) $out[] = (int)$x;
        }
        return array_values(array_unique($out));
    }

    /** The signed-in user's id from the admin request, or null. */
    public static function uid(array $req): ?int
    {
        $id = (int)($req['user']['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    public static function role(array $req): string
    {
        return (string)($req['user']['role'] ?? '');
    }

    // ------------------------------------------------------------------
    // Optimistic locking (4.1.3) and audit detail
    // ------------------------------------------------------------------

    /**
     * The refusal message when the row was saved by someone else after the
     * form was opened (the form carries the row's updated_at), else null.
     */
    public static function stale(?array $row, array $post): ?string
    {
        if (!$row || !array_key_exists('updated_at', $row) || !array_key_exists('updated_at', $post)) return null;
        $sent = trim((string)$post['updated_at']);
        $now = trim((string)($row['updated_at'] ?? ''));
        if ($sent === $now) return null;
        $when = Time::toLocal($now);
        return 'Someone else saved this at ' . ($when ? $when->format('g:i a') : 'a moment ago') . '. Reload to see their change.';
    }

    /** Hidden input carrying updated_at for the lock. */
    public static function lockField(?array $row): string
    {
        return '<input type="hidden" name="updated_at" value="' . e($row['updated_at'] ?? '') . '">';
    }

    /** Changed fields for the audit detail: {"field": [old, new]}. */
    public static function diff(array $old, array $new): array
    {
        if (class_exists('Audit')) return Audit::diff($old, $new);
        $out = [];
        foreach ($new as $k => $v) {
            if (in_array($k, ['updated_at', 'updated_by', 'created_at'], true) || (string)($old[$k] ?? '') === (string)($v ?? '')) continue;
            $out[$k] = [$old[$k] ?? null, $v];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // CSV (4.2.9): UTF-8 with a BOM, formula-injection guard on every cell
    // ------------------------------------------------------------------

    /** A cell starting with = + - @, a tab or a carriage return is prefixed with a quote. */
    public static function csvCell(mixed $v): string
    {
        $s = is_bool($v) ? ($v ? '1' : '0') : (string)($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) $s = "'" . $s;
        return $s;
    }

    public static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $c) {
            $c = self::csvCell($c);
            $out[] = preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }
        return implode(',', $out) . "\r\n";
    }

    /** The whole file as a string, BOM first. */
    public static function csvString(array $header, iterable $rows): string
    {
        $s = "\xEF\xBB\xBF" . self::csvLine($header);
        foreach ($rows as $r) $s .= self::csvLine(array_values((array)$r));
        return $s;
    }

    public static function sendCsv(string $filename, array $header, iterable $rows): never
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        echo self::csvString($header, $rows);
        exit;
    }

    /**
     * Read an uploaded CSV (comma or semicolon, optional BOM). Header names are
     * lowercased and trimmed. Returns ['header' => [...], 'rows' => [[name => value]], 'error' => ?string].
     */
    public static function readCsv(string $file, int $maxRows = 5000): array
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) return ['header' => [], 'rows' => [], 'error' => 'The file could not be read.'];
        $first = (string)fgets($fh);
        if (str_starts_with($first, "\xEF\xBB\xBF")) $first = substr($first, 3);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), str_getcsv(rtrim($first, "\r\n"), $delim, '"', ''));
        $rows = [];
        $n = 0;
        while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($cells === [null] || $cells === ['']) continue;
            if (++$n > $maxRows) {
                fclose($fh);
                return ['header' => $header, 'rows' => $rows, 'error' => 'The file has more than ' . $maxRows . ' rows. Split it and import each part.'];
            }
            $row = [];
            foreach ($header as $i => $h) {
                $v = (string)($cells[$i] ?? '');
                if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                if (preg_match("/^'[=+\\-@\t\r]/", $v)) $v = substr($v, 1);   // undo our own export guard
                $row[$h] = trim($v);
            }
            $rows[] = $row;
        }
        fclose($fh);
        return ['header' => $header, 'rows' => $rows, 'error' => null];
    }

    /**
     * Keep an import preview between the preview screen and "Import" without the
     * session: a JSON file in {private}/imports/, named by a random token the form
     * carries. Files older than a day are removed. Returns the token.
     */
    public static function stashPut(string $kind, array $data): string
    {
        if (!preg_match('/^[a-z]{2,16}$/', $kind)) throw new InvalidArgumentException('Bad stash kind');
        $dir = privatePath('imports');
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        foreach (glob($dir . '/stash-*.json') ?: [] as $old) {
            if (@filemtime($old) < time() - 86400) @unlink($old);
        }
        $token = bin2hex(random_bytes(16));
        file_put_contents($dir . '/stash-' . $kind . '-' . $token . '.json', (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($dir . '/stash-' . $kind . '-' . $token . '.json', 0600);
        return $token;
    }

    /** Read (and by default delete) a stashed preview; null when the token is unknown or malformed. */
    public static function stashGet(string $kind, string $token, bool $delete = true): ?array
    {
        if (!preg_match('/^[a-z]{2,16}$/', $kind) || !preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $file = privatePath('imports/stash-' . $kind . '-' . $token . '.json');
        if (!is_file($file)) return null;
        $data = json_decode((string)file_get_contents($file), true);
        if ($delete) @unlink($file);
        return is_array($data) ? $data : null;
    }

    /** A usable uploaded file from $req['files'][$key], or null when none was chosen. */
    public static function file(array $req, string $key): ?array
    {
        $f = $req['files'][$key] ?? null;
        if (!is_array($f) || is_array($f['error'] ?? null) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        return $f;
    }

    // ------------------------------------------------------------------
    // Images and PDFs (spec 7.6) through W7a's Media library
    // ------------------------------------------------------------------

    /**
     * Store one uploaded image. $opts: alt, caption, by (user id), focal [x, y].
     * Returns ['ok' => true, 'id' => int] or ['ok' => false, 'error' => plain message].
     */
    public static function storeImage(?array $file, string $nameHint = 'image', array $opts = []): array
    {
        if (!$file) return ['ok' => false, 'error' => 'Choose a file first.'];
        [$fx, $fy] = self::focal($opts['focal'] ?? null);
        $meta = ['name' => substr(slugify($nameHint) ?: 'image', 0, 60), 'focal_x' => $fx, 'focal_y' => $fy];
        if (($opts['alt'] ?? '') !== '') $meta['alt'] = (string)$opts['alt'];
        if (($opts['caption'] ?? '') !== '') $meta['caption'] = (string)$opts['caption'];
        try {
            $row = Media::uploadImage($file, $meta, $opts['by'] ?? null);
            return ['ok' => true, 'id' => (int)$row['id']];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => self::uploadMessage($e, 'The image could not be saved. Please try again.')];
        }
    }

    /** Store one uploaded PDF, private by default (certificates). Returns ['ok', 'id' | 'error']. */
    public static function storePdf(?array $file, string $nameHint = 'document', bool $private = true, ?int $by = null): array
    {
        if (!$file) return ['ok' => false, 'error' => 'Choose a file first.'];
        try {
            $row = Media::uploadPdf($file, ['private' => $private, 'name' => substr(slugify($nameHint) ?: 'document', 0, 60)], $by);
            return ['ok' => true, 'id' => (int)$row['id']];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => self::uploadMessage($e, 'The PDF could not be saved. Please try again.')];
        }
    }

    /** Plain upload messages are shown; database and unexpected errors are logged and replaced. */
    private static function uploadMessage(Throwable $e, string $fallback): string
    {
        if ($e instanceof PDOException || !($e instanceof InvalidArgumentException || $e instanceof DomainException || $e instanceof RuntimeException)) {
            Log::exception($e, 'admin upload');
            return $fallback;
        }
        return $e->getMessage();
    }

    /**
     * One image field from an admin form: {prefix}[id|alt|remove|pick|focal_x|focal_y]
     * plus the file input {prefix}_file (AdminUi::imageField). Upload wins, then a
     * media library number, then the current image. A changed alt text or focal
     * point is saved on the media row. Returns ['id' => ?int, 'alt' => string, 'error' => ?string, 'changed' => bool].
     */
    public static function imageFromForm(array $req, string $prefix, ?int $current, string $nameHint, bool $storeAlt = true): array
    {
        $in = is_array($req['post'][$prefix] ?? null) ? $req['post'][$prefix] : [];
        $alt = Sanitizer::text($in['alt'] ?? '', false, 300);
        $file = self::file($req, $prefix . '_file');
        $current = $current ?: null;
        $id = $current;
        $err = null;
        if (!empty($in['remove'])) {
            $id = null;
        } elseif ($file) {
            $r = self::storeImage($file, $nameHint, ['alt' => $alt, 'by' => self::uid($req)]);
            if ($r['ok']) $id = (int)$r['id'];
            else $err = (string)$r['error'];
        } elseif (($pick = self::int($in, 'pick')) && $pick !== $current) {
            if (Db::value("SELECT 1 FROM media WHERE id = ? AND kind = 'image' AND is_private = 0", [$pick])) $id = $pick;
            else $err = 'There is no image with the number ' . $pick . ' in the media library.';
        }
        if ($id) {
            $m = Db::one('SELECT alt, focal_x, focal_y FROM media WHERE id = ?', [$id]);
            $change = [];
            if ($storeAlt && $alt !== '' && $m && (string)$m['alt'] !== $alt) $change['alt'] = $alt;
            if ($id === $current && $m && isset($in['focal_x'], $in['focal_y']) && is_numeric($in['focal_x']) && is_numeric($in['focal_y'])) {
                [$fx, $fy] = self::focal([$in['focal_x'], $in['focal_y']]);
                if (abs((float)$m['focal_x'] - $fx) > 0.001 || abs((float)$m['focal_y'] - $fy) > 0.001) {
                    $change['focal_x'] = $fx;
                    $change['focal_y'] = $fy;
                }
            }
            if ($change) {
                try {
                    Media::update($id, $change, self::uid($req));
                } catch (Throwable $e) {
                    $err ??= self::uploadMessage($e, 'The image details could not be saved.');
                }
            }
            if ($alt === '' && $m) $alt = (string)($m['alt'] ?? '');
        }
        return ['id' => $id, 'alt' => $alt, 'error' => $err, 'changed' => $id !== $current];
    }

    /** Remove a private file (a replaced certificate PDF) once nothing references it. */
    public static function dropPrivateFile(?int $mediaId, ?int $by = null): void
    {
        if (!$mediaId || !Db::value('SELECT 1 FROM media WHERE id = ? AND is_private = 1', [$mediaId])) return;
        try {
            Media::delete($mediaId, $by);
        } catch (Throwable $e) {
            Log::exception($e, 'private file delete');
        }
    }

    /** Small preview URL for an image. */
    public static function thumb(?int $mediaId): string
    {
        $m = $mediaId ? mediaRow($mediaId) : null;
        return ($m && ($m['kind'] ?? '') === 'image') ? mediaUrl($m, 'thumb') : '';
    }

    /** @return array{0:float,1:float} a focal point clamped to 0..1 (default 0.5, 0.4) */
    public static function focal(mixed $f): array
    {
        $x = is_array($f) && is_numeric($f[0] ?? null) ? (float)$f[0] : 0.5;
        $y = is_array($f) && is_numeric($f[1] ?? null) ? (float)$f[1] : 0.4;
        return [round(max(0, min(1, $x)), 3), round(max(0, min(1, $y)), 3)];
    }

    // ------------------------------------------------------------------
    // Copy linter (4.1.3, 11.6): warnings only, saving still works
    // ------------------------------------------------------------------

    /** Warnings for one or more texts (HTML allowed), from Plain::problems(). */
    public static function lint(?string ...$texts): array
    {
        $found = [];
        foreach ($texts as $t) {
            if (trim((string)$t) === '') continue;
            $found = array_merge($found, class_exists('Plain') ? Plain::problems((string)$t) : self::lintBasic((string)$t));
        }
        return array_values(array_unique($found));
    }

    private static function lintBasic(string $t): array
    {
        $bare = Sanitizer::plain($t);
        $out = [];
        if (preg_match('/[\x{2014}\x{2013}]/u', $bare)) $out[] = 'Has an em dash or en dash. Use a comma or a hyphen instead.';
        foreach (['leverage', 'seamless', 'empower', 'unlock', 'robust', 'actionable', 'data-driven', 'solutions', 'testament', 'landscape', 'delve', 'elevate'] as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '/i', $bare)) $out[] = 'Uses "' . $w . '", which is on the house style list.';
        }
        return $out;
    }

    /** The "Fix dashes" button: em and en dashes become commas (hyphens in number ranges). */
    public static function fixDashes(string $s): string
    {
        if (class_exists('Plain')) return Plain::fixDashes($s);
        $s = preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', $s) ?? $s;
        return preg_replace('/,\s*([.!?;:])/', '$1', $s) ?? $s;
    }

    /** Sanitised rich text, with dashes fixed first when the Fix dashes button was used. */
    public static function rich(array $post, string $key, bool $fix = false): string
    {
        $html = (string)(is_scalar($post[$key] ?? null) ? $post[$key] : '');
        if ($fix) $html = self::fixDashes($html);
        return Sanitizer::html($html);
    }

    // ------------------------------------------------------------------
    // Dates and labels
    // ------------------------------------------------------------------

    /** A UTC DATETIME as the value of a Toronto <input type="datetime-local">. */
    public static function localInput(?string $utc): string
    {
        $d = Time::toLocal($utc);
        return $d ? $d->format('Y-m-d\TH:i') : '';
    }

    /** A Toronto datetime-local value ('2026-09-13T17:15') to UTC, or null. */
    public static function localToUtc(?string $local): ?string
    {
        $local = trim((string)$local);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{1,2}:\d{2})/', $local, $m) || !self::validDate($m[1]) || !self::validTime($m[2])) return null;
        return Time::toUtc($m[1] . ' ' . self::validTime($m[2]) . ':00');
    }

    /** "today", "yesterday", "12 days ago" (a DATE or a UTC DATETIME). */
    public static function ago(?string $value): string
    {
        if (!$value) return 'never';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : (Time::toLocal($value)?->format('Y-m-d') ?? '');
        if ($date === '') return 'never';
        $days = (int)((strtotime(Time::today() . ' 00:00:00 UTC') - strtotime($date . ' 00:00:00 UTC')) / 86400);
        return match (true) {
            $days <= 0  => 'today',
            $days === 1 => 'yesterday',
            default     => $days . ' days ago',
        };
    }

    /** "Sep 13, 2026, 3:42 pm" in Toronto time from UTC. */
    public static function stamp(?string $utc): string
    {
        $d = Time::toLocal($utc);
        return $d ? $d->format('M j, Y, g:i a') : '';
    }

    /** Status words (design brief 2.5 plus the admin-only states). */
    public static function statusWord(string $s): string
    {
        return [
            'open' => 'Open', 'fully_booked' => 'Fully booked', 'closed' => 'Closed', 'opens_on' => 'Opens on a date',
            'password' => 'Director approval', 'coming_soon' => 'Coming soon', 'hidden' => 'Hidden',
            'available' => 'Available', 'published' => 'Published', 'draft' => 'Draft', 'scheduled' => 'Scheduled',
            'new' => 'New', 'in_progress' => 'In progress', 'handled' => 'Handled', 'offered' => 'Offered',
            'registered' => 'Registered', 'withdrawn' => 'Withdrawn', 'spam' => 'Spam', 'archived' => 'Archived',
            'valid' => 'Valid', 'revoked' => 'Revoked',
        ][$s] ?? ucfirst(str_replace('_', ' ', $s));
    }

    /** Admin status label: the shell's tag plus a shape glyph (design brief 2.5: status by shape, never colour alone). */
    public static function chip(string $status, ?string $opensOn = null): string
    {
        $s = (string)preg_replace('/[^a-z_]/', '', $status);
        $word = $s === 'opens_on' && $opensOn ? 'Opens ' . Time::long($opensOn) : self::statusWord($s);
        $tone = match ($s) {
            'open', 'available', 'published', 'valid', 'registered', 'handled' => ' tag--ok',
            'fully_booked', 'offered', 'scheduled', 'in_progress' => ' tag--warn',
            'revoked', 'spam' => ' tag--bad',
            'new' => ' tag--new',
            default => '',
        };
        return '<span class="tag' . $tone . ' status status--' . e($s) . '"><span class="status__glyph" aria-hidden="true"></span>' . e($word) . '</span>';
    }

    // ------------------------------------------------------------------
    // Links, paths and lookups
    // ------------------------------------------------------------------

    /**
     * A menu or redirect target: a site path ('/x/', '/x/?a=1#b') or an http(s) URL.
     * Returns the cleaned value or null.
     */
    public static function target(string $to, bool $allowExternal = true): ?string
    {
        $to = trim($to);
        if ($to === '' || preg_match('/[\x00-\x20<>"\\\\]/', $to)) return null;
        if (preg_match('#^/(?!/)#', $to)) return mb_substr($to, 0, 255);
        if ($allowExternal && preg_match('#^https?://[^/\s]+#i', $to) && filter_var($to, FILTER_VALIDATE_URL)) return mb_substr($to, 0, 255);
        return null;
    }

    /** Every page as [path => title], for pickers and datalists. */
    public static function pageOptions(bool $publishedOnly = false): array
    {
        $rows = Db::all('SELECT path, title, status FROM pages' . ($publishedOnly ? " WHERE status = 'published'" : '') . ' ORDER BY path');
        $out = [];
        foreach ($rows as $r) $out[(string)$r['path']] = (string)$r['title'] . ($r['status'] !== 'published' ? ' (draft)' : '');
        return $out;
    }

    /** A slug unique in $table.$col (suffix -2, -3 ...), cut to $max characters. */
    public static function uniqueSlug(string $table, string $slug, ?int $exceptId = null, string $col = 'slug', int $max = 80): string
    {
        self::ident($table);
        self::ident($col);
        $base = trim(substr(trim(slugify($slug), '-'), 0, $max), '-') ?: 'item';
        $try = $base;
        for ($i = 2; $i < 500; $i++) {
            $hit = Db::value('SELECT id FROM ' . $table . ' WHERE ' . $col . ' = ?' . ($exceptId ? ' AND id <> ?' : ''), $exceptId ? [$try, $exceptId] : [$try]);
            if (!$hit) return $try;
            $suffix = '-' . $i;
            $try = rtrim(substr($base, 0, $max - strlen($suffix)), '-') . $suffix;
        }
        return substr($base, 0, $max - 7) . '-' . bin2hex(random_bytes(3));
    }

    /** Comma or newline separated email list: [valid list, invalid list]. */
    public static function emails(string $list): array
    {
        $ok = $bad = [];
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $e) {
            $e = trim($e);
            if ($e === '') continue;
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $ok[] = strtolower($e);
            else $bad[] = $e;
        }
        return [array_values(array_unique($ok)), $bad];
    }

    // ------------------------------------------------------------------
    // Sort order
    // ------------------------------------------------------------------

    /**
     * Move a row one place up ($dir = -1) or down (+1) among the rows that
     * share $groupCol's value, then renumber the group 10, 20, 30...
     */
    public static function move(string $table, int $id, int $dir, ?string $groupCol = null): bool
    {
        self::ident($table);
        if ($groupCol !== null) self::ident($groupCol);
        $row = Db::one('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
        if (!$row) return false;
        $where = '1 = 1';
        $args = [];
        if ($groupCol !== null) {
            if ($row[$groupCol] === null) {
                $where = $groupCol . ' IS NULL';
            } else {
                $where = $groupCol . ' = ?';
                $args[] = $row[$groupCol];
            }
        }
        $ids = array_map('intval', Db::column('SELECT id FROM ' . $table . ' WHERE ' . $where . ' ORDER BY sort, id', $args));
        $pos = array_search($id, $ids, true);
        if ($pos === false) return false;
        $to = $pos + ($dir < 0 ? -1 : 1);
        if ($to < 0 || $to >= count($ids)) return false;
        [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
        self::reorder($table, $ids);
        return true;
    }

    /** Write sort = 10, 20, 30 ... in the given id order. */
    public static function reorder(string $table, array $ids): int
    {
        self::ident($table);
        $n = 0;
        Db::tx(function () use ($table, $ids, &$n) {
            foreach (array_values(self::ids($ids)) as $i => $id) {
                $n += Db::run('UPDATE ' . $table . ' SET sort = ? WHERE id = ?', [($i + 1) * 10, $id])->rowCount();
            }
        });
        return $n;
    }

    private static function ident(string $name): void
    {
        if (!preg_match(self::IDENT, $name)) throw new InvalidArgumentException('Bad identifier');
    }
}
