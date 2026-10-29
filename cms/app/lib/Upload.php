<?php
/**
 * Document uploads from public forms (spec 5.2.8 and 7.6). Owner: W4.
 * Images are W7's Img class; this class handles resumes and other documents.
 *
 * A document is accepted only when all of these hold:
 *   - the size is within the limit;
 *   - finfo's MIME sniff is on the allowlist for the file's extension;
 *   - the extension is one of the allowed kinds (pdf, doc, docx);
 *   - the magic bytes match: PDF starts "%PDF-", DOC is an OLE file,
 *     DOCX is a zip that contains [Content_Types].xml and a word/ part.
 * It is stored at {private}/{dir}/YYYY/MM/{random32}.{ext} with mode 0600 and
 * its SHA-256 recorded. The original name is kept only for display; nothing
 * uploaded keeps its own name.
 */
defined('ICM') or exit;

final class Upload
{
    public const MB = 1048576;

    /** Canonical MIME per kind, and the finfo results accepted for it. */
    public const DOC_TYPES = [
        'pdf'  => [
            'mime'  => 'application/pdf',
            'finfo' => ['application/pdf'],
        ],
        'doc'  => [
            'mime'  => 'application/msword',
            'finfo' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        ],
        'docx' => [
            'mime'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            // libmagic reports a Word 2007+ file as the OOXML type, or as a plain zip
            // when [Content_Types].xml is not the first entry; the structure check below decides.
            'finfo' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ],
    ];

    /** Extra kinds a caller can opt into with 'types' (v3 tasks, 2026-10-19: screenshots and spreadsheets). */
    public const EXTRA_TYPES = [
        'png'  => ['mime' => 'image/png', 'finfo' => ['image/png']],
        'jpg'  => ['mime' => 'image/jpeg', 'finfo' => ['image/jpeg']],
        'jpeg' => ['mime' => 'image/jpeg', 'finfo' => ['image/jpeg']],
        'webp' => ['mime' => 'image/webp', 'finfo' => ['image/webp']],
        'gif'  => ['mime' => 'image/gif', 'finfo' => ['image/gif']],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'finfo' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
    ];

    public const MSG_FAILED = 'That file did not upload. Please try again.';

    /**
     * Validate and store one uploaded document from $_FILES[...].
     * $opts: max_bytes (default 5 MB), types (default ['pdf','doc','docx']),
     *        dir (default 'resumes'), trusted_tmp (CLI tests only: skip is_uploaded_file).
     * Returns ['ok' => true, 'path' (relative to the private root), 'original_name', 'mime', 'bytes', 'sha256']
     *      or ['ok' => false, 'none' => true] when no file was sent,
     *      or ['ok' => false, 'error' => message].
     */
    public static function document(array $file, array $opts = []): array
    {
        $max = max(1, (int)($opts['max_bytes'] ?? 5 * self::MB));
        $kinds = self::DOC_TYPES + self::EXTRA_TYPES;
        $types = array_values(array_intersect((array)($opts['types'] ?? ['pdf', 'doc', 'docx']), array_keys($kinds)));
        $dir = trim((string)($opts['dir'] ?? 'resumes'), '/');
        if (!preg_match('#^[a-z0-9_-]+$#', $dir)) throw new InvalidArgumentException('Bad upload folder');
        $trusted = !empty($opts['trusted_tmp']) && PHP_SAPI === 'cli';

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'none' => true];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return self::fail(self::tooLargeMessage($max));
        if ($err !== UPLOAD_ERR_OK) return self::fail(self::MSG_FAILED);

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || (!$trusted && !is_uploaded_file($tmp))) return self::fail(self::MSG_FAILED);
        $bytes = (int)filesize($tmp);
        if ($bytes <= 0) return self::fail(self::MSG_FAILED);
        if ($bytes > $max) return self::fail(self::tooLargeMessage($max));

        $name = self::displayName((string)($file['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $wrongType = self::typeMessage($types);
        if (!in_array($ext, $types, true)) return self::fail($wrongType);

        $sniffed = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp));
        if (!in_array($sniffed, $kinds[$ext]['finfo'], true)) return self::fail($wrongType);
        if (!self::magicOk($tmp, $ext)) return self::fail($wrongType);

        $rel = $dir . '/' . Time::nowLocal()->format('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $abs = privatePath($rel);
        $folder = dirname($abs);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) return self::fail(self::MSG_FAILED);
        $moved = $trusted ? @copy($tmp, $abs) : @move_uploaded_file($tmp, $abs);
        if (!$moved) return self::fail(self::MSG_FAILED);
        @chmod($abs, 0600);

        return [
            'ok'            => true,
            'path'          => $rel,
            'original_name' => $name,
            'mime'          => $kinds[$ext]['mime'],
            'bytes'         => $bytes,
            'sha256'        => (string)hash_file('sha256', $abs),
        ];
    }

    /** Delete a stored private file by its relative path (a failed insert, a purge). */
    public static function remove(string $rel): void
    {
        if ($rel === '' || str_contains($rel, '..')) return;
        $abs = privatePath($rel);
        if (is_file($abs)) @unlink($abs);
    }

    /** Magic-byte check for a kind. */
    public static function magicOk(string $file, string $ext): bool
    {
        $head = (string)@file_get_contents($file, false, null, 0, 12);
        return match ($ext) {
            'pdf'  => str_starts_with($head, '%PDF-'),
            'png'  => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'gif'  => str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'),
            'xlsx' => str_starts_with($head, "PK\x03\x04") && (bool)array_filter(self::zipNames($file) ?? [], static fn(string $n): bool => str_starts_with($n, 'xl/')),
            'doc'  => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'docx' => str_starts_with($head, "PK\x03\x04") && self::docxStructureOk($file),
            default => false,
        };
    }

    /** A DOCX is a zip with [Content_Types].xml and at least one word/ part. */
    public static function docxStructureOk(string $file): bool
    {
        $names = self::zipNames($file);
        if ($names === null) return false;
        $hasTypes = in_array('[Content_Types].xml', $names, true);
        $hasWord = (bool)array_filter($names, static fn(string $n): bool => str_starts_with($n, 'word/'));
        return $hasTypes && $hasWord;
    }

    /** Entry names of a zip file, or null when it is not a readable zip. */
    public static function zipNames(string $file): ?array
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::RDONLY) !== true) return null;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (is_string($n)) $names[] = $n;
            }
            $zip->close();
            return $names;
        }
        // Without ext-zip: read the central directory records (signature PK\x01\x02).
        $data = (string)@file_get_contents($file);
        if ($data === '') return null;
        $names = [];
        $pos = 0;
        while (($pos = strpos($data, "PK\x01\x02", $pos)) !== false && $pos + 46 <= strlen($data)) {
            $len = unpack('v', substr($data, $pos + 28, 2))[1] ?? 0;
            $names[] = substr($data, $pos + 46, $len);
            $pos += 46 + $len;
        }
        return $names ?: null;
    }

    // -----------------------------------------------------------------
    // Request size (spec 2.5): a POST over post_max_size arrives with empty
    // $_POST and $_FILES, which would otherwise look like a token failure.
    // -----------------------------------------------------------------

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') return 0;
        $n = (float)$v;
        return (int)match (strtolower(substr($v, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    public static function postMaxBytes(): int
    {
        return self::iniBytes((string)ini_get('post_max_size'));
    }

    /** True when this request's body is larger than post_max_size. */
    public static function requestTooLarge(): bool
    {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = self::postMaxBytes();
        return $max > 0 && $len > $max;
    }

    /** "That file is too large (limit 5 MB)." */
    public static function tooLargeMessage(?int $limitBytes = null): string
    {
        $limit = $limitBytes ?? self::postMaxBytes();
        $mb = max(1, (int)floor($limit / self::MB));
        return 'That file is too large (limit ' . $mb . ' MB).';
    }

    // -----------------------------------------------------------------

    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }

    /** "Please upload a PDF, DOC or DOCX file." */
    private static function typeMessage(array $types): string
    {
        $up = array_map('strtoupper', $types);
        $last = array_pop($up);
        return 'Please upload a ' . ($up ? implode(', ', $up) . ' or ' : '') . $last . ' file.';
    }

    /** The original name for display only: no path, no control characters, at most 200 characters. */
    private static function displayName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = Sanitizer::text($name, false, 200);
        return $name === '' ? 'file' : $name;
    }
}
