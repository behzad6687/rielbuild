<?php
/**
 * The media library (spec 4.2.13, 7.6): uploads, the media table, where an
 * item is used, and the delete guard (an item in use cannot be deleted).
 *
 * Images go through Img (re-encoded, renamed, EXIF dropped). PDFs pass the
 * %PDF- magic bytes and finfo, then are renamed: public documents to
 * uploads/docs/{slug}.pdf, private ones (certificates) to {private}/certificates/
 * with an access token. Nothing uploaded keeps its own name.
 */
defined('ICM') or exit;

final class Media
{
    public const PDF_MAX = 10485760;   // 10 MB
    public const VIDEO_MAX = 8388608;  // 8 MB (round 2, the "video" kind: MP4 only, played as uploaded)
    public const PER_PAGE = 48;

    /**
     * FK columns that point at media.id: table => [column, label, admin route, title column].
     * Every entry is read only while its table and column exist (self::hasCol, Db::hasColumn), so the
     * round 2 entries stay inert until their migrations add the columns (V2-PLAN 3, step 0.4).
     */
    private const REFS = [
        ['pages', 'og_media_id', 'Page (social image)', 'pages/edit', 'title'],
        ['posts', 'featured_media_id', 'Blog post', 'posts/edit', 'title'],
        ['projects', 'media_id', 'Project', 'projects/edit', 'title'],
        ['menu_items', 'media_id', 'Menu item', 'menus', 'label'],
    ];

    /** Settings whose value is a media id: a photo they name cannot be deleted. */
    public const SETTING_REFS = ['header.logo_media_id', 'footer.logo_media_id'];

    /** Rich-text and JSON columns that may name an image by its path or id. */
    private const TEXT_COLS = [
        ['posts', 'body_html', 'Blog post', 'posts/edit', 'title'],
        ['pages', 'intro_html', 'Page', 'pages/edit', 'title'],
        ['pages', 'body_html', 'Page', 'pages/edit', 'title'],
        ['pages', 'data_json', 'Page', 'pages/edit', 'title'],
        ['blocks', 'body_html', 'Block', 'pages/edit', 'title'],
        ['blocks', 'data_json', 'Block', 'pages/edit', 'title'],
        ['faqs', 'answer_html', 'FAQ', 'faqs/edit', 'question'],
    ];

    private static ?array $usedCache = null;

    // =================================================================
    // Uploads
    // =================================================================

    /**
     * Store one uploaded image ($_FILES entry). $meta: alt, caption, credit,
     * is_real_photo, is_ai_generated, legacy_url, name (file slug). Returns the row.
     */
    public static function uploadImage(array $file, array $meta = [], ?int $by = null): array
    {
        $tmp = self::checkUpload($file);
        return self::storeImage($tmp, (string)($meta['name'] ?? $file['name'] ?? 'image'), (string)($file['name'] ?? ''), $meta, $by);
    }

    /** The same pipeline for a file already on disk (seeding, CLI). The source is not moved. */
    public static function importFile(string $absPath, array $meta = [], ?int $by = null): array
    {
        if (!is_file($absPath)) throw new InvalidArgumentException('File not found: ' . basename($absPath));
        return self::storeImage($absPath, (string)($meta['name'] ?? basename($absPath)), basename($absPath), $meta, $by);
    }

    /**
     * Store one uploaded PDF. $opts: private (bool, certificates), name (slug), title.
     * Returns the row (kind 'pdf'; private rows carry an access_token).
     */
    public static function uploadPdf(array $file, array $opts = [], ?int $by = null): array
    {
        $tmp = self::checkUpload($file);
        return self::storePdf($tmp, (string)($opts['name'] ?? $file['name'] ?? 'document'), (string)($file['name'] ?? ''), $opts, $by, true);
    }

    public static function importPdf(string $absPath, array $opts = [], ?int $by = null): array
    {
        return self::storePdf($absPath, (string)($opts['name'] ?? basename($absPath)), basename($absPath), $opts, $by, false);
    }

    /**
     * Store one uploaded MP4 video (round 2, WP-A: the "video" kind; Pathways > Certificate video uses it).
     * Shared hosting cannot re-encode, so the file is kept as uploaded, renamed to uploads/video/{slug}.mp4.
     * $opts: name (slug), title (caption), alt (a text alternative). Returns the row (kind 'video').
     */
    public static function uploadVideo(array $file, array $opts = [], ?int $by = null): array
    {
        $tmp = self::checkUpload($file);
        return self::storeVideo($tmp, (string)($opts['name'] ?? $file['name'] ?? 'video'), (string)($file['name'] ?? ''), $opts, $by, true);
    }

    /** The same for a file already on disk (tests, CLI). The source is not moved. */
    public static function importVideo(string $absPath, array $opts = [], ?int $by = null): array
    {
        return self::storeVideo($absPath, (string)($opts['name'] ?? basename($absPath)), basename($absPath), $opts, $by, false);
    }

    /** True when the file starts like an MP4 (an ISO base media "ftyp" box) and finfo agrees. */
    public static function isMp4(string $path): bool
    {
        $head = (string)@file_get_contents($path, false, null, 0, 12);
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') return false;
        return in_array(Img::sniff($path), ['video/mp4', 'video/x-m4v', 'application/mp4'], true);
    }

    private static function storeVideo(string $path, string $slug, string $originalName, array $opts, ?int $by, bool $uploaded): array
    {
        clearstatcache(true, $path);
        $bytes = (int)@filesize($path);
        if ($bytes <= 0) throw new InvalidArgumentException('That file is empty.');
        if ($bytes > self::VIDEO_MAX) throw new InvalidArgumentException('That video is too large (limit ' . (int)(self::VIDEO_MAX / 1048576) . ' MB). A square MP4 under 3 MB plays best on phones.');
        if (!self::isMp4($path)) throw new InvalidArgumentException('That file is not an MP4 video. Export it as MP4 (H.264) and try again.');
        $dir = ROOT_DIR . '/uploads/video';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('The uploads folder is not writable.');
        $slug = Img::cleanSlug($slug) ?: 'video';
        $rel = 'uploads/video/' . $slug . '.mp4';
        while (is_file(ROOT_DIR . '/' . $rel)) $rel = 'uploads/video/' . $slug . '-' . bin2hex(random_bytes(3)) . '.mp4';
        $dest = ROOT_DIR . '/' . $rel;
        $ok = $uploaded && is_uploaded_file($path) ? move_uploaded_file($path, $dest) : copy($path, $dest);
        if (!$ok) throw new RuntimeException('Could not store the video.');
        @chmod($dest, 0644);
        try {
            $id = Db::insert('media', [
                'kind'          => 'video',
                'path'          => $rel,
                'original_name' => self::originalName($originalName),
                'mime'          => 'video/mp4',
                'bytes'         => $bytes,
                'alt'           => isset($opts['alt']) && trim((string)$opts['alt']) !== '' ? mb_substr(Sanitizer::text($opts['alt']), 0, 300) : null,
                'caption'       => isset($opts['title']) && trim((string)$opts['title']) !== '' ? mb_substr(Sanitizer::text($opts['title']), 0, 300) : null,
                'is_private'    => 0,
                'uploaded_by'   => $by,
                'created_at'    => Time::nowUtc(),
                'updated_at'    => Time::nowUtc(),
            ]);
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        self::$usedCache = null;
        Audit::log($by, 'create', 'media', (string)$id, 'Video uploaded: ' . (self::originalName($originalName) ?? $rel));
        return (array)Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /** Validate a $_FILES entry and return its temporary path (plain messages on failure). */
    public static function checkUpload(array $file): string
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('That file is too large (limit ' . self::uploadLimitMb() . ' MB).');
        }
        if ($err === UPLOAD_ERR_PARTIAL) throw new InvalidArgumentException('The upload was interrupted. Please try again.');
        if ($err === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Choose a file first.');
        if ($err !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The upload failed on the server. Please try again.');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) throw new InvalidArgumentException('The upload failed on the server. Please try again.');
        if (!is_uploaded_file($tmp) && empty($GLOBALS['ICM_TRUST_UPLOADS'])) {
            throw new InvalidArgumentException('The upload failed on the server. Please try again.');
        }
        return $tmp;
    }

    /** upload_max_filesize (and post_max_size) in whole MB, for messages. */
    public static function uploadLimitMb(): int
    {
        $a = Img::toBytes((string)ini_get('upload_max_filesize'));
        $b = Img::toBytes((string)ini_get('post_max_size'));
        $min = min($a > 0 ? $a : PHP_INT_MAX, $b > 0 ? $b : PHP_INT_MAX);
        return $min === PHP_INT_MAX ? 0 : max(1, (int)floor($min / 1048576));
    }

    private static function storeImage(string $path, string $slug, string $originalName, array $meta, ?int $by): array
    {
        $fx = self::unit($meta['focal_x'] ?? 0.5);
        $fy = self::unit($meta['focal_y'] ?? 0.4);
        $r = Img::process($path, $slug, ['focal_x' => $fx, 'focal_y' => $fy]);
        $fields = self::metaFields($meta);
        try {
            $id = Db::insert('media', $fields + [
                'kind'          => 'image',
                'path'          => $r['path'],
                'original_name' => self::originalName($originalName),
                'mime'          => $r['mime'],
                'bytes'         => $r['bytes'],
                'width'         => $r['width'],
                'height'        => $r['height'],
                'variants_json' => (string)json_encode(['widths' => $r['widths'], 'og' => true, 'thumb' => true]),
                'focal_x'       => $fx,
                'focal_y'       => $fy,
                'legacy_url'    => isset($meta['legacy_url']) && $meta['legacy_url'] !== '' ? mb_substr(strtolower((string)$meta['legacy_url']), 0, 191) : null,
                'uploaded_by'   => $by,
                'created_at'    => Time::nowUtc(),
                'updated_at'    => Time::nowUtc(),
            ]);
        } catch (Throwable $e) {
            Img::forgetPath($r['path']);
            throw $e;
        }
        self::$usedCache = null;
        Audit::log($by, 'create', 'media', (string)$id, 'Image uploaded: ' . ($originalName !== '' ? self::originalName($originalName) : $r['path']));
        return (array)Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    private static function storePdf(string $path, string $slug, string $originalName, array $opts, ?int $by, bool $uploaded): array
    {
        clearstatcache(true, $path);
        $bytes = (int)@filesize($path);
        if ($bytes <= 0) throw new InvalidArgumentException('That file is empty.');
        if ($bytes > self::PDF_MAX) throw new InvalidArgumentException('That PDF is too large (limit 10 MB).');
        if ((string)@file_get_contents($path, false, null, 0, 5) !== '%PDF-') throw new InvalidArgumentException('That file is not a PDF.');
        if (Img::sniff($path) !== 'application/pdf') throw new InvalidArgumentException('That file is not a PDF.');

        $private = !empty($opts['private']);
        $slug = Img::cleanSlug($slug) ?: 'document';
        if ($private) {
            $dir = privatePath('certificates');
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Private storage is not writable.');
            $rel = 'certificates/' . bin2hex(random_bytes(16)) . '.pdf';
            $dest = privatePath($rel);
        } else {
            $dir = ROOT_DIR . '/uploads/docs';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('The uploads folder is not writable.');
            $rel = 'uploads/docs/' . $slug . '.pdf';
            while (is_file(ROOT_DIR . '/' . $rel)) $rel = 'uploads/docs/' . $slug . '-' . bin2hex(random_bytes(3)) . '.pdf';
            $dest = ROOT_DIR . '/' . $rel;
        }
        $ok = $uploaded && is_uploaded_file($path) ? move_uploaded_file($path, $dest) : copy($path, $dest);
        if (!$ok) throw new RuntimeException('Could not store the PDF.');
        @chmod($dest, $private ? 0600 : 0644);

        $id = Db::insert('media', [
            'kind'          => 'pdf',
            'path'          => $rel,
            'original_name' => self::originalName($originalName),
            'mime'          => 'application/pdf',
            'bytes'         => $bytes,
            'caption'       => isset($opts['title']) ? mb_substr(Sanitizer::text($opts['title']), 0, 300) : null,
            'is_private'    => $private ? 1 : 0,
            'access_token'  => $private ? bin2hex(random_bytes(32)) : null,
            'uploaded_by'   => $by,
            'created_at'    => Time::nowUtc(),
            'updated_at'    => Time::nowUtc(),
        ]);
        self::$usedCache = null;
        Audit::log($by, 'create', 'media', (string)$id, 'PDF uploaded' . ($private ? ' (private)' : '') . ': ' . self::originalName($originalName));
        return (array)Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    // =================================================================
    // Edit and delete
    // =================================================================

    /**
     * Update alt, caption, credit, the real/AI flags and the focal point. Moving the
     * focal point rebuilds the social crop and the thumbnail. Returns the new row.
     */
    public static function update(int $id, array $in, ?int $by = null): array
    {
        $row = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$row) throw new InvalidArgumentException('That item no longer exists.');
        $data = self::metaFields($in, $row);
        if (!empty($data['is_real_photo']) && !empty($data['is_ai_generated'])) {
            throw new InvalidArgumentException('Choose "real photo" or "AI image", not both.');
        }
        $refocus = false;
        if (array_key_exists('focal_x', $in) || array_key_exists('focal_y', $in)) {
            $fx = self::unit($in['focal_x'] ?? $row['focal_x']);
            $fy = self::unit($in['focal_y'] ?? $row['focal_y']);
            if (abs($fx - (float)$row['focal_x']) > 0.0005 || abs($fy - (float)$row['focal_y']) > 0.0005) {
                $data['focal_x'] = $fx;
                $data['focal_y'] = $fy;
                $refocus = $row['kind'] === 'image';
            }
        }
        $data['updated_at'] = Time::nowUtc();
        Db::update('media', $data, 'id = ?', [$id]);
        if ($refocus) Img::rederive($row, (float)$data['focal_x'], (float)$data['focal_y']);
        $diff = Audit::diff($row, $data);
        if ($diff) Audit::log($by, 'update', 'media', (string)$id, 'Media details changed', $diff);
        return (array)Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /**
     * Delete an item and its files, unless something uses it.
     * @return list<array> the places that use it (empty when it was deleted)
     */
    public static function delete(int $id, ?int $by = null): array
    {
        $row = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$row) return [];
        $uses = self::usage($id);
        if ($uses) return $uses;
        if ($row['kind'] === 'image') {
            Img::forget($row);
        } elseif (!empty($row['is_private'])) {
            $f = privatePath((string)$row['path']);
            if (str_starts_with((string)$row['path'], 'certificates/') && is_file($f)) @unlink($f);
        } elseif (preg_match('#^uploads/docs/[a-z0-9-]+\.pdf$#', (string)$row['path']) && is_file(ROOT_DIR . '/' . $row['path'])) {
            @unlink(ROOT_DIR . '/' . $row['path']);
        } elseif ($row['kind'] === 'video' && preg_match('#^uploads/video/[a-z0-9-]+\.mp4$#', (string)$row['path']) && is_file(ROOT_DIR . '/' . $row['path'])) {
            @unlink(ROOT_DIR . '/' . $row['path']);   // uploaded videos only; the seeded uploads/docs/ file stays with the seed
        }
        Db::delete('media', 'id = ?', [$id]);
        self::$usedCache = null;
        Audit::log($by, 'delete', 'media', (string)$id, 'Deleted ' . ($row['original_name'] ?: $row['path']));
        return [];
    }

    // =================================================================
    // Where an item is used
    // =================================================================

    /**
     * Every place that uses a media item.
     * @return list<array{label:string,title:string,route:string,id:int}>
     */
    public static function usage(int $id): array
    {
        $row = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$row) return [];
        $out = [];
        $add = static function (string $label, string $title, string $route, int $rid) use (&$out): void {
            $out[$label . '#' . $rid] = ['label' => $label, 'title' => $title, 'route' => $route, 'id' => $rid];
        };

        foreach (self::REFS as [$table, $col, $label, $route, $titleCol]) {
            if (!self::hasCol($table, $col)) continue;
            foreach (Db::all("SELECT id, $titleCol AS t FROM $table WHERE $col = ?", [$id]) as $r) $add($label, (string)$r['t'], $route, (int)$r['id']);
        }
        if (self::hasCol('post_media', 'media_id')) {
            foreach (Db::all('SELECT p.id, p.title FROM post_media pm JOIN posts p ON p.id = pm.post_id WHERE pm.media_id = ?', [$id]) as $r) {
                $add('Post gallery', (string)$r['title'], 'posts/edit', (int)$r['id']);
            }
        }

        $needles = [(string)$row['path']];
        if (!empty($row['legacy_url'])) $needles[] = (string)$row['legacy_url'];
        foreach (self::TEXT_COLS as [$table, $col, $label, $route, $titleCol]) {
            if (!self::hasCol($table, $col)) continue;
            foreach ($needles as $n) {
                $rows = Db::all("SELECT id, $titleCol AS t FROM $table WHERE $col LIKE ?" . Db::LIKE_ESCAPE, ['%' . Db::like($n) . '%']);
                foreach ($rows as $r) $add($label, (string)$r['t'], $route, (int)$r['id']);
            }
            if (str_ends_with($col, '_json')) {
                foreach (Db::all("SELECT id, $titleCol AS t, $col AS j FROM $table WHERE $col LIKE ?" . Db::LIKE_ESCAPE, ['%' . Db::like('media') . '%']) as $r) {
                    if (preg_match('/"[a-z_]*media(_id)?"\s*:\s*"?' . $id . '"?\s*[,}\]]/', (string)$r['j'])) $add($label, (string)$r['t'], $route, (int)$r['id']);
                }
            }
        }
        if (Db::tableExists('settings')) {
            foreach (Db::all('SELECT skey FROM settings WHERE is_secret = 0 AND (svalue LIKE ?' . Db::LIKE_ESCAPE . ' OR svalue = ?)', ['%' . Db::like((string)$row['path']) . '%', (string)$id]) as $r) {
                if (preg_match('/(image|media|poster|logo)/', (string)$r['skey'])) $add('Setting', (string)$r['skey'], 'settings', 0);
            }
            foreach (Db::pairs('SELECT skey, svalue FROM settings WHERE is_secret = 0 AND skey IN (' . Db::in(self::SETTING_REFS) . ')', self::SETTING_REFS) as $skey => $v) {
                if ((string)$v === (string)$id) $add('Setting', (string)$skey, 'settings', 0);
            }
        }
        return array_values($out);
    }

    /** Media ids in use anywhere, for the "unused" filter (one scan per request). @return array<int,true> */
    public static function usedIds(): array
    {
        if (self::$usedCache !== null) return self::$usedCache;
        $used = [];
        foreach (self::REFS as [$table, $col]) {
            if (!self::hasCol($table, $col)) continue;
            foreach (Db::column("SELECT DISTINCT $col FROM $table WHERE $col IS NOT NULL") as $v) $used[(int)$v] = true;
        }
        if (self::hasCol('post_media', 'media_id')) {
            foreach (Db::column('SELECT DISTINCT media_id FROM post_media') as $v) $used[(int)$v] = true;
        }
        if (Db::tableExists('settings')) {
            foreach (Db::column('SELECT svalue FROM settings WHERE is_secret = 0 AND skey IN (' . Db::in(self::SETTING_REFS) . ')', self::SETTING_REFS) as $v) {
                if (ctype_digit((string)$v) && (int)$v > 0) $used[(int)$v] = true;
            }
        }
        $blob = '';
        foreach (self::TEXT_COLS as [$table, $col]) {
            if (!self::hasCol($table, $col)) continue;
            foreach (Db::column("SELECT $col FROM $table WHERE $col LIKE '%uploads%' OR $col LIKE '%media%'") as $v) $blob .= "\n" . $v;
        }
        foreach (Db::column("SELECT svalue FROM settings WHERE is_secret = 0 AND svalue LIKE '%uploads/%'") as $v) $blob .= "\n" . $v;
        if ($blob !== '') {
            foreach (Db::all('SELECT id, path, legacy_url FROM media') as $m) {
                if (isset($used[(int)$m['id']])) continue;
                if (str_contains($blob, (string)$m['path']) || (!empty($m['legacy_url']) && str_contains($blob, (string)$m['legacy_url']))
                    || preg_match('/"[a-z_]*media(_id)?"\s*:\s*"?' . (int)$m['id'] . '"?\s*[,}\]]/', $blob)) {
                    $used[(int)$m['id']] = true;
                }
            }
        }
        return self::$usedCache = $used;
    }

    // =================================================================
    // Lists and JSON for the pickers
    // =================================================================

    /**
     * One page of the library. $f: q, kind (image|pdf), filter (real|ai|unused|noalt), page.
     * @return array{rows:list<array>,total:int,page:int,per:int,pages:int}
     */
    public static function listPage(array $f): array
    {
        $where = [];
        $args = [];
        $kind = in_array($f['kind'] ?? '', ['image', 'pdf', 'video'], true) ? $f['kind'] : '';
        if ($kind !== '') {
            $where[] = 'kind = ?';
            $args[] = $kind;
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . Db::like(mb_substr($q, 0, 80)) . '%';
            $where[] = '(alt LIKE ?' . Db::LIKE_ESCAPE . ' OR caption LIKE ?' . Db::LIKE_ESCAPE . ' OR original_name LIKE ?' . Db::LIKE_ESCAPE . ' OR path LIKE ?' . Db::LIKE_ESCAPE . ')';
            array_push($args, $like, $like, $like, $like);
        }
        $filter = (string)($f['filter'] ?? '');
        if ($filter === 'real') $where[] = 'is_real_photo = 1';
        if ($filter === 'ai') $where[] = 'is_ai_generated = 1';
        if ($filter === 'noalt') $where[] = "kind = 'image' AND (alt IS NULL OR alt = '')";
        if ($filter === 'unused') {
            $ids = array_keys(self::usedIds());
            if ($ids) {
                $where[] = 'id NOT IN (' . Db::in($ids) . ')';
                array_push($args, ...$ids);
            }
        }
        $where[] = 'is_private = 0';
        $sql = 'SELECT * FROM media' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
        return Db::paginate($sql, $args, max(1, (int)($f['page'] ?? 1)), self::PER_PAGE);
    }

    /** The picker's view of one row (thumb, a body-size source, dimensions, alt). */
    public static function toJson(array $m): array
    {
        $isImg = $m['kind'] === 'image';
        $v = json_decode((string)($m['variants_json'] ?? ''), true);
        $widths = array_map('intval', (array)($v['widths'] ?? []));
        $bodyW = in_array(1200, $widths, true) ? 1200 : ($widths ? max($widths) : 0);
        $w = (int)($m['width'] ?? 0);
        $h = (int)($m['height'] ?? 0);
        $outW = $bodyW && $w ? min($bodyW, $w) : $bodyW;
        return [
            'id'      => (int)$m['id'],
            'kind'    => (string)$m['kind'],
            'thumb'   => $isImg ? mediaUrl($m, 'thumb') : '',
            'preview' => $isImg ? mediaUrl($m, in_array(800, $widths, true) ? 800 : ($widths ? min($widths) : 800)) : '',
            'src'     => $isImg ? mediaUrl($m, $bodyW ?: 1200) : '/' . ltrim((string)$m['path'], '/'),
            'og'      => $isImg ? mediaUrl($m, 'og') : '',
            'w'       => $outW,
            'h'       => $outW && $w ? (int)round($outW * $h / $w) : 0,
            'alt'     => (string)($m['alt'] ?? ''),
            'caption' => (string)($m['caption'] ?? ''),
            'name'    => (string)($m['original_name'] ?? basename((string)$m['path'])),
            'focal'   => [(float)$m['focal_x'], (float)$m['focal_y']],
        ];
    }

    /** Absolute path of a private file (certificate PDFs), or null. */
    public static function privateFile(array $m): ?string
    {
        if (empty($m['is_private'])) return null;
        $rel = (string)$m['path'];
        if (str_contains($rel, '..') || !preg_match('#^[a-z]+/[A-Za-z0-9._-]+$#', $rel)) return null;
        $f = privatePath($rel);
        return is_file($f) ? $f : null;
    }

    // -----------------------------------------------------------------

    private static function metaFields(array $in, ?array $row = null): array
    {
        $out = [];
        $text = ['alt' => 300, 'caption' => 300, 'credit' => 160];
        foreach ($text as $k => $max) {
            if (!array_key_exists($k, $in)) continue;
            $v = Sanitizer::text($in[$k], false, $max);
            $out[$k] = $v === '' ? null : $v;
        }
        foreach (['is_real_photo', 'is_ai_generated'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = !empty($in[$k]) && $in[$k] !== '0' ? 1 : 0;
            elseif ($row) $out[$k] = (int)$row[$k];
        }
        if (!$row) $out = array_filter($out, fn($v) => $v !== null);
        return $out;
    }

    private static function unit(mixed $v): float
    {
        return round(min(1.0, max(0.0, (float)$v)), 3);
    }

    private static function originalName(string $name): ?string
    {
        $n = Sanitizer::text(basename(str_replace('\\', '/', $name)), false, 255);
        return $n === '' ? null : $n;
    }

    private static function hasCol(string $table, string $col): bool
    {
        try {
            return Db::tableExists($table) && Db::hasColumn($table, $col);
        } catch (Throwable) {
            return false;
        }
    }
}
