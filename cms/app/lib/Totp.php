<?php
/**
 * Two-step sign-in codes (spec 7.2): RFC 6238 TOTP, hand-written.
 * HMAC-SHA1, 6 digits, 30-second steps, one step either side accepted, and the
 * last accepted step is stored so a code cannot be replayed.
 *
 * Enrolment shows a QR code drawn here as SVG (byte mode, error correction M),
 * so no secret ever goes to an outside service, plus 8 one-time recovery codes.
 */
defined('ICM') or exit;

final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1;
    public const ISSUER = 'Website admin';
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    // ---------------- secrets and codes ----------------

    /** A new base32 secret (20 random bytes = 32 characters). */
    public static function newSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if ($chunk === '') continue;
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): ?string
    {
        $b32 = strtoupper((string)preg_replace('/[\s=-]+/', '', $b32));
        if ($b32 === '' || strspn($b32, self::B32) !== strlen($b32)) return null;
        $bits = '';
        foreach (str_split($b32) as $c) $bits .= str_pad(decbin((int)strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }

    /** The time step for a unix time (default now). */
    public static function step(?int $ts = null): int
    {
        return intdiv($ts ?? Time::ts(), self::PERIOD);
    }

    /** The code for one step. $digits is 6 in use (8 for the RFC test vectors). */
    public static function code(string $secretB32, int $step, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secretB32) ?? '';
        $h = hash_hmac('sha1', pack('J', $step), $key, true);
        $o = ord($h[19]) & 0x0f;
        $bin = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Check a typed code against now, one step either side. Returns the matched
     * step (store it as totp_last_step), or null. Steps at or below $lastStep
     * are refused, so a code works once.
     */
    public static function verify(string $secretB32, string $code, ?int $lastStep = null, ?int $ts = null): ?int
    {
        $code = (string)preg_replace('/\D+/', '', $code);
        if (strlen($code) !== self::DIGITS || self::base32Decode($secretB32) === null) return null;
        $now = self::step($ts);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $s = $now + $i;
            if ($lastStep !== null && $s <= $lastStep) continue;
            if (hash_equals(self::code($secretB32, $s), $code)) return $s;
        }
        return null;
    }

    /** The otpauth:// address an authenticator app reads from the QR code. */
    public static function uri(string $secretB32, string $account, string $issuer = self::ISSUER): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** The secret in groups of 4 for typing by hand. */
    public static function groups(string $secretB32): string
    {
        return trim(chunk_split($secretB32, 4, ' '));
    }

    // ---------------- recovery codes ----------------

    /** $n fresh recovery codes, "abcde-fghjk" (no look-alike characters). */
    public static function recoveryCodes(int $n = 8): array
    {
        $out = [];
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        while (count($out) < $n) {
            $c = '';
            for ($i = 0; $i < 10; $i++) $c .= self::RECOVERY_ALPHABET[random_int(0, $max)];
            $out[substr($c, 0, 5) . '-' . substr($c, 5)] = true;
        }
        return array_keys($out);
    }

    /** Stored form of a recovery code (HMAC with app_secret; spaces, dashes and case ignored). */
    public static function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', self::normaliseRecovery($code), (string)cfg('app_secret'));
    }

    /** True when the typed text looks like a recovery code rather than a 6-digit code. */
    public static function looksLikeRecovery(string $typed): bool
    {
        return strlen(self::normaliseRecovery($typed)) === 10 && preg_match('/[a-z]/', self::normaliseRecovery($typed)) === 1;
    }

    private static function normaliseRecovery(string $code): string
    {
        return strtolower((string)preg_replace('/[\s-]+/', '', $code));
    }

    // ---------------- QR code (SVG) ----------------

    /**
     * An SVG QR code for $text (byte mode, error correction M, versions 1 to 25),
     * with the 4-module quiet zone. Colours are fixed dark on light for scanners.
     */
    public static function qrSvg(string $text, string $label = 'QR code for the authenticator app'): string
    {
        $m = self::qrMatrix($text);
        $n = count($m);
        $size = $n + 8;
        $d = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if (!$m[$y][$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $n && $m[$y][$x]) $x++;
                $d .= 'M' . ($start + 4) . ' ' . ($y + 4) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . ($size * 6) . '" height="' . ($size * 6)
            . '" role="img" aria-label="' . e($label) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#ffffff"/>'
            . '<path fill="#000000" d="' . $d . '"/></svg>';
    }

    /** ECC level M codewords per block, by version (index 0 unused). */
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28];
    /** ECC level M block count, by version. */
    private const ECC_BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21];

    /** @return list<list<bool>> the module matrix, true = dark */
    public static function qrMatrix(string $text): array
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $len = count($data);
        $ver = 0;
        for ($v = 1; $v <= 25; $v++) {
            $capBits = self::dataCodewords($v) * 8;
            $need = 4 + ($v < 10 ? 8 : 16) + 8 * $len;
            if ($need <= $capBits) {
                $ver = $v;
                break;
            }
        }
        if ($ver === 0) throw new InvalidArgumentException('Text too long for the QR code');

        // Bit stream: byte mode, count, data, terminator, pad bytes.
        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1;
        };
        $push(0b0100, 4);
        $push($len, $ver < 10 ? 8 : 16);
        foreach ($data as $b) $push($b, 8);
        $cap = self::dataCodewords($ver) * 8;
        $push(0, min(4, $cap - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $push($pad, 8);
        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $v = 0;
            foreach ($byte as $bit) $v = ($v << 1) | $bit;
            $codewords[] = $v;
        }
        $all = self::addEcc($codewords, $ver);

        $size = $ver * 4 + 17;
        $mod = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $x, int $y, bool $dark) use (&$mod, &$fn): void {
            $mod[$y][$x] = $dark;
            $fn[$y][$x] = true;
        };

        for ($i = 0; $i < $size; $i++) {          // timing patterns
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {   // finder patterns and separators
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) continue;
                    $dist = max(abs($dx), abs($dy));
                    $set($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $align = self::alignmentPositions($ver, $size);
        $na = count($align);
        for ($i = 0; $i < $na; $i++) {
            for ($j = 0; $j < $na; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($align[$i] + $dx, $align[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        self::drawFormat($set, $size, 0);          // reserve the format areas
        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $vbits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($vbits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $bit);
                $set($b, $a, $bit);
            }
        }

        // Data modules in the zigzag order.
        $i = 0;
        $total = count($all) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total) {
                        $mod[$y][$x] = (($all[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }

        // Pick the mask with the lowest penalty.
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $try = self::applyMask($mod, $fn, $mask, $size);
            $setTry = static function (int $x, int $y, bool $dark) use (&$try): void {
                $try[$y][$x] = $dark;
            };
            self::drawFormat($setTry, $size, $mask);
            $score = self::penalty($try, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $try;
            }
        }
        return $best;
    }

    private static function rawModules(int $ver): int
    {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $na = intdiv($ver, 7) + 2;
            $r -= (25 * $na - 10) * $na - 55;
            if ($ver >= 7) $r -= 36;
        }
        return $r;
    }

    private static function dataCodewords(int $ver): int
    {
        return intdiv(self::rawModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::ECC_BLOCKS[$ver];
    }

    private static function alignmentPositions(int $ver, int $size): array
    {
        if ($ver === 1) return [];
        $na = intdiv($ver, 7) + 2;
        $step = intdiv($ver * 4 + $na * 2 + 1, $na * 2 - 2) * 2;
        $out = array_fill(0, $na, 0);
        $out[0] = 6;
        for ($i = $na - 1, $pos = $size - 7; $i >= 1; $i--, $pos -= $step) $out[$i] = $pos;
        return $out;
    }

    /** Format bits for level M (bits 00) and the mask, in both copies, plus the dark module. */
    private static function drawFormat(callable $set, int $size, int $mask): void
    {
        $data = (0 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
        $set(8, $size - 8, true);
    }

    private static function applyMask(array $mod, array $fn, int $mask, int $size): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($fn[$y][$x]) continue;
                $inv = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                };
                if ($inv) $mod[$y][$x] = !$mod[$y][$x];
            }
        }
        return $mod;
    }

    /** The standard mask penalty (runs, 2x2 blocks, finder look-alikes, balance). */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        $lines = [];
        for ($y = 0; $y < $size; $y++) $lines[] = $m[$y];
        for ($x = 0; $x < $size; $x++) $lines[] = array_column($m, $x);
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i <= $size; $i++) {
                if ($i < $size && $line[$i] === $line[$i - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) $score += 3 + ($run - 5);
                $run = 1;
            }
            $s = implode('', array_map(fn($b) => $b ? '1' : '0', $line));
            $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($m[$y][$x]) $dark++;
                if ($x < $size - 1 && $y < $size - 1) {
                    $c = $m[$y][$x];
                    if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) $score += 3;
                }
            }
        }
        $total = $size * $size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $score + max(0, $k) * 10;
    }

    /** Split into blocks, add Reed-Solomon codewords, interleave. */
    private static function addEcc(array $data, int $ver): array
    {
        $numBlocks = self::ECC_BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::rawModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $div = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $n = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $n);
            $k += $n;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $numShort) $dat[] = 0;
            $blocks[] = array_merge($dat, $ecc);
        }
        $out = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $b) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) $out[] = $b[$i];
            }
        }
        return $out;
    }

    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) $r[$j] ^= $r[$j + 1];
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $coef) $r[$i] ^= self::gfMul($coef, $factor);
        }
        return $r;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }
}
