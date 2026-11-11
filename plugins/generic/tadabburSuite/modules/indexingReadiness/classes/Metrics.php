<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/indexingReadiness/classes/Metrics.php
 *
 * يحسب المؤشرات الكمية التي تنظر فيها لجان Scopus وWeb of Science وDOAJ، من بيانات OJS نفسها:
 * الانتظام، والإنتاج السنوي، والتنوع الجغرافي للمؤلفين وهيئة التحرير، وتغطية الملخصات الإنجليزية والمراجع اللاتينية
 * وDOI وORCID، ونسبة الاستشهاد الذاتي في المراجع، ووجود السياسات المطلوبة في صفحات المجلة.
 */
namespace APP\plugins\generic\tadabburSuite\m\indexingReadiness\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;

class Metrics
{
    public array $m = [];

    public function __construct(private $context, private int $years = 3, private int $expectedPerYear = 2) {}

    private static function plain($s): string
    {
        return trim(html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8'));
    }

    public static function validIssn(string $issn): bool
    {
        if (!preg_match('/^(\d{4})-?(\d{3})([\dXx])$/', trim($issn), $m)) return false;
        $d = $m[1] . $m[2]; $sum = 0;
        for ($i = 0; $i < 7; $i++) $sum += (int) $d[$i] * (8 - $i);
        $c = (11 - $sum % 11) % 11;
        return strtoupper($m[3]) === ($c === 10 ? 'X' : (string) $c);
    }

    private static function pct(int $a, int $b): ?float { return $b ? round(100 * $a / $b, 1) : null; }

    public function compute(): array
    {
        $c = $this->context; $cid = (int) $c->getId();
        $m = &$this->m;
        $m['years'] = $this->years; $m['expectedPerYear'] = $this->expectedPerYear;
        $m['issnOnline'] = (string) $c->getData('onlineIssn'); $m['issnPrint'] = (string) $c->getData('printIssn');
        $m['issnValid'] = ($m['issnOnline'] !== '' && self::validIssn($m['issnOnline'])) || ($m['issnPrint'] !== '' && self::validIssn($m['issnPrint']));
        $m['publisher'] = (string) $c->getData('publisherInstitution');
        $m['country'] = (string) $c->getData('country');
        $m['contactEmail'] = (string) $c->getData('contactEmail');
        $m['locales'] = (array) $c->getSupportedLocales();

        // ---- الأعداد والانتظام
        $issues = DB::table('issues')->where('journal_id', $cid)->where('published', 1)->whereNotNull('date_published')->orderBy('date_published')->pluck('date_published')->all();
        $m['issuesTotal'] = count($issues);
        $m['firstYear'] = $issues ? (int) substr($issues[0], 0, 4) : null;
        $m['lastIssue'] = $issues ? substr(end($issues), 0, 10) : null;
        $m['yearsActive'] = $m['firstYear'] ? (int) date('Y') - $m['firstYear'] + 1 : 0;
        $by = [];
        foreach ($issues as $d) $by[(int) substr($d, 0, 4)] = ($by[(int) substr($d, 0, 4)] ?? 0) + 1;
        $y0 = (int) date('Y');
        $m['issuesByYear'] = [];
        for ($y = $y0 - $this->years; $y <= $y0; $y++) $m['issuesByYear'][$y] = $by[$y] ?? 0;
        $gap = 0;
        for ($i = 1; $i < count($issues); $i++) $gap = max($gap, (strtotime($issues[$i]) - strtotime($issues[$i - 1])) / 86400);
        $m['maxGapDays'] = (int) round($gap);
        $interval = 365 / max(1, $this->expectedPerYear);
        $m['daysSinceLast'] = $m['lastIssue'] ? (int) floor((time() - strtotime($m['lastIssue'])) / 86400) : null;
        $m['delayed'] = $m['daysSinceLast'] === null || $m['daysSinceLast'] > $interval * 1.5;
        $lastFull = $y0 - 1;
        $m['regularYears'] = count(array_filter([$lastFull - 1, $lastFull], fn ($y) => ($by[$y] ?? 0) >= $this->expectedPerYear));

        // ---- البحوث المنشورة في النافذة
        $since = ($y0 - $this->years) . '-01-01';
        $pubs = DB::table('publications as p')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $cid)->where('p.status', 3)->where('p.date_published', '>=', $since)
            ->whereRaw('p.publication_id = s.current_publication_id')
            ->get(['p.publication_id', 'p.date_published', 's.submission_id', 'p.doi_id']);
        $pubIds = $pubs->pluck('publication_id')->all();
        $m['articles'] = count($pubIds);
        $m['articlesByYear'] = [];
        for ($y = $y0 - $this->years; $y <= $y0; $y++) $m['articlesByYear'][$y] = $pubs->filter(fn ($p) => (int) substr((string) $p->date_published, 0, 4) === $y)->count();
        $m['articlesLastYear'] = $m['articlesByYear'][$lastFull] ?? 0;

        $ps = $pubIds ? DB::table('publication_settings')->whereIn('publication_id', $pubIds)->whereIn('setting_name', ['title', 'abstract'])->where('locale', 'en')
            ->where('setting_value', '<>', '')->get(['publication_id', 'setting_name', 'setting_value']) : collect();
        $enT = $ps->where('setting_name', 'title')->filter(fn ($r) => self::plain($r->setting_value) !== '')->pluck('publication_id')->unique()->count();
        $enA = $ps->where('setting_name', 'abstract')->filter(fn ($r) => str_word_count(self::plain($r->setting_value)) >= 40)->pluck('publication_id')->unique()->count();
        $m['enTitlePct'] = self::pct($enT, count($pubIds)); $m['enAbstractPct'] = self::pct($enA, count($pubIds));
        $m['doiPct'] = self::pct($pubs->filter(fn ($p) => $p->doi_id)->count(), count($pubIds));

        // ---- المراجع: الوجود، الحروف اللاتينية، الاستشهاد الذاتي
        $cites = $pubIds ? DB::table('citations')->whereIn('publication_id', $pubIds)->get(['publication_id', 'raw_citation']) : collect();
        $m['refsPresentPct'] = self::pct($cites->pluck('publication_id')->unique()->count(), count($pubIds));
        $m['refsTotal'] = $cites->count();
        $nonLatin = $cites->filter(fn ($r) => preg_match('/[\x{0600}-\x{06FF}]/u', (string) $r->raw_citation))->count();
        $m['refsRomanPct'] = self::pct($m['refsTotal'] - $nonLatin, $m['refsTotal']);
        $needles = array_filter(array_map(fn ($s) => mb_strtolower(trim((string) $s)), array_merge(
            array_values((array) $c->getData('name')), array_values((array) $c->getData('abbreviation')),
            [$m['issnOnline'], $m['issnPrint'], (string) $c->getData('doiPrefix') ? (string) $c->getData('doiPrefix') . '/' : '']
        )), fn ($s) => mb_strlen($s) >= 4);
        $self = 0;
        foreach ($cites as $r) {
            $t = mb_strtolower((string) $r->raw_citation);
            foreach ($needles as $n) if (str_contains($t, $n)) { $self++; break; }
        }
        $m['selfCites'] = $self; $m['selfCitePct'] = self::pct($self, $m['refsTotal']);

        // ---- المؤلفون
        $authors = $pubIds ? DB::table('authors')->whereIn('publication_id', $pubIds)->pluck('author_id')->all() : [];
        $as = $authors ? DB::table('author_settings')->whereIn('author_id', $authors)->whereIn('setting_name', ['country', 'affiliation', 'orcid'])
            ->where('setting_value', '<>', '')->get(['author_id', 'setting_name', 'setting_value']) : collect();
        $countries = $as->where('setting_name', 'country')->pluck('setting_value')->all();
        // في OJS 3.5 الانتماءات في جدول author_affiliations
        $affIds = [];
        try {
            if ($authors && \Illuminate\Support\Facades\Schema::hasTable('author_affiliations')) $affIds = DB::table('author_affiliations')->whereIn('author_id', $authors)->distinct()->pluck('author_id')->all();
        } catch (\Throwable $e) {}
        $affIds = array_unique(array_merge($affIds, $as->where('setting_name', 'affiliation')->pluck('author_id')->all()));
        $m['authors'] = count($authors);
        $m['authorCountries'] = count(array_unique($countries));
        $cc = array_count_values($countries); arsort($cc);
        $m['authorTopCountry'] = $cc ? array_key_first($cc) : null;
        $m['authorTopShare'] = $cc ? self::pct(reset($cc), count($countries)) : null;
        $m['authorLocalShare'] = $m['country'] !== '' && $countries ? self::pct($cc[$m['country']] ?? 0, count($countries)) : null;
        $m['authorCountryList'] = array_slice($cc, 0, 15, true);
        $m['orcidPct'] = self::pct($as->where('setting_name', 'orcid')->pluck('author_id')->unique()->count(), count($authors));
        $m['affPct'] = self::pct(count($affIds), count($authors));
        $m['countryPct'] = self::pct(count($countries), count($authors));

        // ---- هيئة التحرير
        $groupIds = (array) $c->getData('mastheadUserGroupIds');
        if (!$groupIds) $groupIds = DB::table('user_groups')->where('context_id', $cid)->where('masthead', 1)->pluck('user_group_id')->all();
        $members = $groupIds ? DB::table('user_user_groups')->whereIn('user_group_id', $groupIds)
            ->where(fn ($q) => $q->whereNull('date_end')->orWhere('date_end', '>', now()))->distinct()->pluck('user_id')->all() : [];
        $bc = []; $bAff = 0; $bOrcid = 0; $orcids = [];
        foreach ($members as $uid) {
            $u = Repo::user()->get((int) $uid, true);
            if (!$u) continue;
            if ($u->getData('country')) $bc[] = $u->getData('country');
            $aff = $u->getLocalizedData('affiliation');
            if (trim(is_array($aff) ? implode('', $aff) : (string) $aff) !== '') $bAff++;
            if ($u->getData('orcid')) { $bOrcid++; $orcids[(int) $uid] = preg_replace('#^https?://orcid.org/#', '', (string) $u->getData('orcid')); }
        }
        $bcc = array_count_values($bc);
        $m['board'] = ['n' => count($members), 'countries' => count($bcc), 'affPct' => self::pct($bAff, count($members)), 'orcidPct' => self::pct($bOrcid, count($members)),
            'foreignPct' => $m['country'] !== '' && $bc ? self::pct(count($bc) - ($bcc[$m['country']] ?? 0), count($bc)) : null, 'countryList' => $bcc];
        $m['boardOrcids'] = $orcids;

        // ---- السياسات المعلنة في صفحات المجلة
        $txt = '';
        foreach (['about', 'description', 'focusScopeDesc', 'reviewPolicy', 'reviewGuidelines', 'authorGuidelines', 'openAccessPolicy', 'copyrightNotice', 'privacyStatement', 'competingInterests', 'beginSubmissionHelp', 'editorialStatsEmailContent'] as $k) {
            foreach ((array) $c->getData($k) as $v) $txt .= ' ' . self::plain($v);
        }
        $txt .= ' ' . $this->staticPagesText($cid);
        $t = mb_strtolower($txt);
        $has = fn (string $rx) => (bool) preg_match('/' . $rx . '/u', $t);
        $m['policy'] = [
            'aims' => $has('aims? and scope|focus and scope|الأهداف|أهداف المجلة|مجالات النشر|رؤية المجلة'),
            'peerReview' => $has('peer[\s-]?review|double[\s-]?blind|single[\s-]?blind|التحكيم|تحكيم|محكم'),
            'reviewType' => $has('double[\s-]?blind|single[\s-]?blind|open peer|مزدوج|المزدوج|التعمية|العمياء|عمياء'),
            'ethics' => $has('publication ethics|ethic|cope|malpractice|أخلاقيات'),
            'plagiarism' => $has('plagiar|similarity|ithenticate|turnitin|الاستلال|انتحال|السرقة العلمية|الاقتباس غير'),
            'aiPolicy' => $has('artificial intelligence|generative ai|\bai\b|الذكاء الاصطناعي'),
            'oa' => $has('open access|الوصول المفتوح|الوصول الحر') || trim(self::plain(implode(' ', (array) $c->getData('openAccessPolicy')))) !== '',
            'fees' => $has('fee|charge|apc|رسوم|مجان') || (bool) $c->getData('publicationFee'),
            'archiving' => $has('lockss|clockss|portico|pkp pn|preservation|archiv|أرشف|الحفظ الرقمي') || (bool) ($c->getData('enableLockss') || $c->getData('enableClockss')),
            'copyright' => $has('copyright|حقوق النشر|حقوق الطبع|حقوق المؤلف'),
            'retraction' => $has('retract|correction|erratum|سحب|التصحيح|تصويب'),
            'conflicts' => $has('conflict of interest|competing interest|تضارب المصالح|تعارض المصالح'),
            'complaints' => $has('complain|appeal|الشكاوى|التظلم|الاعتراض'),
            'guidelines' => count(preg_split('/\s+/u', self::plain(implode(' ', (array) $c->getData('authorGuidelines'))), -1, PREG_SPLIT_NO_EMPTY)) >= 30,
            'dataPolicy' => $has('data availability|data sharing|research data|البيانات البحثية|إتاحة البيانات'),
        ];
        $lic = (string) $c->getData('licenseUrl');
        $m['license'] = $lic; $m['licenseCC'] = str_contains($lic, 'creativecommons.org');
        $m['englishSite'] = in_array('en', $m['locales'], true) && self::plain(($c->getData('about') ?? [])['en'] ?? '') !== '';
        $m['doiEnabled'] = (bool) $c->getData('enableDois') && trim((string) $c->getData('doiPrefix')) !== '';
        $m['oai'] = (bool) ($c->getData('enableOai') ?? true);
        $m['scholar'] = DB::table('plugin_settings')->where('plugin_name', 'googlescholarplugin')->where('context_id', $cid)->where('setting_name', 'enabled')->where('setting_value', '1')->exists();
        return $m;
    }

    private function staticPagesText(int $cid): string
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('static_page_settings')) return '';
            $ids = DB::table('static_pages')->where('context_id', $cid)->pluck('static_page_id')->all();
            if (!$ids) return '';
            return implode(' ', array_map([self::class, 'plain'], DB::table('static_page_settings')->whereIn('static_page_id', $ids)->whereIn('setting_name', ['title', 'content'])->pluck('setting_value')->all()));
        } catch (\Throwable $e) {
            return '';
        }
    }
}
