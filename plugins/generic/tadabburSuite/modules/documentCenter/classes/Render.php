<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/documentCenter/classes/Render.php
 * توليد PDF: خطابات على ورق المجلة بالتوقيع والختم وQR، وشهادات مرسومة (برمجيًا) بزخرفة المجلة.
 */
namespace APP\plugins\generic\tadabburSuite\m\documentCenter\classes;

use APP\plugins\generic\tadabburSuite\classes\Branding;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Letters;
use APP\plugins\generic\tadabburSuite\m\officialLetters\classes\QrCode;

class Render
{
    private string $ol;   // مجلد أصول officialLetters
    private string $rc;   // مجلد أصول reviewerCertificate

    public function __construct(private string $tempDir)
    {
        $this->ol = Modules::root() . '/officialLetters/assets';
        $this->rc = Modules::root() . '/reviewerCertificate/assets';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0775, true);
    }

    public static function available(): bool { return class_exists(\Mpdf\Mpdf::class); }
    public static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function ap(string $f): string { return Branding::path($f, $this->ol . '/' . $f); }
    private function tx(string $k, array $p, string $lang): string { return __('plugins.generic.documentCenter.tx.' . $k, $p, $lang === 'en' ? 'en' : 'ar'); }

    private function mpdf(array $o): \Mpdf\Mpdf
    {
        if (!self::available()) throw new \RuntimeException('mPDF is not available on this server.');
        $c = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $f = (new \Mpdf\Config\FontVariables())->getDefaults();
        $lang = ($o['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        return new \Mpdf\Mpdf($o['mpdf'] + [
            'mode' => 'utf-8', 'margin_header' => 0, 'margin_footer' => 0, 'tempDir' => $this->tempDir, 'fontDir' => array_merge($c['fontDir'], [$this->ol]),
            'fontdata' => $f['fontdata'] + ['plexar' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF]],
            'default_font' => 'plexar', 'useKerning' => false, 'directionality' => $lang === 'en' ? 'ltr' : 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => false,
        ]);
    }

    // ================================================================ خطاب على ورق المجلة
    /** @param array{title:string,body:string,lang:string,number:string,date:string,code:string,verifyUrl:string,signature:bool,journal:string} $o */
    public function letter(array $o): string
    {
        $lang = ($o['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $mpdf = $this->mpdf(['lang' => $lang, 'mpdf' => ['format' => 'A4', 'margin_left' => 22, 'margin_right' => 22, 'margin_top' => 50, 'margin_bottom' => 74]]);
        $mpdf->SetTitle($o['title'] . ' — ' . $o['number']); $mpdf->SetAuthor($o['journal'] ?? ''); $mpdf->SetCreator('OJS documentCenter');
        $mpdf->SetDefaultBodyCSS('background', "url('" . $this->ap('letterhead.jpg') . "')");
        $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
        $mpdf->AddPage();
        $dir = $lang === 'en' ? 'ltr' : 'rtl'; $al = $lang === 'en' ? 'left' : 'right';
        $mpdf->WriteHTML('<div style="font-feature-settings:\'locl\' 0;direction:' . $dir . ';text-align:' . $al . '">'
            . '<div style="text-align:center;font-size:17pt;font-weight:bold;color:#3c4a78;margin-bottom:5mm">' . self::e($o['title']) . '</div>' . $o['body'] . '</div>');
        $yBody = $mpdf->y;
        $date = substr((string) $o['date'], 0, 10);
        $field = fn ($html, $y) => $mpdf->WriteFixedPosHTML('<div style="font-size:8.6pt;color:#222;text-align:center;direction:ltr">' . $html . '</div>', 15, $y, 33, 6, 'visible');
        $field(self::e($o['number']), 15.6);
        $field(self::e($date), 21.6);
        $mpdf->WriteFixedPosHTML('<div style="font-size:7.6pt;color:#222;text-align:center;direction:rtl">' . self::e(Letters::hijri($date, 'ar')) . '</div>', 15, 25.4, 33, 5, 'visible');
        $field('—', 31.6);
        if (!empty($o['signature'])) {
            $y = min(max($yBody + 4, 150), 197);
            $mpdf->WriteFixedPosHTML('<div style="text-align:center;font-size:11pt;font-weight:bold">' . self::e($this->tx('signer', [], $lang)) . '</div>', 22, $y, 70, 6, 'visible');
            $mpdf->Image($this->ap('name_editor.png'), 26, $y + 6, 62, 0, 'png', '', true, false);
            $mpdf->Image($this->ap('signature.png'), 38, $y + 21, 36, 0, 'png', '', true, false);
            $mpdf->Image($this->ap('stamp.png'), 84, $y + 14, 34, 0, 'png', '', true, false);
        }
        $this->qr($mpdf, $o['verifyUrl'], $o['code'], 152, 228, $lang, true);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function qr(\Mpdf\Mpdf $mpdf, string $url, string $code, float $x, float $y, string $lang, bool $white): void
    {
        $png = $url !== '' ? QrCode::png($url, 8) : null;
        if ($png === null) return;
        $key = 'qr' . md5($code . $x);
        $mpdf->imageVars[$key] = $png;
        if ($white) { $mpdf->SetFillColor(255, 255, 255); $mpdf->Rect($x - 2, $y - 2, 40, 40, 'F'); }
        $mpdf->Image('var:' . $key, $x, $y, 24, 24, 'png', '', true, false);
        $mpdf->WriteFixedPosHTML('<div style="font-size:7.4pt;color:#333;text-align:center;line-height:1.35">' . self::e($this->tx('verifyHint', [], $lang)) . '<br><span style="direction:ltr;font-size:7pt">' . self::e(implode('-', str_split($code, 5))) . '</span></div>', $x - 6, $y + 25, 36, 12, 'visible');
    }

    // ================================================================ شهادة مرسومة (A4 أفقي)
    /** @param array{lang:string,heading:string,honorific:string,name:string,bodyText:string,date:string,number:string,code:string,verifyUrl:string,journal:string,signature:bool,sub:string} $o */
    public function certificate(array $o): string
    {
        $lang = ($o['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $mpdf = $this->mpdf(['lang' => $lang, 'mpdf' => ['format' => 'A4-L', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0]]);
        $mpdf->SetTitle($o['heading'] . ' — ' . $o['number']); $mpdf->SetAuthor($o['journal']); $mpdf->SetCreator('OJS documentCenter');
        $mpdf->AddPage();
        [$br, $bg, $bb] = [67, 38, 15]; [$gr, $gg, $gb] = [197, 161, 88];
        $mpdf->SetFillColor(255, 253, 248); $mpdf->Rect(0, 0, 297, 210, 'F');
        $mpdf->SetDrawColor($br, $bg, $bb); $mpdf->SetLineWidth(1.6); $mpdf->Rect(8, 8, 281, 194, 'D');
        $mpdf->SetDrawColor($gr, $gg, $gb); $mpdf->SetLineWidth(0.7); $mpdf->Rect(11.5, 11.5, 274, 187, 'D');
        $mpdf->SetDrawColor($br, $bg, $bb); $mpdf->SetLineWidth(0.25); $mpdf->Rect(14, 14, 269, 182, 'D');
        $mpdf->SetFillColor($gr, $gg, $gb);
        foreach ([[14, 14], [283, 14], [14, 196], [283, 196]] as [$cx, $cy]) { $mpdf->Rect($cx - 3, $cy - 3, 6, 6, 'F'); }
        $logo = $this->rc . '/logo_tadabbur.png';
        if (is_file($logo)) $mpdf->Image($logo, 133.5, 18, 30, 0, 'png', '', true, false);
        $mid = fn ($html, $y, $h, $style = '') => $mpdf->WriteFixedPosHTML('<div style="text-align:center;direction:' . ($lang === 'en' ? 'ltr' : 'rtl') . ';' . $style . '">' . $html . '</div>', 24, $y, 249, $h, 'visible');
        $mid(self::e($o['journal']), 49, 10, 'font-size:17pt;font-weight:bold;color:#43260f');
        $mid(self::e($o['heading']), 61, 20, 'font-size:34pt;font-weight:bold;color:#9a7633');
        $mid(self::e($o['sub']), 83, 8, 'font-size:13pt;color:#444');
        $full = trim(($o['honorific'] !== '' ? $o['honorific'] . ' ' : '') . $o['name']);
        $mid(self::e($full), 93, 17, 'font-size:27pt;font-weight:bold;color:#111');
        $mpdf->SetDrawColor($gr, $gg, $gb); $mpdf->SetLineWidth(0.5); $mpdf->Line(88, 110, 209, 110);
        $mpdf->WriteFixedPosHTML('<div style="text-align:center;direction:' . ($lang === 'en' ? 'ltr' : 'rtl') . ';font-size:13.5pt;line-height:1.95;color:#222">' . $o['bodyText'] . '</div>', 38, 114, 221, 40, 'visible');
        $mpdf->WriteFixedPosHTML('<div style="font-size:10.5pt;color:#444;text-align:center;direction:ltr">' . self::e($o['number']) . '<br>' . self::e(substr($o['date'], 0, 10)) . '</div><div style="font-size:10pt;color:#444;text-align:center;direction:rtl">' . self::e(Letters::hijri(substr($o['date'], 0, 10), 'ar')) . '</div>', 118, 178, 60, 14, 'visible');
        if (!empty($o['signature'])) {
            $mpdf->WriteFixedPosHTML('<div style="text-align:center;font-size:10.5pt;font-weight:bold">' . self::e($this->tx('signer', [], $lang)) . '</div>', 196, 158, 70, 6, 'visible');
            $mpdf->Image($this->ap('name_editor.png'), 200, 164, 62, 0, 'png', '', true, false);
            $mpdf->Image($this->ap('signature.png'), 212, 176, 36, 0, 'png', '', true, false);
            $mpdf->Image($this->ap('stamp.png'), 164, 168, 28, 0, 'png', '', true, false);
        }
        $this->qr($mpdf, $o['verifyUrl'], $o['code'], 32, 158, $lang, false);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    // ================================================================ نصوص الخطابات (HTML داخل الورق)
    public function body(string $type, array $d, string $lang): string
    {
        $e = [self::class, 'e'];
        $p = ['journal' => self::e($d['journal'] ?? ''), 'title' => self::e($d['title'] ?? ''), 'id' => (int) ($d['sid'] ?? 0), 'name' => self::e($d['name'] ?? ''), 'date' => self::e($d['completed'] ?? '')];
        $salute = '<p style="font-size:12.5pt;font-weight:bold;margin:0 0 3mm">' . self::e($this->tx('salute', ['name' => trim(($d['honorific'] ?? '') . ' ' . ($d['name'] ?? ''))], $lang)) . '</p>';
        $para = fn (string $k) => '<p style="font-size:12.5pt;line-height:1.9;margin:0 0 4mm">' . $this->tx($k, $p, $lang) . '</p>';
        $note = trim((string) ($d['note'] ?? ''));
        $noteHtml = $note !== '' ? '<p style="font-size:12pt;line-height:1.85;margin:0 0 4mm;padding:3mm 4mm;background:#f6f1e7;border-right:1.2mm solid #c5a158">' . nl2br(self::e($note)) . '</p>' : '';
        $closing = '<p style="font-size:12.5pt;margin:4mm 0 0">' . $this->tx('closing', [], $lang) . '</p>';
        return match ($type) {
            'rev_thanks' => $salute . $para('rev_thanks.body') . $noteHtml . $closing,
            'au_apology' => $salute . $para('au_apology.body') . $noteHtml . $para('au_apology.hope') . $closing,
            'au_reviews' => $salute . $para('au_reviews.body') . ($d['reviewsHtml'] ?? '') . $noteHtml . $closing,
            default => $salute . $noteHtml . $closing,
        };
    }

    /** نص الشهادة وعنوانها الفرعي */
    public function certText(string $type, array $d, string $lang): array
    {
        $p = ['journal' => self::e($d['journal'] ?? ''), 'title' => self::e($d['title'] ?? ''), 'id' => (int) ($d['sid'] ?? 0), 'name' => self::e($d['name'] ?? '')];
        return ['heading' => $this->tx($type . '.heading', [], $lang), 'sub' => $this->tx($type . '.sub', [], $lang), 'body' => $this->tx($type . '.body', $p, $lang)];
    }
}
