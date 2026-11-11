<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * يرسم شهادة الشكر والتقدير بتصميم مجلة تدبر (1579×1194 نقطة):
 *  - html(): صفحة HTML للطباعة/الحفظ PDF من المتصفح.
 *  - pdf():  ملف PDF على الخادم عبر mPDF (مضمّنة في OJS 3.5) مع تشكيل عربي صحيح.
 * لكل شهادة: لقب، اسم، تاريخ، رقم فريد، ورمز QR لصفحة التحقق.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes;

class CertificateRenderer
{
    private const W = 1579; private const H = 1194;   // نقطة
    // المواضع بالنقطة: [يسار، أعلى، عرض، ارتفاع]
    private const POS = [
        'hon' => [0, 455, 1579, 50], 'name' => [0, 502, 1579, 72], 'date' => [1048, 1086, 214, 32],
        'stamp' => [744, 1052, 116, 84], 'sig' => [376, 1048, 133, 69], 'qr' => [1266, 922, 88, 88], 'code' => [1190, 1012, 240, 30],
    ];

    public function __construct(private string $dir) { $this->dir = rtrim($dir, '/'); }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function asset(string $f): string { return $this->ap($f); }
    /** صورة الهوية: المرفوعة من لوحة الحزمة إن وُجدت، وإلا الافتراضية المرفقة */
    private function ap(string $file): string
    {
        $d = $this->dir . '/assets/' . $file;
        return class_exists(\APP\plugins\generic\tadabburSuite\classes\Branding::class) ? \APP\plugins\generic\tadabburSuite\classes\Branding::path($file, $d) : $d;
    }

    private function b64(string $f, string $mime): string { return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($this->asset($f))); }

    public static function pdfAvailable(): bool { return class_exists(\Mpdf\Mpdf::class); }

    /** @param array<int,array{honorific:string,name:string,date:string,code:string,verifyUrl:string}> $certs */
    public function html(array $certs, string $pdfUrl = '', string $title = 'شهادة شكر وتقدير'): string
    {
        $bg = $this->b64('bg.jpg', 'image/jpeg'); $sig = $this->b64('signature.png', 'image/png'); $stamp = $this->b64('stamp.png', 'image/png');
        $fr = $this->b64('IBMPlexSansArabic-Regular.ttf', 'font/ttf'); $fb = $this->b64('IBMPlexSansArabic-Bold.ttf', 'font/ttf');
        $p = self::POS; $box = fn (string $k) => 'left:' . $p[$k][0] . 'pt;top:' . $p[$k][1] . 'pt;width:' . $p[$k][2] . 'pt;height:' . $p[$k][3] . 'pt';
        $pages = '';
        foreach ($certs as $c) {
            $h = trim($c['honorific']) !== '' ? self::e(trim($c['honorific'])) . '/' : '';
            $pages .= '<div class="holder"><section class="sheet">'
                . '<div class="hon" style="' . $box('hon') . '">' . $h . '</div>'
                . '<div class="nm" style="' . $box('name') . '">' . self::e($c['name']) . '</div>'
                . '<div class="dt" style="' . $box('date') . '">' . self::e($c['date']) . '</div>'
                . '<div class="stamp" style="' . $box('stamp') . '"><img src="' . $stamp . '" alt=""></div>'
                . '<img class="sig" style="' . $box('sig') . '" src="' . $sig . '" alt="">'
                . ($c['code'] !== '' ? '<div class="qr" style="' . $box('qr') . '">' . QrCode::svg($c['verifyUrl']) . '</div>'
                    . '<div class="code" style="' . $box('code') . '">رقم الشهادة للتحقق<br><span dir="ltr">' . self::e($c['code']) . '</span></div>' : '')
                . '</section></div>';
        }
        $css = '@font-face{font-family:CertFont;font-weight:400;src:url(' . $fr . ') format("truetype")}@font-face{font-family:CertFont;font-weight:700;src:url(' . $fb . ') format("truetype")}'
            . '@page{size:' . self::W . 'pt ' . self::H . 'pt;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#e9e9ee}'
            . 'body{font-family:CertFont,Tahoma,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.bar{position:sticky;top:0;z-index:9;background:#1f2a44;color:#fff;padding:10px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;font:14px CertFont,sans-serif;direction:rtl}'
            . '.bar a,.bar button{background:#c9a24a;border:0;color:#1f2a44;font:700 14px CertFont,sans-serif;padding:8px 18px;border-radius:6px;cursor:pointer;text-decoration:none}'
            . '.wrap{padding:16px}.holder{margin:0 auto 16px;width:calc(' . self::W . 'pt*var(--k,1));height:calc(' . self::H . 'pt*var(--k,1))}'
            . '.sheet{position:relative;width:' . self::W . 'pt;height:' . self::H . 'pt;background:#fff url(' . $bg . ') center/100% 100% no-repeat;overflow:hidden;transform:scale(var(--k,1));transform-origin:top right;page-break-after:always;break-after:page;direction:rtl}'
            . '.sheet>div,.sheet>img{position:absolute}.hon{text-align:center;font-size:29pt;color:#111;line-height:1.4}.nm{text-align:center;font-size:43pt;font-weight:700;color:#000;line-height:1.5}'
            . '.dt{text-align:center;font-size:20pt;direction:ltr;line-height:1.3}.stamp{border:3pt solid #000;background:#fff;display:flex;align-items:center;justify-content:center;padding:3pt}.stamp img{max-width:100%;max-height:100%}'
            . '.qr svg{width:100%;height:100%;display:block}.code{text-align:center;font-size:8.5pt;color:#333;line-height:1.35}'
            . '@media print{html,body{background:#fff}.bar{display:none}.wrap{padding:0}.holder{width:' . self::W . 'pt;height:' . self::H . 'pt;margin:0}.sheet{transform:none}}';
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . self::e($title) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="bar"><span>' . count($certs) . ' شهادة</span>' . ($pdfUrl !== '' ? '<a href="' . self::e($pdfUrl) . '">تنزيل PDF</a>' : '')
            . '<button onclick="window.print()">طباعة / حفظ PDF من المتصفح</button><span style="opacity:.8;font-size:12px">عند الطباعة فعّل «رسومات الخلفية».</span></div><div class="wrap">' . $pages . '</div>'
            . '<script>(function(){function f(){var k=Math.min(1,(window.innerWidth-32)/(' . self::W . '*96/72));document.documentElement.style.setProperty("--k",k)}f();addEventListener("resize",f)})();</script></body></html>';
    }

    /** @param array<int,array{honorific:string,name:string,date:string,code:string,verifyUrl:string}> $certs */
    public function pdf(array $certs, string $tempDir): string
    {
        if (!self::pdfAvailable()) throw new \RuntimeException('مكتبة mPDF غير متاحة في هذا الخادم.');
        if (!is_dir($tempDir)) @mkdir($tempDir, 0775, true);
        $pt = 25.4 / 72; $P = fn ($v) => $v * $pt;
        $cfg = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fonts = (new \Mpdf\Config\FontVariables())->getDefaults();
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => [$P(self::W), $P(self::H)],
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0,
            'tempDir' => $tempDir, 'fontDir' => array_merge($cfg['fontDir'], [$this->dir . '/assets']),
            'fontdata' => $fonts['fontdata'] + ['plexar' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF]],
            'default_font' => 'plexar', 'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => false,
        ]);
        $mpdf->SetTitle('شهادة شكر وتقدير'); $mpdf->SetAuthor('مجلة تدبر'); $mpdf->SetCreator('OJS reviewerCertificate');
        $p = self::POS;
        foreach ($certs as $c) {
            $mpdf->AddPage();
            $mpdf->Image($this->asset('bg.jpg'), 0, 0, $P(self::W), $P(self::H), 'jpg', '', true, false);
            $h = trim($c['honorific']) !== '' ? self::e(trim($c['honorific'])) . '/' : '';
            $txt = function (string $k, string $html, string $style) use ($mpdf, $p, $P) {
                $mpdf->WriteFixedPosHTML('<div style="text-align:center;' . $style . '">' . $html . '</div>', $P($p[$k][0]), $P($p[$k][1]), $P($p[$k][2]), $P($p[$k][3]), 'visible');
            };
            if ($h !== '') $txt('hon', $h, 'font-size:29pt;color:#111;direction:rtl');
            $txt('name', self::e($c['name']), 'font-size:43pt;font-weight:bold;color:#000;direction:rtl');
            $txt('date', self::e($c['date']), 'font-size:20pt;direction:ltr');
            [$x, $y, $w, $hh] = $p['stamp'];
            $mpdf->SetLineWidth($P(3)); $mpdf->SetDrawColor(0, 0, 0); $mpdf->SetFillColor(255, 255, 255);
            $mpdf->Rect($P($x), $P($y), $P($w), $P($hh), 'DF');
            $mpdf->Image($this->asset('stamp.png'), $P($x + 6), $P($y + 5), $P($w - 12), $P($hh - 10), 'png', '', true, false);
            $mpdf->Image($this->asset('signature.png'), $P($p['sig'][0]), $P($p['sig'][1]), $P($p['sig'][2]), $P($p['sig'][3]), 'png', '', true, false);
            if ($c['code'] !== '') {
                $png = QrCode::png($c['verifyUrl'], 8);
                if ($png !== null) {
                    $mpdf->imageVars['qr' . md5($c['code'])] = $png;
                    $mpdf->Image('var:qr' . md5($c['code']), $P($p['qr'][0]), $P($p['qr'][1]), $P($p['qr'][2]), $P($p['qr'][3]), 'png', '', true, false);
                }
                $txt('code', 'رقم الشهادة للتحقق<br><span style="font-size:7.5pt">' . self::e($c['code']) . '</span>', 'font-size:8.5pt;color:#333;direction:rtl');
            }
        }
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
