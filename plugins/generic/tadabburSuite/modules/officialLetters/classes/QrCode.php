<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * مولّد رموز QR مستقل (Model 2، وضع البايت، مستوى تصحيح M، الإصدارات 1–20) — بلا مكتبات خارجية.
 * مبني على الخوارزمية القياسية ISO/IEC 18004؛ يُخرج SVG أو PNG (عبر GD إن توفّر).
 */
namespace APP\plugins\generic\tadabburSuite\m\officialLetters\classes;

class QrCode
{
    /** [ecc per block, g1 blocks, g1 data cw, g2 blocks, g2 data cw] — مستوى M */
    private const ECC_M = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0], 6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41], 15 => [24, 5, 41, 5, 42], 16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44], 19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
        9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    private int $size;
    private array $m = [];   // [y][x] bool
    private array $fn = [];  // [y][x] bool

    /** @return bool[][] مصفوفة الوحدات [صف][عمود] (true = داكن) */
    public static function matrix(string $text): array
    {
        $q = new self();
        return $q->encode($text);
    }

    private function encode(string $text): array
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $len = count($data);
        $version = 0;
        foreach (self::ECC_M as $v => $t) {
            $cap = $t[1] * $t[2] + $t[3] * $t[4];
            $bits = 4 + ($v < 10 ? 8 : 16) + 8 * $len;
            if ($bits <= $cap * 8) { $version = $v; break; }
        }
        if (!$version) throw new \InvalidArgumentException('QR: النص أطول من السعة المدعومة.');
        [$ecc, $b1, $d1, $b2, $d2] = self::ECC_M[$version];
        $capBits = ($b1 * $d1 + $b2 * $d2) * 8;

        // تدفق البتات
        $bs = [];
        $put = function (int $val, int $n) use (&$bs) { for ($i = $n - 1; $i >= 0; $i--) $bs[] = ($val >> $i) & 1; };
        $put(4, 4);
        $put($len, $version < 10 ? 8 : 16);
        foreach ($data as $b) $put($b, 8);
        $put(0, min(4, $capBits - count($bs)));
        $put(0, (8 - count($bs) % 8) % 8);
        for ($pad = 0xEC; count($bs) < $capBits; $pad ^= 0xEC ^ 0x11) $put($pad, 8);
        $cw = [];
        for ($i = 0; $i < count($bs); $i += 8) {
            $v = 0; for ($j = 0; $j < 8; $j++) $v = ($v << 1) | $bs[$i + $j];
            $cw[] = $v;
        }

        // الكتل وتصحيح الأخطاء
        $div = self::rsDivisor($ecc);
        $blocks = []; $eccs = []; $k = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$n, $dl]) {
            for ($i = 0; $i < $n; $i++) {
                $blk = array_slice($cw, $k, $dl); $k += $dl;
                $blocks[] = $blk; $eccs[] = self::rsRemainder($blk, $div);
            }
        }
        $final = [];
        $maxD = max($d1, $d2);
        for ($i = 0; $i < $maxD; $i++) foreach ($blocks as $blk) if ($i < count($blk)) $final[] = $blk[$i];
        for ($i = 0; $i < $ecc; $i++) foreach ($eccs as $e) $final[] = $e[$i];

        // الرسم
        $this->size = $version * 4 + 17;
        $s = $this->size;
        $this->m = array_fill(0, $s, array_fill(0, $s, false));
        $this->fn = array_fill(0, $s, array_fill(0, $s, false));
        for ($i = 0; $i < $s; $i++) { $this->set(6, $i, $i % 2 === 0); $this->set($i, 6, $i % 2 === 0); }
        $this->finder(3, 3); $this->finder($s - 4, 3); $this->finder(3, $s - 4);
        $al = self::ALIGN[$version]; $na = count($al);
        for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
        }
        $this->format(0);
        $this->versionBits($version);
        $this->place($final);

        // اختيار القناع الأقل عقوبة
        $best = null; $bestScore = PHP_INT_MAX; $base = $this->m;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->m = $base;
            $this->applyMask($mask);
            $this->format($mask);
            $score = $this->penalty();
            if ($score < $bestScore) { $bestScore = $score; $best = $this->m; }
        }
        return $best;
    }

    private function set(int $x, int $y, bool $dark): void { $this->m[$y][$x] = $dark; $this->fn[$y][$x] = true; }

    private function finder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $xx = $x + $dx; $yy = $y + $dy;
            if ($xx < 0 || $yy < 0 || $xx >= $this->size || $yy >= $this->size) continue;
            $d = max(abs($dx), abs($dy));
            $this->set($xx, $yy, $d !== 2 && $d !== 4);
        }
    }

    private function format(int $mask): void
    {
        $data = (0 << 3) | $mask; // مستوى M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
        $b = fn(int $i) => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $b($i));
        $this->set(8, 7, $b(6)); $this->set(8, 8, $b(7)); $this->set(7, 8, $b(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $b($i));
        for ($i = 0; $i < 8; $i++) $this->set($s - 1 - $i, 8, $b($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $s - 15 + $i, $b($i));
        $this->set(8, $s - 8, true);
    }

    private function versionBits(int $v): void
    {
        if ($v < 7) return;
        $rem = $v;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        $bits = ($v << 12) | ($rem & 0xFFF);
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3; $b = intdiv($i, 3);
            $this->set($a, $b, $bit); $this->set($b, $a, $bit);
        }
    }

    private function place(array $data): void
    {
        $s = $this->size; $i = 0; $total = count($data) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $up = (($right + 1) & 2) === 0;
                $y = $up ? $s - 1 - $vert : $vert;
                if (!$this->fn[$y][$x] && $i < $total) {
                    $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) {
            if ($this->fn[$y][$x]) continue;
            switch ($mask) {
                case 0: $inv = ($x + $y) % 2 === 0; break;
                case 1: $inv = $y % 2 === 0; break;
                case 2: $inv = $x % 3 === 0; break;
                case 3: $inv = ($x + $y) % 3 === 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
                case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                default: $inv = (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0;
            }
            if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
        }
    }

    private function penalty(): int
    {
        $s = $this->size; $p = 0; $dark = 0;
        $lines = [];
        for ($y = 0; $y < $s; $y++) {
            $r = ''; $c = '';
            for ($x = 0; $x < $s; $x++) { $r .= $this->m[$y][$x] ? '1' : '0'; $c .= $this->m[$x][$y] ? '1' : '0'; if ($this->m[$y][$x]) $dark++; }
            $lines[] = $r; $lines[] = $c;
        }
        foreach ($lines as $l) {
            if (preg_match_all('/0{5,}|1{5,}/', $l, $mm)) foreach ($mm[0] as $run) $p += 3 + strlen($run) - 5;
            $p += 40 * (substr_count('0000' . $l . '0000', '10111010000') + substr_count('0000' . $l . '0000', '00001011101'));
        }
        for ($y = 0; $y < $s - 1; $y++) for ($x = 0; $x < $s - 1; $x++) {
            $v = $this->m[$y][$x];
            if ($v === $this->m[$y][$x + 1] && $v === $this->m[$y + 1][$x] && $v === $this->m[$y + 1][$x + 1]) $p += 3;
        }
        $total = $s * $s;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $p + max(0, $k) * 10;
    }

    private static function gmul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ ((($z >> 7) & 1) * 0x11D)) & 0xFF; $z ^= (($y >> $i) & 1) * $x; }
        return $z;
    }

    private static function rsDivisor(int $deg): array
    {
        $r = array_fill(0, $deg, 0); $r[$deg - 1] = 1; $root = 1;
        for ($i = 0; $i < $deg; $i++) {
            for ($j = 0; $j < $deg; $j++) { $r[$j] = self::gmul($r[$j], $root); if ($j + 1 < $deg) $r[$j] ^= $r[$j + 1]; }
            $root = self::gmul($root, 2);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($r); $r[] = 0;
            foreach ($div as $i => $d) $r[$i] ^= self::gmul($d, $f);
        }
        return $r;
    }

    /** SVG متجه (هامش 4 وحدات) */
    public static function svg(string $text, string $dark = '#000', string $light = '#fff'): string
    {
        $m = self::matrix($text); $n = count($m); $b = 4; $w = $n + 2 * $b; $d = '';
        foreach ($m as $y => $row) foreach ($row as $x => $v) if ($v) $d .= 'M' . ($x + $b) . ' ' . ($y + $b) . 'h1v1h-1z';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $w . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="' . $light . '"/><path fill="' . $dark . '" d="' . $d . '"/></svg>';
    }

    /** PNG عبر GD (يعيد null إن لم تتوفر GD) */
    public static function png(string $text, int $scale = 10): ?string
    {
        if (!function_exists('imagecreate')) return null;
        $m = self::matrix($text); $n = count($m); $b = 4; $w = ($n + 2 * $b) * $scale;
        $im = imagecreate($w, $w);
        $white = imagecolorallocate($im, 255, 255, 255); $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);
        foreach ($m as $y => $row) foreach ($row as $x => $v) if ($v) imagefilledrectangle($im, ($x + $b) * $scale, ($y + $b) * $scale, ($x + $b + 1) * $scale - 1, ($y + $b + 1) * $scale - 1, $black);
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return $png;
    }
}
