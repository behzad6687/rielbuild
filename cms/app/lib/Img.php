<?php
/**
 * The runtime image pipeline (spec 7.6, 8.6), adapted from NHF.
 *
 * Every image that reaches the site is re-encoded with GD, so nothing uploaded
 * keeps its bytes, its name or its metadata (EXIF and GPS from phone photos of
 * children are dropped; the orientation is applied first). Variants:
 *   {base}-{480|800|1200|1600}.webp and .jpg  (never upscaled beyond 1.25x)
 *   {base}-og.jpg                            (1200x630 focal crop)
 *   {base}-thumb.webp                        (320 square focal crop)
 * where base is uploads/img/YYYY/MM/{slug}-{6 hex}. A clean master (at most
 * 3200 px, no metadata) is kept in {private}/originals/YYYY/MM/ for re-deriving
 * crops when the focal point moves. No grading and no forced crop (spec 1.8).
 *
 * Guards before GD decodes anything: 12 MB, finfo JPEG/PNG/WebP only (GIF and
 * HEIC refused with a plain message), 50 megapixels, and the memory pre-check
 * (width x height x 5 x 1.8 bytes must fit in the free memory, spec 2.5).
 */
defined('ICM') or exit;

final class Img
{
    public const WIDTHS = [480, 800, 1200, 1600];
    public const MAX_BYTES = 12582912;        // 12 MB
    public const MAX_PIXELS = 50000000;       // 50 MP (a 48 MP iPhone photo passes)
    public const MASTER_MAX = 3200;
    public const Q_WEBP = 82;
    public const Q_JPEG = 80;
    public const OG_W = 1200;
    public const OG_H = 630;
    public const THUMB = 320;
    public const HEIC_MSG = 'This photo is in HEIC format. Share it as JPEG, or set the camera to Most Compatible.';
    public const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];

    /**
     * Check a file before decoding it. Throws InvalidArgumentException with a
     * message fit for staff. @return array{mime:string,type:int,width:int,height:int,bytes:int}
     */
    public static function inspect(string $path): array
    {
        clearstatcache(true, $path);
        $bytes = (int)@filesize($path);
        if ($bytes <= 0) throw new InvalidArgumentException('That file is empty.');
        if ($bytes > self::MAX_BYTES) throw new InvalidArgumentException('That image is too large (limit 12 MB).');

        $head = (string)@file_get_contents($path, false, null, 0, 64);
        if (self::isHeic($head)) throw new InvalidArgumentException(self::HEIC_MSG);

        $mime = self::sniff($path);
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            throw new InvalidArgumentException(self::HEIC_MSG);
        }
        if ($mime === 'image/gif') throw new InvalidArgumentException('GIF images are not accepted. Use JPEG, PNG or WebP.');
        if (!isset(self::TYPES[$mime])) throw new InvalidArgumentException('That file is not a JPEG, PNG or WebP image.');

        $info = @getimagesize($path);
        if (!$info || ($info[2] ?? 0) !== self::TYPES[$mime]) {
            throw new InvalidArgumentException('That image could not be read. Try saving it again as a JPEG.');
        }
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if ($w < 1 || $h < 1) throw new InvalidArgumentException('That image could not be read. Try saving it again as a JPEG.');
        if ($w * $h > self::MAX_PIXELS) {
            throw new InvalidArgumentException(sprintf(
                'That image is %s by %s pixels, over the 50 megapixel limit. Resize it and upload it again.',
                number_format($w),
                number_format($h)
            ));
        }
        self::memoryCheck($w, $h);
        return ['mime' => $mime, 'type' => self::TYPES[$mime], 'width' => $w, 'height' => $h, 'bytes' => $bytes];
    }

    /**
     * The whole pipeline for one file: inspect, decode, orient, write the variants
     * and the master. $slug names the files. Returns the facts for the media row:
     * @return array{path:string,mime:string,bytes:int,width:int,height:int,widths:list<int>}
     */
    public static function process(string $srcPath, string $slug, array $opts = []): array
    {
        $info = self::inspect($srcPath);
        $fx = (float)($opts['focal_x'] ?? 0.5);
        $fy = (float)($opts['focal_y'] ?? 0.4);

        $im = self::decode($srcPath, $info['type']);
        $im = self::limit($im, self::MASTER_MAX);
        if ($info['type'] === IMAGETYPE_JPEG) $im = self::orient($im, self::orientation($srcPath));
        $alpha = $info['type'] !== IMAGETYPE_JPEG && self::hasAlpha($im);

        $now = Time::nowLocal();
        $dir = 'uploads/img/' . $now->format('Y') . '/' . $now->format('m');
        $slug = self::cleanSlug($slug);
        $abs = ROOT_DIR . '/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true) && !is_dir($abs)) throw new RuntimeException('The uploads folder is not writable.');
        do {
            $base = $dir . '/' . $slug . '-' . bin2hex(random_bytes(3));
        } while (glob(ROOT_DIR . '/' . $base . '-*') ?: false);

        try {
            $widths = self::writeWidths($im, ROOT_DIR . '/' . $base, $alpha);
            if (!$widths) {
                throw new InvalidArgumentException('That image is only ' . imagesx($im) . ' pixels wide. Use one at least 400 pixels wide.');
            }
            self::writeCrops($im, ROOT_DIR . '/' . $base, $fx, $fy, $alpha);
            self::writeMaster($im, $base, $alpha);
        } catch (Throwable $e) {
            self::forgetPath($base);
            imagedestroy($im);
            throw $e;
        }
        $out = ['path' => $base, 'mime' => $info['mime'], 'bytes' => $info['bytes'], 'width' => imagesx($im), 'height' => imagesy($im), 'widths' => $widths];
        imagedestroy($im);
        return $out;
    }

    /**
     * Rebuild the social crop and the thumbnail after the focal point moved, and (round 2, WP-I) every ratio crop
     * already on disk ({base}-r4x5-800.webp and the like), so a slide's phone crop follows the new focal point too.
     */
    public static function rederive(array $media, float $fx, float $fy): void
    {
        $base = (string)$media['path'];
        if (!str_starts_with($base, 'uploads/img/')) return;
        $src = self::sourceFile($media);
        if (!$src) return;
        $info = @getimagesize($src);
        if (!$info) return;
        self::memoryCheck((int)$info[0], (int)$info[1]);
        $im = self::decode($src, (int)$info[2]);
        $alpha = (int)$info[2] !== IMAGETYPE_JPEG && self::hasAlpha($im);
        self::writeCrops($im, ROOT_DIR . '/' . $base, $fx, $fy, $alpha);
        foreach (self::ratioFiles($base) as $ratio => $widths) self::writeRatio($im, $base, $ratio, $widths, $fx, $fy, $alpha);
        imagedestroy($im);
    }

    // -----------------------------------------------------------------
    // Ratio crops (round 2, WP-I; V2-PLAN WP-I "a phone preset", ADMIN-AUDIT 4.9, gate W1 request B3)
    // -----------------------------------------------------------------

    /**
     * Fixed-ratio focal crops, written beside the width variants as {base}-{tag}-{width}.webp and .jpg, where the tag
     * is "r" plus the ratio ("4:5" gives r4x5). A slide's phone photo uses PHONE_WIDTHS at the slider's phone ratio
     * (4:5, 1:1 or 9:16); without a phone photo the desktop photo is cropped the same way, around its focal point.
     * CARD_WIDTHS (320 and 640) at 4:3 or 3:2 are the menu card sizes of DESIGN-AUDIT 6.5 (WP-H, request B3).
     * Never upscaled beyond 1.25x the crop box; the files go with the image (forgetPath() removes {base}-*).
     */
    public const RATIOS = [
        '4:5' => [4, 5], '1:1' => [1, 1], '9:16' => [9, 16],      // slider phone ratios
        '21:9' => [21, 9], '16:9' => [16, 9], '3:1' => [3, 1],   // slider desktop ratios (the page crops these with CSS)
        '4:3' => [4, 3], '3:2' => [3, 2],                        // menu cards (WP-H)
    ];
    public const PHONE_WIDTHS = [480, 800, 1080];
    public const CARD_WIDTHS = [320, 640];

    /** The file tag of a ratio ("4:5" gives "r4x5"), or null for an unknown ratio. */
    public static function ratioTag(string $ratio): ?string
    {
        return isset(self::RATIOS[$ratio]) ? 'r' . self::RATIOS[$ratio][0] . 'x' . self::RATIOS[$ratio][1] : null;
    }

    /**
     * Write the $ratio crops of an image at $widths (focal point: the media row's, unless given). Widths the crop box
     * cannot fill at 1.25x are skipped, and an older file of such a width is removed. @return list<int> the widths written
     */
    public static function ratioCrops(array $media, string $ratio, array $widths = self::PHONE_WIDTHS, ?float $fx = null, ?float $fy = null): array
    {
        $base = (string)($media['path'] ?? '');
        if (self::ratioTag($ratio) === null || ($media['kind'] ?? 'image') !== 'image' || !self::isImageBase($base)) return [];
        $src = self::sourceFile($media);
        if (!$src) return [];
        $info = @getimagesize($src);
        if (!$info) return [];
        self::memoryCheck((int)$info[0], (int)$info[1]);
        $im = self::decode($src, (int)$info[2]);
        $alpha = (int)$info[2] !== IMAGETYPE_JPEG && self::hasAlpha($im);
        try {
            return self::writeRatio($im, $base, $ratio, $widths, $fx ?? (float)($media['focal_x'] ?? 0.5), $fy ?? (float)($media['focal_y'] ?? 0.4), $alpha);
        } finally {
            imagedestroy($im);
        }
    }

    /** The widths of the $ratio crops of an image that exist on disk (WebP and JPEG both). @return list<int> */
    public static function ratioWidths(array $media, string $ratio, array $widths = self::PHONE_WIDTHS): array
    {
        $tag = self::ratioTag($ratio);
        $base = (string)($media['path'] ?? '');
        if ($tag === null || ($media['kind'] ?? 'image') !== 'image' || !self::isImageBase($base)) return [];
        $out = [];
        foreach ($widths as $w) {
            $f = ROOT_DIR . '/' . $base . '-' . $tag . '-' . (int)$w;
            if (is_file($f . '.webp') && is_file($f . '.jpg')) $out[] = (int)$w;
        }
        sort($out);
        return $out;
    }

    /** The public URL of one ratio crop ('' for an unknown ratio). */
    public static function ratioUrl(array $media, string $ratio, int $width, string $ext = 'webp'): string
    {
        $tag = self::ratioTag($ratio);
        if ($tag === null || empty($media['path'])) return '';
        return '/' . ltrim((string)$media['path'], '/') . '-' . $tag . '-' . $width . '.' . ($ext === 'jpg' ? 'jpg' : 'webp');
    }

    /** The ratio crops of a base already on disk: [ratio => widths]. */
    private static function ratioFiles(string $base): array
    {
        if (!self::isImageBase($base)) return [];
        $byTag = [];
        foreach (self::RATIOS as $ratio => $_) $byTag[(string)self::ratioTag($ratio)] = $ratio;
        $out = [];
        foreach (glob(ROOT_DIR . '/' . $base . '-r*x*-*.webp') ?: [] as $f) {
            if (!preg_match('/-(r\d+x\d+)-(\d+)\.webp$/', $f, $m) || !isset($byTag[$m[1]])) continue;
            $out[$byTag[$m[1]]][] = (int)$m[2];
        }
        foreach ($out as $r => $ws) {
            $ws = array_values(array_unique($ws));
            sort($ws);
            $out[$r] = $ws;
        }
        return $out;
    }

    /** @return list<int> the widths written */
    private static function writeRatio(GdImage $im, string $base, string $ratio, array $widths, float $fx, float $fy, bool $alpha): array
    {
        [$rw, $rh] = self::RATIOS[$ratio];
        $tag = (string)self::ratioTag($ratio);
        $sw = imagesx($im);
        $sh = imagesy($im);
        $cw = (int)round($sw * $rh / $rw) > $sh ? (int)round($sh * $rw / $rh) : $sw;   // the width of the largest box of that ratio
        $abs = ROOT_DIR . '/' . $base . '-' . $tag . '-';
        $done = [];
        foreach (array_values(array_unique(array_map('intval', $widths))) as $w) {
            if ($w <= 0) continue;
            if ($w > $cw * 1.25) {
                @unlink($abs . $w . '.webp');
                @unlink($abs . $w . '.jpg');
                continue;
            }
            $dst = self::crop($im, $w, (int)max(1, round($w * $rh / $rw)), $fx, $fy, $alpha);
            self::save($dst, $abs . $w, $alpha);
            imagedestroy($dst);
            $done[] = $w;
        }
        sort($done);
        return $done;
    }

    /** The best source to crop from: the clean master, else the largest stored JPEG variant (seeded images). */
    private static function sourceFile(array $media): ?string
    {
        $base = (string)($media['path'] ?? '');
        if (!str_starts_with($base, 'uploads/img/')) return null;
        $src = self::masterFile($base);
        if ($src) return $src;
        $variants = json_decode((string)($media['variants_json'] ?? ''), true);
        $w = max(array_map('intval', (array)($variants['widths'] ?? [1200])) ?: [1200]);
        $f = ROOT_DIR . '/' . $base . '-' . $w . '.jpg';
        if (is_file($f)) return $f;
        $best = null;
        $bestW = 0;
        foreach (glob(ROOT_DIR . '/' . $base . '-*.jpg') ?: [] as $g) {
            if (preg_match('/-(\d+)\.jpg$/', $g, $m) && (int)$m[1] > $bestW && !preg_match('/-r\d+x\d+-\d+\.jpg$/', $g)) {
                $bestW = (int)$m[1];
                $best = $g;
            }
        }
        return $best;
    }

    private static function isImageBase(string $base): bool
    {
        return (bool)preg_match('#^uploads/img/\d{4}/\d{2}/[a-z0-9-]+$#', $base);
    }

    /** Delete every file of an image (variants and master). */
    public static function forget(array $media): void
    {
        self::forgetPath((string)$media['path']);
    }

    public static function forgetPath(string $base): void
    {
        if (!preg_match('#^uploads/img/\d{4}/\d{2}/[a-z0-9-]+$#', $base)) return;
        foreach (glob(ROOT_DIR . '/' . $base . '-*.{jpg,webp}', GLOB_BRACE) ?: [] as $f) @unlink($f);
        $m = self::masterFile($base);
        if ($m) @unlink($m);
    }

    /** The clean master in private storage, or null. */
    public static function masterFile(string $base): ?string
    {
        if (!preg_match('#^uploads/img/(\d{4}/\d{2}/[a-z0-9-]+)$#', $base, $m)) return null;
        foreach (['jpg', 'png'] as $ext) {
            $f = privatePath('originals/' . $m[1] . '.' . $ext);
            if (is_file($f)) return $f;
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Decoding and orientation
    // -----------------------------------------------------------------

    public static function decode(string $path, int $type): GdImage
    {
        $im = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };
        if (!$im) throw new InvalidArgumentException('That image could not be read. Try saving it again as a JPEG.');
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        return $im;
    }

    /** EXIF orientation (1 to 8) of a JPEG; 1 when absent. Works without ext-exif too. */
    public static function orientation(string $path): int
    {
        if (function_exists('exif_read_data')) {
            $e = @exif_read_data($path, 'IFD0');
            if (is_array($e) && isset($e['Orientation'])) {
                $o = (int)$e['Orientation'];
                return $o >= 1 && $o <= 8 ? $o : 1;
            }
            if (is_array($e)) return 1;
        }
        return self::orientationFromBytes((string)@file_get_contents($path, false, null, 0, 131072));
    }

    /** Minimal APP1/TIFF reader for tag 0x0112 (the fallback when ext-exif is missing). */
    public static function orientationFromBytes(string $b): int
    {
        if (strlen($b) < 4 || substr($b, 0, 2) !== "\xFF\xD8") return 1;
        $i = 2;
        $n = strlen($b);
        while ($i + 4 <= $n) {
            if ($b[$i] !== "\xFF") return 1;
            $marker = ord($b[$i + 1]);
            if ($marker === 0xD9 || $marker === 0xDA) return 1;
            $len = (ord($b[$i + 2]) << 8) | ord($b[$i + 3]);
            if ($marker === 0xE1 && substr($b, $i + 4, 6) === "Exif\0\0") {
                $t = $i + 10;
                $le = substr($b, $t, 2) === 'II';
                $u16 = static fn(int $o): int => $le ? (ord($b[$o]) | (ord($b[$o + 1]) << 8)) : ((ord($b[$o]) << 8) | ord($b[$o + 1]));
                $u32 = static fn(int $o): int => $le
                    ? (ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24))
                    : ((ord($b[$o]) << 24) | (ord($b[$o + 1]) << 16) | (ord($b[$o + 2]) << 8) | ord($b[$o + 3]));
                if ($t + 8 > $n) return 1;
                $ifd = $t + $u32($t + 4);
                if ($ifd + 2 > $n) return 1;
                $count = $u16($ifd);
                for ($k = 0; $k < $count; $k++) {
                    $e = $ifd + 2 + $k * 12;
                    if ($e + 12 > $n) break;
                    if ($u16($e) === 0x0112) {
                        $o = $u16($e + 8);
                        return $o >= 1 && $o <= 8 ? $o : 1;
                    }
                }
                return 1;
            }
            $i += 2 + $len;
        }
        return 1;
    }

    /** Apply an EXIF orientation so the pixels stand upright. */
    public static function orient(GdImage $im, int $o): GdImage
    {
        if ($o <= 1 || $o > 8) return $im;
        $rot = static function (GdImage $src, int $angle): GdImage {
            $r = imagerotate($src, $angle, 0);
            if (!$r) return $src;
            imagedestroy($src);
            return $r;
        };
        switch ($o) {
            case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 3: $im = $rot($im, 180); break;
            case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
            case 5: $im = $rot($im, 270); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 6: $im = $rot($im, 270); break;
            case 7: $im = $rot($im, 90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 8: $im = $rot($im, 90); break;
        }
        return $im;
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /** @return list<int> the widths written */
    private static function writeWidths(GdImage $im, string $absBase, bool $alpha): array
    {
        $sw = imagesx($im);
        $done = [];
        foreach (self::WIDTHS as $w) {
            if ($w > $sw * 1.25) continue;
            $h = (int)max(1, round($w * imagesy($im) / $sw));
            $dst = self::resample($im, 0, 0, $sw, imagesy($im), $w, $h, $alpha);
            self::save($dst, $absBase . '-' . $w, $alpha);
            imagedestroy($dst);
            $done[] = $w;
        }
        return $done;
    }

    private static function writeCrops(GdImage $im, string $absBase, float $fx, float $fy, bool $alpha): void
    {
        $og = self::crop($im, self::OG_W, self::OG_H, $fx, $fy, false);
        self::jpeg($og, $absBase . '-og.jpg');
        imagedestroy($og);
        $th = self::crop($im, self::THUMB, self::THUMB, $fx, $fy, $alpha);
        self::webp($th, $absBase . '-thumb.webp', $alpha);
        imagedestroy($th);
    }

    private static function writeMaster(GdImage $im, string $base, bool $alpha): void
    {
        if (!preg_match('#^uploads/img/(\d{4}/\d{2})/([a-z0-9-]+)$#', $base, $m)) return;
        $dir = privatePath('originals/' . $m[1]);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            Log::write('media', 'originals folder not writable; master not kept for ' . $base);
            return;
        }
        if ($alpha) {
            imagesavealpha($im, true);
            imagepng($im, $dir . '/' . $m[2] . '.png', 6);
        } else {
            imageinterlace($im, true);
            imagejpeg($im, $dir . '/' . $m[2] . '.jpg', 90);
        }
    }

    /** A $w x $h crop of the largest box of that ratio around the focal point. */
    private static function crop(GdImage $src, int $w, int $h, float $fx, float $fy, bool $alpha): GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $ratio = $w / $h;
        $cw = $sw;
        $ch = (int)round($sw / $ratio);
        if ($ch > $sh) {
            $ch = $sh;
            $cw = (int)round($sh * $ratio);
        }
        $fx = min(1.0, max(0.0, $fx));
        $fy = min(1.0, max(0.0, $fy));
        $cx = (int)max(0, min((int)round($fx * $sw - $cw / 2), $sw - $cw));
        $cy = (int)max(0, min((int)round($fy * $sh - $ch / 2), $sh - $ch));
        return self::resample($src, $cx, $cy, $cw, $ch, $w, $h, $alpha);
    }

    private static function resample(GdImage $src, int $sx, int $sy, int $sw, int $sh, int $w, int $h, bool $alpha): GdImage
    {
        $dst = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
        if ($w < $sw * 0.8 && !$alpha) {
            // A light sharpen after a big reduction (from NHF), so small sizes stay crisp.
            imageconvolution($dst, [[-0.05, -0.05, -0.05], [-0.05, 1.4, -0.05], [-0.05, -0.05, -0.05]], 1, 0);
        }
        return $dst;
    }

    private static function save(GdImage $im, string $absNoExt, bool $alpha): void
    {
        self::webp($im, $absNoExt . '.webp', $alpha);
        self::jpeg($im, $absNoExt . '.jpg');
    }

    private static function webp(GdImage $im, string $file, bool $alpha): void
    {
        if ($alpha) imagesavealpha($im, true);
        if (!imagewebp($im, $file, self::Q_WEBP)) throw new RuntimeException('Could not write ' . basename($file));
    }

    /** JPEG, progressive, flattened on white when the source had transparency. */
    private static function jpeg(GdImage $im, string $file): void
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        imageinterlace($flat, true);
        $ok = imagejpeg($flat, $file, self::Q_JPEG);
        imagedestroy($flat);
        if (!$ok) throw new RuntimeException('Could not write ' . basename($file));
    }

    /** Scale down so the long edge is at most $max. */
    private static function limit(GdImage $im, int $max): GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        if (max($w, $h) <= $max) return $im;
        $s = $max / max($w, $h);
        $nw = (int)max(1, round($w * $s));
        $nh = (int)max(1, round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        return $dst;
    }

    private static function hasAlpha(GdImage $im): bool
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $step = max(1, (int)floor(max($w, $h) / 64));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    /** Refuse an image GD could not decode within the free memory (spec 2.5). */
    public static function memoryCheck(int $w, int $h): void
    {
        $need = (int)($w * $h * 5 * 1.8);
        $limit = self::toBytes((string)ini_get('memory_limit'));
        if ($limit < 0) return;
        // memory_get_usage() without "real": freed blocks PHP keeps are reused before the limit bites.
        $free = $limit - memory_get_usage();
        if ($need <= $free) return;
        if ($limit < 536870912 && @ini_set('memory_limit', '512M') !== false) {
            $limit = self::toBytes((string)ini_get('memory_limit'));
            $free = $limit - memory_get_usage();
            if ($need <= $free) return;
        }
        throw new InvalidArgumentException(sprintf(
            'That image (%s by %s pixels) is too big for this server to process. Resize it to about 4000 pixels wide and upload it again.',
            number_format($w),
            number_format($h)
        ));
    }

    /** HEIC/HEIF (and AVIF) files are ISO boxes: "ftyp" at byte 4 and a known brand. */
    public static function isHeic(string $head): bool
    {
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') return false;
        $brands = substr($head, 8, 4) . substr($head, 16, min(32, max(0, strlen($head) - 16)));
        foreach (['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'] as $b) {
            if (str_contains($brands, $b)) return true;
        }
        return false;
    }

    public static function sniff(string $path): string
    {
        if (class_exists('finfo')) {
            $m = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($m) && $m !== '') return strtolower($m);
        }
        $info = @getimagesize($path);
        return strtolower((string)($info['mime'] ?? 'application/octet-stream'));
    }

    public static function cleanSlug(string $s): string
    {
        $s = (string)preg_replace('/\.[a-z0-9]{2,5}$/i', '', trim($s));
        $s = substr(slugify($s), 0, 60);
        return trim($s, '-') ?: 'image';
    }

    public static function toBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') return -1;
        $n = (float)$v;
        return (int)match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
