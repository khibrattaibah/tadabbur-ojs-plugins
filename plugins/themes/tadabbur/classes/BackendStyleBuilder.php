<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * يبني أنماط لوحة التحكم بهوية المجلة: يأخذ styles/build.css المثبت في OJS ويستبدل ألوان PKP الأساسية
 * بألوان الهوية مع الحفاظ على ترتيب القواعد، ثم يحل محله. لذلك يبقى متوافقًا مع أي تحديث صغير لـ OJS 3.5.
 */
namespace APP\plugins\themes\tadabbur\classes;

class BackendStyleBuilder
{
    private array $map;

    public function __construct(private string $buildCss, private string $primary, private string $accent, private string $secondary, private string $assetsUrl, private string $stylesUrl = '')
    {
        $dark = self::shade($primary, -0.35);
        $hover = self::shade($primary, 0.18);
        $heading = self::shade($primary, -0.2);
        $light = self::mix($accent, '#ffffff', 0.22);
        // [قيمة PKP => قيمة الهوية]
        $this->map = [
            '006798' => $primary, '0082bf' => $hover, '002c40' => $dark, '01354f' => $heading, 'd5e9f2' => $light,
        ];
    }

    private static function rgb(string $hex): array { $h = ltrim($hex, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }
    private static function hex(array $c): string { return sprintf('#%02x%02x%02x', ...array_map(fn ($v) => max(0, min(255, (int) round($v))), $c)); }
    public static function shade(string $hex, float $amt): string
    {
        $c = self::rgb($hex);
        return self::hex(array_map(fn ($v) => $amt < 0 ? $v * (1 + $amt) : $v + (255 - $v) * $amt, $c));
    }
    public static function mix(string $a, string $b, float $wa): string
    {
        $x = self::rgb($a); $y = self::rgb($b);
        return self::hex([$x[0] * $wa + $y[0] * (1 - $wa), $x[1] * $wa + $y[1] * (1 - $wa), $x[2] * $wa + $y[2] * (1 - $wa)]);
    }

    /** يعيد تلوين نص تصريحات CSS؛ يعيد null إن لم يتضمن لونًا من ألوان PKP */
    private function recolour(string $decl): ?string
    {
        $hit = false;
        foreach ($this->map as $from => $to) {
            [$r, $g, $b] = self::rgb($from); [$R, $G, $B] = self::rgb($to);
            $patterns = [
                '/#' . $from . '\b/i' => $to,
                '/\b' . $r . ' ' . $g . ' ' . $b . '\b/' => "$R $G $B",
                '/\b' . $r . ',\s*' . $g . ',\s*' . $b . '\b/' => "$R,$G,$B",
            ];
            foreach ($patterns as $re => $rep) {
                $decl = preg_replace($re, $rep, $decl, -1, $n);
                if ($n) $hit = true;
            }
        }
        return $hit ? $decl : null;
    }

    public function build(): string
    {
        $src = is_file($this->buildCss) ? (string) file_get_contents($this->buildCss) : '';
        // نسخة كاملة من build.css بالألوان الجديدة (تحافظ على ترتيب القواعد)، مع تحويل الروابط النسبية إلى مطلقة
        $recoloured = $src !== '' ? ($this->recolour($src) ?? $src) : '';
        $recoloured = preg_replace('#url\((["\']?)(?!data:|https?:|/)([^)"\']+)\1\)#i', 'url($1' . $this->stylesUrl . '/$2$1)', $recoloured);
        $p = $this->primary; $a = $this->accent; $dark = self::shade($p, -0.35);
        $f = $this->assetsUrl . '/fonts/';
        $extra = '@font-face{font-family:"IBM Plex Sans Arabic";font-weight:400;font-style:normal;font-display:swap;src:url("' . $f . 'IBMPlexSansArabic-Regular.woff2") format("woff2")}'
            . '@font-face{font-family:"IBM Plex Sans Arabic";font-weight:700;font-style:normal;font-display:swap;src:url("' . $f . 'IBMPlexSansArabic-Bold.woff2") format("woff2")}'
            . 'body,.app,.pkpFormField,.pkpButton,button,input,select,textarea{font-family:"IBM Plex Sans Arabic",-apple-system,"Segoe UI",Tahoma,sans-serif}'
            . '.app__header{border-bottom:3px solid ' . $a . ';box-shadow:0 1px 0 rgba(0,0,0,.08)}'
            . '.app__contextTitle,.app__header .app__contextTitle{font-weight:700;letter-spacing:0}'
            . 'a:focus-visible,button:focus-visible,[tabindex]:focus-visible{outline:2px solid ' . $a . ';outline-offset:2px}'
            . '::selection{background:' . self::mix($a, '#ffffff', 0.35) . ';color:' . $dark . '}'
            . '.app__nav a[aria-current=page],.app__nav .is-active{box-shadow:inset 0 0 0 0 transparent}';
        return $recoloured . "\n/* Tadabbur theme — backend palette (generated from styles/build.css) */\n" . $extra;
    }
}
