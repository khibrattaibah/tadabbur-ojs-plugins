<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/preReviewCheck/classes/Checker.php
 *
 * الفحص الفني قبل التحكيم:
 *  أ) البيانات الوصفية: العنوان، الملخصات وأطوالها، الكلمات المفتاحية، المؤلفون (الانتماء، الدولة، ORCID)، الإقرارات.
 *  ب) ملف البحث (Word): عدد الكلمات، إخفاء هوية المؤلفين في النص وخصائص الملف، التعليقات وتتبع التغييرات،
 *     العناوين الإلزامية، قائمة المراجع وحجمها، وتقرير مدقّق الآيات إن وُجد.
 */
namespace APP\plugins\generic\tadabburSuite\m\preReviewCheck\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\submissionFile\SubmissionFile;

class Checker
{
    public const REQ = 'req', REC = 'rec', INFO = 'info';
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(private array $cfg) {}

    private static function plain($s): string { return trim(html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8')); }
    private static function words(string $s): int { return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY)); }
    private static function norm(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', mb_strtolower($s));
        return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }
    private function item(string $id, string $level, bool $ok, string $detail = '', array $p = []): array
    {
        return ['id' => $id, 'level' => $level, 'ok' => $ok, 'detail' => $detail, 'p' => $p];
    }

    /** @return array{checks: array, stats: array, file: ?array} */
    public function run($submission, $context, ?int $fileId = null): array
    {
        $pub = $submission->getCurrentPublication();
        $c = [];
        $locales = array_values(array_unique(array_merge([(string) $submission->getData('locale')], (array) $this->cfg['locales'])));

        // ---------------------------------------------------------- البيانات الوصفية
        foreach ($locales as $l) {
            $title = self::plain($pub->getData('title', $l));
            $tw = self::words($title);
            $c[] = $this->item('title', self::REQ, $title !== '' && $tw <= $this->cfg['titleMax'], $title === '' ? '' : (string) $tw, ['lang' => $l, 'max' => $this->cfg['titleMax']]);
            $abs = self::plain($pub->getData('abstract', $l)); $aw = self::words($abs);
            $c[] = $this->item('abstract', self::REQ, $aw >= $this->cfg['absMin'] && $aw <= $this->cfg['absMax'], (string) $aw, ['lang' => $l, 'min' => $this->cfg['absMin'], 'max' => $this->cfg['absMax']]);
            $kw = count((array) (($pub->getData('keywords') ?? [])[$l] ?? []));
            $c[] = $this->item('keywords', self::REQ, $kw >= $this->cfg['kwMin'] && $kw <= $this->cfg['kwMax'], (string) $kw, ['lang' => $l, 'min' => $this->cfg['kwMin'], 'max' => $this->cfg['kwMax']]);
        }
        $authors = iterator_to_array($pub->getData('authors') ?? []);
        $noAff = []; $noCountry = []; $noOrcid = [];
        $primaryId = (int) $pub->getData('primaryContactId');
        foreach ($authors as $a) {
            $name = (string) $a->getFullName();
            $aff = $a->getData('affiliations');
            $hasAff = $aff ? (bool) count(is_iterable($aff) ? iterator_to_array($aff) : (array) $aff) : trim((string) $a->getLocalizedData('affiliation')) !== '';
            if (!$hasAff) $noAff[] = $name;
            if (!$a->getData('country')) $noCountry[] = $name;
            $needOrcid = $this->cfg['orcid'] === 'all' || ($this->cfg['orcid'] === 'corresponding' && (int) $a->getId() === $primaryId);
            if ($needOrcid && !$a->getData('orcid')) $noOrcid[] = $name;
        }
        $c[] = $this->item('affiliation', self::REQ, !$noAff, implode('، ', $noAff));
        $c[] = $this->item('country', self::REQ, !$noCountry, implode('، ', $noCountry));
        if ($this->cfg['orcid'] !== 'none') $c[] = $this->item('orcid', self::REC, !$noOrcid, implode('، ', $noOrcid), ['scope' => $this->cfg['orcid']]);

        // الإقرارات (إن كانت إضافة الإقرارات مفعّلة)
        if ($this->cfg['checkDeclarations'] && $this->declarationsEnabled((int) $context->getId())) {
            $missing = [];
            foreach (['declCoi', 'declFunding', 'declEthics', 'declAi'] as $f) if ((string) $pub->getData($f) === '') $missing[] = $f;
            $c[] = $this->item('declarations', self::REQ, !$missing, (string) count($missing));
        }

        // ---------------------------------------------------------- ملف البحث
        $file = $this->pickFile((int) $submission->getId(), $fileId);
        $stats = ['words' => null, 'footnotes' => null, 'refs' => null];
        if (!$file) {
            $c[] = $this->item('file', self::REQ, false);
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $c[] = $this->item('fileType', self::REQ, in_array($ext, $this->cfg['fileTypes'], true), $ext, ['types' => implode(', ', $this->cfg['fileTypes'])]);
            if ($ext === 'docx') {
                $d = $this->docx($file['path']);
                if ($d === null) {
                    $c[] = $this->item('fileRead', self::REQ, false, $file['name']);
                } else {
                    $stats = ['words' => $d['words'], 'footnotes' => $d['footnotes'], 'refs' => $d['refs']];
                    $c[] = $this->item('length', self::REQ, $d['words'] >= $this->cfg['wordsMin'] && $d['words'] <= $this->cfg['wordsMax'], number_format($d['words']), ['min' => number_format($this->cfg['wordsMin']), 'max' => number_format($this->cfg['wordsMax'])]);
                    if ($this->cfg['blind']) {
                        $hits = $this->identity($d, $authors);
                        $c[] = $this->item('anonText', self::REQ, !$hits['text'], implode('، ', $hits['text']));
                        $c[] = $this->item('anonProps', self::REQ, !$hits['props'], implode('، ', $hits['props']));
                    }
                    $c[] = $this->item('comments', self::REC, $d['comments'] === 0, (string) $d['comments']);
                    $c[] = $this->item('tracked', self::REC, $d['tracked'] === 0, (string) $d['tracked']);
                    $missingH = [];
                    foreach ($this->cfg['headings'] as $h) {
                        $nh = self::norm($h); $found = false;
                        foreach ($d['lines'] as $ln) if (mb_strlen($ln) < 120 && str_contains(self::norm($ln), $nh)) { $found = true; break; }
                        if (!$found) $missingH[] = $h;
                    }
                    if ($this->cfg['headings']) $c[] = $this->item('headings', self::REQ, !$missingH, implode('، ', $missingH));
                    $c[] = $this->item('references', self::REQ, $d['refs'] >= $this->cfg['refsMin'], (string) $d['refs'], ['min' => $this->cfg['refsMin']]);
                    if ($this->cfg['romanRefs']) $c[] = $this->item('romanRefs', self::REC, $d['romanSection'], '');
                }
            }
        }
        // تقرير مدقّق الآيات
        $qv = $this->quranReport((int) $context->getId(), (int) $submission->getId());
        if ($qv !== null) $c[] = $this->item('quran', self::REC, $qv['problems'] === 0, (string) $qv['problems'], ['reportId' => $qv['id']]);

        $req = array_filter($c, fn ($x) => $x['level'] === self::REQ);
        return ['checks' => $c, 'stats' => $stats + ['failedReq' => count(array_filter($req, fn ($x) => !$x['ok'])), 'failedRec' => count(array_filter($c, fn ($x) => $x['level'] === self::REC && !$x['ok'])), 'total' => count($c)],
            'file' => $file ? ['id' => $file['id'], 'name' => $file['name']] : null];
    }

    private function declarationsEnabled(int $cid): bool
    {
        return \APP\plugins\generic\tadabburSuite\classes\Modules::isActive('declarations');
    }

    private function quranReport(int $cid, int $sid): ?array
    {
        try {
            if (!Schema::hasTable('quran_verifier_reports')) return null;
            $r = DB::table('quran_verifier_reports')->where('context_id', $cid)->where('submission_id', $sid)->orderBy('report_id', 'desc')->first(['report_id', 'stats']);
            if (!$r) return null;
            $st = json_decode((string) $r->stats, true) ?: [];
            return ['id' => (int) $r->report_id, 'problems' => (int) ($st['problems'] ?? 0)];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** أحدث ملف تقديم (Word أولًا) ونسخة مؤقتة منه */
    private function pickFile(int $sid, ?int $fileId): ?array
    {
        $files = Repo::submissionFile()->getCollector()->filterBySubmissionIds([$sid])->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])->getMany();
        $best = null;
        foreach ($files as $sf) {
            if ($fileId && (int) $sf->getId() !== $fileId) continue;
            $name = (string) $sf->getLocalizedData('name');
            $rank = str_ends_with(strtolower($name), '.docx') ? 0 : 1;
            if (!$best || [$rank, -strtotime((string) $sf->getData('updatedAt'))] < [$best[0], -strtotime((string) $best[1]->getData('updatedAt'))]) $best = [$rank, $sf];
        }
        if (!$best) return null;
        $sf = $best[1];
        $tmp = tempnam(sys_get_temp_dir(), 'prc');
        try { file_put_contents($tmp, app()->get('file')->fs->read((string) $sf->getData('path'))); } catch (\Throwable $e) { @unlink($tmp); return null; }
        register_shutdown_function(fn () => @unlink($tmp));
        return ['id' => (int) $sf->getId(), 'name' => (string) $sf->getLocalizedData('name'), 'path' => $tmp];
    }

    /** تحليل ملف Word */
    public function docx(string $path): ?array
    {
        if (!class_exists(\ZipArchive::class)) return null;
        $z = new \ZipArchive();
        if ($z->open($path) !== true) return null;
        $doc = $z->getFromName('word/document.xml');
        if ($doc === false) { $z->close(); return null; }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($doc, LIBXML_NONET | LIBXML_PARSEHUGE)) { $z->close(); return null; }
        $xp = new \DOMXPath($dom); $xp->registerNamespace('w', self::W);
        $lines = [];
        foreach ($xp->query('//w:body//w:p') as $p) {
            $t = '';
            foreach ($xp->query('.//w:t', $p) as $n) $t .= $n->textContent;
            $t = trim($t); if ($t !== '') $lines[] = $t;
        }
        $body = implode("\n", $lines);
        $fnText = '';
        $fnCount = 0;
        if (($fx = $z->getFromName('word/footnotes.xml')) !== false) {
            $fd = new \DOMDocument(); @$fd->loadXML($fx, LIBXML_NONET | LIBXML_PARSEHUGE);
            $fxp = new \DOMXPath($fd); $fxp->registerNamespace('w', self::W);
            foreach ($fxp->query('//w:footnote[not(@w:type)]') as $f) { $fnCount++; $fnText .= ' ' . $f->textContent; }
        }
        $comments = 0;
        if (($cx = $z->getFromName('word/comments.xml')) !== false) $comments = substr_count($cx, '<w:comment ');
        $tracked = substr_count($doc, '<w:ins ') + substr_count($doc, '<w:del ');
        $props = [];
        foreach (['docProps/core.xml', 'docProps/app.xml'] as $e) {
            if (($px = $z->getFromName($e)) === false) continue;
            foreach (['dc:creator', 'cp:lastModifiedBy', 'Company', 'Manager'] as $tag) {
                if (preg_match('#<' . preg_quote($tag, '#') . '[^>]*>([^<]+)</' . preg_quote($tag, '#') . '>#u', $px, $m) && trim($m[1]) !== '') $props[$tag] = trim($m[1]);
            }
        }
        $z->close();
        // المراجع: الأسطر بعد عنوان قائمة المصادر
        $refs = 0; $latinRefs = 0; $romanSection = false; $in = false;
        $refHead = '/^(قائمة\s+)?(المصادر|المراجع|المصادر\s+والمراجع|ثبت\s+المصادر(\s+والمراجع)?|فهرس\s+المصادر(\s+والمراجع)?|references|bibliography|works\s+cited)\b/iu';
        foreach ($lines as $ln) {
            $n = trim(preg_replace('/^[\d\.\-\s]+/u', '', $ln));
            if (mb_strlen($n) < 60 && preg_match($refHead, $n)) { $in = true; if (preg_match('/references|bibliography|romani|mutarjam|المرومنة|بالحروف اللاتينية/iu', $n)) $romanSection = true; continue; }
            if ($in && mb_strlen($ln) >= 15) { $refs++; if (!preg_match('/\p{Arabic}/u', $ln) && preg_match('/[A-Za-z]{3,}/', $ln)) $latinRefs++; }
        }
        if ($latinRefs >= 3) $romanSection = true;
        return ['words' => self::words($body), 'lines' => $lines, 'body' => $body, 'footnotesText' => $fnText, 'footnotes' => $fnCount,
            'comments' => $comments, 'tracked' => $tracked, 'props' => $props, 'refs' => $refs, 'romanSection' => (bool) $romanSection];
    }

    /** البحث عن أسماء المؤلفين وبريدهم في النص والحواشي وخصائص الملف */
    private function identity(array $d, array $authors): array
    {
        $hay = self::norm($d['body'] . ' ' . $d['footnotesText']);
        $text = []; $props = [];
        foreach ($authors as $a) {
            $variants = [];
            foreach ((array) $a->getData('givenName') as $l => $g) {
                $f = (string) (($a->getData('familyName') ?? [])[$l] ?? '');
                if (trim($g . $f) === '') continue;
                $variants[] = trim($g . ' ' . $f);
                if (mb_strlen(trim($f)) >= 4) $variants[] = trim($f);
            }
            if ($a->getData('email')) $variants[] = (string) $a->getData('email');
            foreach (array_unique($variants) as $v) {
                $nv = self::norm($v);
                if (mb_strlen($nv) < 4) continue;
                $isFamily = !str_contains($v, ' ') && !str_contains($v, '@');
                $found = $isFamily ? (bool) preg_match('/(?<![\p{L}])' . preg_quote($nv, '/') . '(?![\p{L}])/u', $hay) : str_contains($hay, $nv);
                if ($found) { $text[] = $v; break; }
            }
            foreach ($d['props'] as $tag => $val) {
                foreach ($variants as $v) {
                    if (str_contains($v, '@') || mb_strlen($v) < 4) continue;
                    if (str_contains(self::norm($val), self::norm($v)) || str_contains(self::norm($v), self::norm($val)) && mb_strlen($val) >= 4) { $props[] = $tag . ': ' . $val; break; }
                }
            }
        }
        // أي اسم شخصي في خصائص الملف تنبيه حتى لو لم يطابق (قد يكون اسم المؤلف بصيغة أخرى)
        foreach ($d['props'] as $tag => $val) {
            if (in_array($tag, ['dc:creator', 'cp:lastModifiedBy'], true) && !preg_match('/^(user|admin|windows user|microsoft office user|author|un-named|مستخدم)$/iu', trim($val))) $props[] = $tag . ': ' . $val;
        }
        return ['text' => array_values(array_unique($text)), 'props' => array_values(array_unique($props))];
    }
}
