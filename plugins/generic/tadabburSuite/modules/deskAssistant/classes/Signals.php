<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/deskAssistant/classes/Signals.php
 *
 * مؤشرات القرار الأولي المحسوبة محليًا (دون ذكاء اصطناعي):
 *  - الملاءمة لنطاق المجلة: تشابه نص البحث مع نطاق المجلة ومع ما نشرته فعلًا (TF-IDF وجيب التمام).
 *  - أقرب البحوث المنشورة في المجلة، واحتمال التقديم المكرر (تطابق شديد مع بحث آخر في النظام).
 *  - سجل المؤلفين في المجلة (تقديمات سابقة ونتائجها).
 *  - نتائج الإضافات المساعدة إن وُجدت: الفحص الفني، مدقّق الآيات، الإقرارات، مركز المحكّمين.
 */
namespace APP\plugins\generic\tadabburSuite\m\deskAssistant\classes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Signals
{
    private const STOP = ['في', 'من', 'على', 'الى', 'عن', 'مع', 'او', 'ثم', 'بين', 'هذا', 'هذه', 'التي', 'الذي', 'كان', 'قد', 'لم', 'لا', 'ما', 'هو', 'هي', 'كل', 'بعد', 'قبل', 'عند', 'حيث', 'وقد', 'كما', 'ذلك', 'تلك', 'دراسه', 'بحث', 'البحث', 'الدراسه', 'خلال', 'يهدف', 'هدف', 'منهج', 'نتايج', 'اهم', 'the', 'of', 'and', 'in', 'a', 'an', 'to', 'on', 'for', 'is', 'are', 'this', 'that', 'with', 'by', 'as', 'its', 'study', 'paper', 'research', 'aims', 'results', 'method'];

    public function __construct(private int $contextId) {}

    public static function norm(string $s): string
    {
        $s = mb_strtolower(preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', '’' => '', "'" => '']);
        return trim(preg_replace('/[^\p{L}\s]+/u', ' ', $s));
    }

    /** كلمات مع جذع تقريبي (حذف ال والواو وبعض اللواحق) */
    public static function terms(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', self::norm($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $p) if (mb_strlen($w) > mb_strlen($p) + 2 && str_starts_with($w, $p)) { $w = mb_substr($w, mb_strlen($p)); break; }
            foreach (['يات', 'ات', 'ين', 'ون', 'يه', 'ه'] as $suf) if (mb_strlen($w) > mb_strlen($suf) + 2 && str_ends_with($w, $suf)) { $w = mb_substr($w, 0, -mb_strlen($suf)); break; }
            if (mb_strlen($w) < 3 || in_array($w, self::STOP, true)) continue;
            $out[] = $w;
        }
        return $out;
    }

    private static function vec(array $terms, array $idf): array
    {
        $tf = array_count_values($terms); $v = [];
        foreach ($tf as $t => $n) $v[$t] = (1 + log($n)) * ($idf[$t] ?? 1.0);
        return $v;
    }

    private static function cos(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $t => $x) if (isset($b[$t])) $dot += $x * $b[$t];
        $na = sqrt(array_sum(array_map(fn ($x) => $x * $x, $a))); $nb = sqrt(array_sum(array_map(fn ($x) => $x * $x, $b)));
        return $na && $nb ? $dot / ($na * $nb) : 0.0;
    }

    /** نص كل بحث (العنوان والملخص والكلمات) لمجموعة بحوث */
    private function docs(array $submissionIds): array
    {
        if (!$submissionIds) return [];
        $pubs = DB::table('submissions')->whereIn('submission_id', $submissionIds)->pluck('current_publication_id', 'submission_id')->all();
        $txt = [];
        foreach (DB::table('publication_settings')->whereIn('publication_id', array_values($pubs))->whereIn('setting_name', ['title', 'abstract'])->get(['publication_id', 'setting_name', 'setting_value']) as $r) {
            $txt[(int) $r->publication_id] = ($txt[(int) $r->publication_id] ?? '') . ' ' . ($r->setting_name === 'title' ? str_repeat(strip_tags((string) $r->setting_value) . ' ', 2) : strip_tags((string) $r->setting_value));
        }
        foreach (DB::table('controlled_vocabs as cv')->join('controlled_vocab_entries as e', 'e.controlled_vocab_id', '=', 'cv.controlled_vocab_id')->join('controlled_vocab_entry_settings as s', 's.controlled_vocab_entry_id', '=', 'e.controlled_vocab_entry_id')
            ->where('cv.symbolic', 'submissionKeyword')->whereIn('cv.assoc_id', array_values($pubs))->where('s.setting_name', 'name')->get(['cv.assoc_id', 's.setting_value']) as $r) {
            $txt[(int) $r->assoc_id] = ($txt[(int) $r->assoc_id] ?? '') . ' ' . str_repeat((string) $r->setting_value . ' ', 3);
        }
        $out = [];
        foreach ($pubs as $sid => $pid) $out[(int) $sid] = $txt[(int) $pid] ?? '';
        return $out;
    }

    private function titles(array $sids, string $locale): array
    {
        if (!$sids) return [];
        $rows = DB::table('submissions as s')->join('publication_settings as ps', fn ($j) => $j->on('ps.publication_id', '=', 's.current_publication_id')->where('ps.setting_name', 'title'))
            ->whereIn('s.submission_id', $sids)->get(['s.submission_id', 'ps.locale', 'ps.setting_value'])->groupBy('submission_id');
        $out = [];
        foreach ($rows as $sid => $ts) $out[(int) $sid] = strip_tags((string) (($ts->firstWhere('locale', $locale) ?? $ts->first())->setting_value));
        return $out;
    }

    public function compute($submission, string $scopeText, string $locale): array
    {
        $sid = (int) $submission->getId();
        $published = DB::table('submissions')->where('context_id', $this->contextId)->where('status', 3)->where('submission_id', '<>', $sid)->pluck('submission_id')->map(fn ($x) => (int) $x)->all();
        $others = DB::table('submissions')->where('context_id', $this->contextId)->where('submission_id', '<>', $sid)->whereNotIn('submission_id', $published ?: [0])
            ->where(fn ($q) => $q->whereNull('submission_progress')->orWhere('submission_progress', ''))->pluck('submission_id')->map(fn ($x) => (int) $x)->all();
        $docs = $this->docs(array_merge([$sid], $published, $others));
        $termsOf = array_map(fn ($t) => self::terms($t), $docs);
        // IDF على كل المجموعة + النطاق
        $scopeTerms = self::terms($scopeText);
        $df = []; $N = count($termsOf) + 1;
        foreach (array_merge($termsOf, [$scopeTerms]) as $ts) foreach (array_unique($ts) as $t) $df[$t] = ($df[$t] ?? 0) + 1;
        $idf = []; foreach ($df as $t => $n) $idf[$t] = log(($N + 1) / ($n + 0.5));
        $me = self::vec($termsOf[$sid] ?? [], $idf);
        $scopeSim = $scopeTerms ? self::cos($me, self::vec($scopeTerms, $idf)) : null;
        $sims = [];
        foreach ($published as $p) $sims[$p] = self::cos($me, self::vec($termsOf[$p] ?? [], $idf));
        arsort($sims);
        $top = array_slice($sims, 0, 5, true);
        // ملاءمة المنشور: متوسط أعلى 5 تشابهات
        $corpusFit = $top ? array_sum($top) / count($top) : null;
        $dups = [];
        foreach (array_merge($published, $others) as $o) {
            $s = $o === $sid ? 0 : self::cos($me, self::vec($termsOf[$o] ?? [], $idf));
            if ($s >= 0.75) $dups[$o] = $s;
        }
        arsort($dups);
        $titles = $this->titles(array_merge(array_keys($top), array_keys($dups)), $locale);
        // تقدير الملاءمة: نطاق المجلة (وزن 0.4) + المنشور (وزن 0.6)، مُعايَر تقريبًا إلى 0–100
        $fitRaw = ($scopeSim !== null ? 0.4 * min(1, $scopeSim / 0.25) : 0) + ($corpusFit !== null ? 0.6 * min(1, $corpusFit / 0.30) : 0);
        if ($scopeSim === null && $corpusFit !== null) $fitRaw = min(1, $corpusFit / 0.30);
        if ($corpusFit === null && $scopeSim !== null) $fitRaw = min(1, $scopeSim / 0.25);
        $fit = (int) round(100 * $fitRaw);

        return [
            'fit' => $fit, 'fitLevel' => $fit >= 60 ? 'high' : ($fit >= 35 ? 'medium' : 'low'),
            'scopeSim' => $scopeSim === null ? null : round($scopeSim, 3), 'corpusFit' => $corpusFit === null ? null : round($corpusFit, 3), 'corpusSize' => count($published),
            'similar' => array_map(fn ($id, $s) => ['id' => $id, 'title' => $titles[$id] ?? '#' . $id, 'sim' => round($s, 2)], array_keys($top), array_values($top)),
            'duplicates' => array_map(fn ($id, $s) => ['id' => $id, 'title' => $titles[$id] ?? '#' . $id, 'sim' => round($s, 2), 'published' => in_array($id, $published, true)], array_keys($dups), array_values($dups)),
            'authors' => $this->authorHistory($submission),
            'checks' => $this->otherPlugins($sid),
        ];
    }

    /** تقديمات سابقة لمؤلفي البحث في المجلة (بالبريد) */
    private function authorHistory($submission): array
    {
        $pub = $submission->getCurrentPublication();
        $emails = [];
        foreach ($pub->getData('authors') ?? [] as $a) if ($a->getData('email')) $emails[mb_strtolower((string) $a->getData('email'))] = $a->getFullName();
        if (!$emails) return [];
        $rows = DB::table('authors as a')->join('publications as p', 'p.publication_id', '=', 'a.publication_id')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $this->contextId)->where('s.submission_id', '<>', $submission->getId())->whereIn(DB::raw('LOWER(a.email)'), array_keys($emails))
            ->get([DB::raw('LOWER(a.email) as email'), 's.submission_id', 's.status'])->unique(fn ($r) => $r->email . '|' . $r->submission_id);
        $out = [];
        foreach ($rows as $r) {
            $o = &$out[$r->email];
            $o['name'] = $emails[$r->email] ?? $r->email;
            $o['total'] = ($o['total'] ?? 0) + 1;
            $k = ['1' => 'active', '3' => 'published', '4' => 'declined', '5' => 'scheduled'][(string) $r->status] ?? 'other';
            $o[$k] = ($o[$k] ?? 0) + 1;
            unset($o);
        }
        return array_values($out);
    }

    /** نتائج الإضافات الأخرى إن كانت مثبتة */
    private function otherPlugins(int $sid): array
    {
        $c = [];
        try {
            if (Schema::hasTable('prereview_checks')) {
                $r = DB::table('prereview_checks')->where('submission_id', $sid)->orderBy('check_id', 'desc')->first(['check_id', 'failed_req', 'failed_rec']);
                $c['preReview'] = $r ? ['id' => (int) $r->check_id, 'req' => (int) $r->failed_req, 'rec' => (int) $r->failed_rec] : ['missing' => true];
            }
            if (Schema::hasTable('quran_verifier_reports')) {
                $r = DB::table('quran_verifier_reports')->where('submission_id', $sid)->orderBy('report_id', 'desc')->first(['report_id', 'stats']);
                $st = $r ? (json_decode((string) $r->stats, true) ?: []) : [];
                $c['quran'] = $r ? ['id' => (int) $r->report_id, 'total' => (int) ($st['total'] ?? 0), 'problems' => (int) ($st['problems'] ?? 0), 'hadithIssues' => (int) ($st['hadithIssues'] ?? 0)] : ['missing' => true];
            }
        } catch (\Throwable $e) {
        }
        return $c;
    }

    /** نص ملف Word للبحث (لتحليل الذكاء الاصطناعي إن سُمح) */
    public static function manuscriptText(int $submissionId, int $maxWords = 6000): string
    {
        try {
            $files = \APP\facades\Repo::submissionFile()->getCollector()->filterBySubmissionIds([$submissionId])->filterByFileStages([\PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_SUBMISSION])->getMany();
            foreach ($files as $sf) {
                if (!str_ends_with(strtolower((string) $sf->getLocalizedData('name')), '.docx')) continue;
                $tmp = tempnam(sys_get_temp_dir(), 'dsk');
                file_put_contents($tmp, app()->get('file')->fs->read((string) $sf->getData('path')));
                $z = new \ZipArchive();
                $text = '';
                if ($z->open($tmp) === true) {
                    $xml = (string) $z->getFromName('word/document.xml'); $z->close();
                    $xml = preg_replace('/<\/w:p>/', "\n", $xml);
                    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                @unlink($tmp);
                $w = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
                return implode(' ', array_slice($w, 0, $maxWords));
            }
        } catch (\Throwable $e) {
        }
        return '';
    }
}
