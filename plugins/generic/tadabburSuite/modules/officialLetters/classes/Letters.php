<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/officialLetters/classes/Letters.php
 *
 * أهلية الخطابات، ولقطة بيانات البحث وقت الإصدار، وتوليد PDF على ورق المجلة الرسمي
 * (رقم الصادر والتاريخ الهجري والميلادي في حقول الترويسة، والتوقيع والختم، ورمز QR للتحقق).
 */
namespace APP\plugins\generic\tadabburSuite\m\officialLetters\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\decision\Decision;

class Letters
{
    public function __construct(private string $dir) {}


    /** صورة الهوية: المرفوعة من لوحة الحزمة إن وُجدت، وإلا الافتراضية المرفقة */
    private function ap(string $file): string
    {
        $d = $this->dir . '/assets/' . $file;
        return class_exists(\APP\plugins\generic\tadabburSuite\classes\Branding::class) ? \APP\plugins\generic\tadabburSuite\classes\Branding::path($file, $d) : $d;
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p, string $lang): string { return __('plugins.generic.officialLetters.' . $k, $p, $lang === 'en' ? 'en' : 'ar'); }

    public static function pdfAvailable(): bool { return class_exists(\Mpdf\Mpdf::class); }

    /** الأنواع المتاحة للبحث الآن */
    public static function eligible($submission): array
    {
        $out = [];
        if ((string) $submission->getData('submissionProgress') === '' && $submission->getData('dateSubmitted') && (int) $submission->getData('status') !== 4) $out[] = 'receipt';
        if (self::acceptedDate($submission) !== null && (int) $submission->getData('status') !== 4) $out[] = 'acceptance';
        if ((int) $submission->getData('status') === 3) $out[] = 'publication';
        return $out;
    }

    public static function acceptedDate($submission): ?string
    {
        $d = DB::table('edit_decisions')->where('submission_id', $submission->getId())->whereIn('decision', [Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW])->min('date_decided');
        if ($d) return substr((string) $d, 0, 10);
        return in_array((int) $submission->getData('stageId'), [4, 5], true) || (int) $submission->getData('status') === 3 ? substr((string) $submission->getData('lastModified'), 0, 10) : null;
    }

    public static function hijri(string $date, string $lang): string
    {
        if (!class_exists(\IntlDateFormatter::class)) return '';
        $f = new \IntlDateFormatter(($lang === 'en' ? 'en' : 'ar') . '@calendar=islamic-umalqura;numbers=latn', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'd MMMM y');
        $s = $f->format(new \DateTime($date));
        return $s ? $s . ($lang === 'en' ? ' AH' : 'هـ') : '';
    }

    /** تاريخ ميلادي مقروء: 30 سبتمبر 2026 */
    public static function human(?string $date, string $lang): string
    {
        if (!$date) return '';
        if (!class_exists(\IntlDateFormatter::class)) return $date;
        $f = new \IntlDateFormatter(($lang === 'en' ? 'en' : 'ar') . '@numbers=latn', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::GREGORIAN, 'd MMMM y');
        return (string) ($f->format(new \DateTime($date)) ?: $date);
    }

    /** لقطة ثابتة من بيانات البحث */
    public function snapshot($submission, $context, string $lang, string $journalUrl): array
    {
        $pub = $submission->getCurrentPublication();
        $title = strip_tags((string) ($pub->getData('title', $lang) ?: $pub->getLocalizedFullTitle()));
        $sub = strip_tags((string) ($pub->getData('subtitle', $lang) ?: ''));
        $authors = [];
        foreach ($pub->getData('authors') ?? [] as $a) $authors[] = $a->getFullName(true, false, $lang);
        $issue = $pub->getData('issueId') ? Repo::issue()->get((int) $pub->getData('issueId')) : null;
        return [
            'id' => (int) $submission->getId(), 'title' => trim($title . ($sub !== '' ? ': ' . $sub : '')), 'authors' => $authors,
            'submitted' => substr((string) $submission->getData('dateSubmitted'), 0, 10), 'accepted' => self::acceptedDate($submission),
            'published' => substr((string) $pub->getData('datePublished'), 0, 10) ?: null,
            'issue' => $issue ? trim(($issue->getVolume() ? $this->t('vol', ['n' => $issue->getVolume()], $lang) . ' ' : '') . ($issue->getNumber() ? $this->t('no', ['n' => $issue->getNumber()], $lang) . ' ' : '') . ($issue->getYear() ? '(' . $issue->getYear() . ')' : '')) : '',
            'pages' => (string) $pub->getData('pages'), 'doi' => (string) $pub->getDoi(), 'url' => $journalUrl,
            'journal' => (string) ($context->getName($lang) ?: $context->getLocalizedName()), 'issn' => trim(($context->getData('onlineIssn') ? 'e-ISSN ' . $context->getData('onlineIssn') : '') . '  ' . ($context->getData('printIssn') ? 'p-ISSN ' . $context->getData('printIssn') : '')),
        ];
    }

    /** محتوى الخطاب HTML (لـ mPDF) */
    private function bodyHtml(object $letter, array $d, array $cfg): string
    {
        $lang = $letter->lang; $type = $letter->letter_type;
        $p = ['journal' => self::e($d['journal']), 'title' => self::e($d['title']), 'authors' => self::e(implode($lang === 'en' ? ', ' : '، ', $d['authors'])), 'id' => (int) $d['id'],
            'submitted' => self::e(self::human($d['submitted'], $lang)), 'accepted' => self::e(self::human($d['accepted'] ?? null, $lang)), 'published' => self::e(self::human($d['published'] ?? null, $lang)), 'issue' => self::e($d['issue'])];
        $row = fn ($k, $v) => $v === '' || $v === null ? '' : '<tr><td style="width:32%;padding:2.2mm 3mm;background:#f5f1e8;border:0.3mm solid #d9cfbd;font-weight:bold">' . $this->t('f.' . $k, [], $lang) . '</td><td style="padding:2.2mm 3mm;border:0.3mm solid #d9cfbd">' . $v . '</td></tr>';
        $rows = $row('title', '<b>' . $p['title'] . '</b>') . $row('authors', $p['authors']) . $row('id', (string) $d['id']) . $row('submitted', $p['submitted']);
        if ($type !== 'receipt') $rows .= $row('accepted', $p['accepted']);
        if ($type === 'publication') {
            $rows .= $row('published', $p['published']) . $row('issue', $p['issue']) . $row('pages', self::e($d['pages']))
                . ($d['doi'] !== '' ? $row('doi', '<span style="direction:ltr">https://doi.org/' . self::e($d['doi']) . '</span>') : '');
        } elseif ($type === 'acceptance' && $d['issue'] !== '') {
            $rows .= $row('expectedIssue', $p['issue']);
        }
        $salute = $type === 'receipt' ? $this->t('salute.author', ['name' => self::e($d['authors'][0] ?? '')], $lang) : $this->t('salute.general', [], $lang);
        $note = trim((string) ($cfg['note_' . $lang] ?? ''));
        return '<div style="text-align:center;font-size:17pt;font-weight:bold;color:#3c4a78;margin-bottom:5mm">' . $this->t('title.' . $type, [], $lang) . '</div>'
            . '<p style="font-size:12.5pt;font-weight:bold;margin:0 0 3mm">' . $salute . '</p>'
            . '<p style="font-size:12.5pt;line-height:1.85;margin:0 0 4mm">' . $this->t('body.' . $type, $p, $lang) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:11pt;margin:2mm 0 4mm">' . $rows . '</table>'
            . ($note !== '' ? '<p style="font-size:11.5pt;line-height:1.7;margin:0 0 3mm">' . nl2br(self::e($note)) . '</p>' : '')
            . '<p style="font-size:12.5pt;margin:4mm 0 0">' . $this->t('closing', [], $lang) . '</p>';
    }

    public function pdf(object $letter, array $cfg, string $verifyUrl, string $tempDir): string
    {
        if (!self::pdfAvailable()) throw new \RuntimeException('mPDF is not available on this server.');
        if (!is_dir($tempDir)) @mkdir($tempDir, 0775, true);
        $d = json_decode((string) $letter->data, true) ?: [];
        $lang = $letter->lang === 'en' ? 'en' : 'ar';
        $c = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $f = (new \Mpdf\Config\FontVariables())->getDefaults();
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 22, 'margin_right' => 22, 'margin_top' => 50, 'margin_bottom' => 74, 'margin_header' => 0, 'margin_footer' => 0,
            'tempDir' => $tempDir, 'fontDir' => array_merge($c['fontDir'], [$this->dir . '/assets']),
            'fontdata' => $f['fontdata'] + ['plexar' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF]],
            'default_font' => 'plexar', 'useKerning' => false, 'directionality' => $lang === 'en' ? 'ltr' : 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => false,
        ]);
        $mpdf->SetTitle($this->t('title.' . $letter->letter_type, [], $lang) . ' — ' . $letter->number);
        $mpdf->SetAuthor($d['journal'] ?? ''); $mpdf->SetCreator('OJS officialLetters');
        $mpdf->SetDefaultBodyCSS('background', "url('" . $this->ap('letterhead.jpg') . "')");
        $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
        $mpdf->AddPage();
        $date = substr((string) $letter->issued_at, 0, 10);
        $mpdf->WriteHTML('<div style="font-feature-settings:\'locl\' 0;direction:' . ($lang === 'en' ? 'ltr' : 'rtl') . ';text-align:' . ($lang === 'en' ? 'left' : 'right') . '">' . $this->bodyHtml($letter, $d, $cfg) . '</div>');
        $yBody = $mpdf->y;
        // حقول الترويسة المطبوعة: الرقم، التاريخ، المرفقات
        $field = fn ($html, $y) => $mpdf->WriteFixedPosHTML('<div style="font-size:8.6pt;color:#222;text-align:center;direction:ltr">' . $html . '</div>', 15, $y, 33, 6, 'visible');
        $field(self::e($letter->number), 15.6);
        $field(self::e($date), 21.6);
        $mpdf->WriteFixedPosHTML('<div style="font-size:7.6pt;color:#222;text-align:center;direction:rtl">' . self::e(self::hijri($date, 'ar')) . '</div>', 15, 25.4, 33, 5, 'visible');
        $field('—', 31.6);
        // التوقيع والختم (في الجهة اليسرى، فوق التذييل)
        if (!empty($cfg['signature'])) {
            $y = min(max($yBody + 4, 150), 197);
            $mpdf->WriteFixedPosHTML('<div style="text-align:center;font-size:11pt;font-weight:bold">' . $this->t('signer', [], $lang) . '</div>', 22, $y, 70, 6, 'visible');
            $mpdf->Image($this->ap('name_editor.png'), 26, $y + 6, 62, 0, 'png', '', true, false);
            $mpdf->Image($this->ap('signature.png'), 38, $y + 21, 36, 0, 'png', '', true, false);
            $mpdf->Image($this->ap('stamp.png'), 84, $y + 14, 34, 0, 'png', '', true, false);
        }
        // رمز التحقق
        $png = QrCode::png($verifyUrl, 8);
        if ($png !== null) {
            $mpdf->imageVars['qr'] = $png;
            $mpdf->SetFillColor(255, 255, 255);
            $mpdf->Rect(150, 226, 40, 40, 'F');
            $mpdf->Image('var:qr', 152, 228, 24, 24, 'png', '', true, false);
            $mpdf->WriteFixedPosHTML('<div style="font-size:7.4pt;color:#333;text-align:center;line-height:1.35">' . $this->t('verifyHint', [], $lang) . '<br><span style="direction:ltr;font-size:7pt">' . self::e(implode('-', str_split((string) $letter->code, 5))) . '</span></div>', 146, 252, 36, 12, 'visible');
        }
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
