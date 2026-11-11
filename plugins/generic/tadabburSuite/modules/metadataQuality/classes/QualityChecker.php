<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/metadataQuality/classes/QualityChecker.php
 *
 * فحوص جودة البيانات الوصفية على مستوى البحث وعلى مستوى المجلة، مبنية على معايير:
 * Scopus (Title Evaluation), Web of Science (Quality criteria), DOAJ, Crossref (metadata completeness), COPE/OASPA.
 * كل فحص: id, level (required|recommended), ok, detail, std[]
 */
namespace APP\plugins\generic\tadabburSuite\m\metadataQuality\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\facades\Locale;

class QualityChecker
{
    public const REQUIRED = 'required';
    public const RECOMMENDED = 'recommended';

    /** @param array $cfg ['locales'=>['ar','en'], 'minAbstractWords'=>int, 'minKeywords'=>int, 'requireOrcid'=>'none|corresponding|all', 'exemptSections'=>int[]] */
    public function __construct(private array $cfg) {}

    private static function plain($html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function words(string $text): int
    {
        return $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function hasArabic(string $s): bool { return (bool) preg_match('/\p{Arabic}/u', $s); }

    /** نسبة الحروف اللاتينية إلى مجموع الحروف */
    public static function latinShare(string $s): float
    {
        $lat = preg_match_all('/\p{Latin}/u', $s); $ara = preg_match_all('/\p{Arabic}/u', $s);
        return ($lat + $ara) ? $lat / ($lat + $ara) : 1.0;
    }

    /** تحقق ORCID بخوارزمية ISO 7064 MOD 11-2 */
    public static function validOrcid(?string $orcid): bool
    {
        if (!$orcid || !preg_match('/(\d{4})-?(\d{4})-?(\d{4})-?(\d{3}[\dX])$/i', trim($orcid), $m)) return false;
        $digits = $m[1] . $m[2] . $m[3] . $m[4]; $total = 0;
        for ($i = 0; $i < 15; $i++) $total = ($total + (int) $digits[$i]) * 2;
        $check = (12 - $total % 11) % 11;
        return strtoupper($digits[15]) === ($check === 10 ? 'X' : (string) $check);
    }

    /** تحقق ISSN (رقم التحقق mod 11) */
    public static function validIssn(?string $issn): bool
    {
        if (!$issn || !preg_match('/^(\d{4})-?(\d{3}[\dX])$/i', trim($issn), $m)) return false;
        $d = $m[1] . $m[2]; $sum = 0;
        for ($i = 0; $i < 7; $i++) $sum += (int) $d[$i] * (8 - $i);
        $c = (11 - $sum % 11) % 11;
        return strtoupper($d[7]) === ($c === 10 ? 'X' : (string) $c);
    }

    private function item(string $id, string $level, bool $ok, string $detail = '', array $std = []): array
    {
        return ['id' => $id, 'level' => $level, 'ok' => $ok, 'detail' => $detail, 'std' => $std];
    }

    private function langName(string $l): string
    {
        return Locale::getMetadata($l)?->getDisplayName() ?? $l;
    }

    // ================================================================ مستوى البحث
    /** @return array{checks: array, score: int, missingRequired: int, missingRecommended: int} */
    public function checkPublication($publication, $submission, $context): array
    {
        $c = [];
        $locales = $this->cfg['locales'];
        $exempt = in_array((int) $publication->getData('sectionId'), $this->cfg['exemptSections'] ?? [], true);

        // العنوان والملخص والكلمات المفتاحية بكل لغة مطلوبة
        foreach ($locales as $l) {
            $ln = $this->langName($l);
            $title = self::plain($publication->getData('title', $l) ?? '');
            $c[] = $this->item('title', self::REQUIRED, $title !== '', $ln, ['Scopus', 'WoS', 'DOAJ', 'Crossref']);
            if ($title !== '' && $l === 'en' && self::hasArabic($title)) {
                $c[] = $this->item('titleScript', self::REQUIRED, false, $ln, ['WoS', 'Scopus']);
            }
            if (!$exempt) {
                $abs = self::plain($publication->getData('abstract', $l) ?? '');
                $w = self::words($abs);
                $c[] = $this->item('abstract', self::REQUIRED, $w >= (int) $this->cfg['minAbstractWords'], $ln . ' — ' . $w . ' / ' . (int) $this->cfg['minAbstractWords'], ['Scopus', 'WoS', 'DOAJ']);
                $kws = $publication->getData('keywords')[$l] ?? [];
                $kc = count(array_filter((array) $kws));
                $c[] = $this->item('keywords', self::RECOMMENDED, $kc >= (int) $this->cfg['minKeywords'], $ln . ' — ' . $kc . ' / ' . (int) $this->cfg['minKeywords'], ['Scopus', 'DOAJ']);
            }
        }

        // المؤلفون
        $authors = $publication->getData('authors') ?? collect();
        $c[] = $this->item('authors', self::REQUIRED, count($authors) > 0, (string) count($authors), ['Crossref', 'WoS']);
        $primaryId = (int) $publication->getData('primaryContactId');
        $missingName = []; $missingAff = []; $missingAffEn = []; $missingCountry = []; $badOrcid = []; $noOrcid = []; $missingCI = [];
        foreach ($authors as $a) {
            $name = $a->getFullName(false, false) ?: ('#' . $a->getId());
            foreach ($locales as $l) {
                if (trim((string) $a->getGivenName($l)) === '' && trim((string) $a->getFamilyName($l)) === '') $missingName[] = $name . ' (' . $l . ')';
            }
            $affs = $a->getData('affiliations') ?? [];
            $hasAff = false; $hasAffEn = false;
            foreach ($affs as $af) {
                $hasAff = true;
                $names = is_object($af) && method_exists($af, 'getData') ? (array) $af->getData('name') : (array) ($af['name'] ?? []);
                if (!empty($names['en']) || (is_object($af) && method_exists($af, 'getData') && $af->getData('ror'))) $hasAffEn = true;
            }
            if (!$hasAff && method_exists($a, 'getLocalizedAffiliation') && trim((string) @$a->getLocalizedAffiliation()) !== '') $hasAff = true;
            if (!$hasAff) $missingAff[] = $name; elseif (in_array('en', $locales, true) && !$hasAffEn) $missingAffEn[] = $name;
            if (!$a->getData('country')) $missingCountry[] = $name;
            $orcid = (string) $a->getData('orcid');
            if ($orcid !== '' && !self::validOrcid($orcid)) $badOrcid[] = $name;
            $needOrcid = ($this->cfg['requireOrcid'] ?? 'corresponding') === 'all' || (($this->cfg['requireOrcid'] ?? '') === 'corresponding' && (int) $a->getId() === $primaryId);
            if ($needOrcid && $orcid === '') $noOrcid[] = $name;
            if ($context->getData('requireAuthorCompetingInterests') && trim(self::plain($a->getLocalizedData('competingInterests'))) === '') $missingCI[] = $name;
        }
        $c[] = $this->item('authorNames', self::REQUIRED, !$missingName, implode('، ', $missingName), ['WoS', 'Crossref']);
        $c[] = $this->item('affiliation', self::REQUIRED, !$missingAff, implode('، ', $missingAff), ['WoS', 'Scopus']);
        if (in_array('en', $locales, true)) $c[] = $this->item('affiliationEn', self::RECOMMENDED, !$missingAffEn, implode('، ', $missingAffEn), ['WoS', 'Crossref']);
        $c[] = $this->item('country', self::RECOMMENDED, !$missingCountry, implode('، ', $missingCountry), ['Scopus']);
        $c[] = $this->item('orcidValid', self::REQUIRED, !$badOrcid, implode('، ', $badOrcid), ['Crossref']);
        if (($this->cfg['requireOrcid'] ?? 'corresponding') !== 'none') {
            $c[] = $this->item('orcid', self::RECOMMENDED, !$noOrcid, implode('، ', $noOrcid), ['Crossref', 'WoS']);
        }
        if ($context->getData('requireAuthorCompetingInterests')) {
            $c[] = $this->item('competingInterests', self::RECOMMENDED, !$missingCI, implode('، ', $missingCI), ['COPE']);
        }
        $primary = $primaryId ? $authors->first(fn ($a) => (int) $a->getId() === $primaryId) : null;
        $c[] = $this->item('correspondingEmail', self::REQUIRED, $primary && filter_var($primary->getData('email'), FILTER_VALIDATE_EMAIL), '', ['WoS']);

        // المعرفات والترخيص
        $doiEnabled = (bool) $context->getData('enableDois') && in_array('publication', (array) $context->getData('enabledDoiTypes'), true);
        if ($doiEnabled) $c[] = $this->item('doi', self::REQUIRED, (bool) $publication->getData('doiId'), (string) $publication->getDoi(), ['Crossref', 'Scopus', 'WoS']);
        $c[] = $this->item('license', self::REQUIRED, (bool) ($publication->getData('licenseUrl') ?: $context->getData('licenseUrl')), (string) ($publication->getData('licenseUrl') ?: $context->getData('licenseUrl')), ['DOAJ', 'Crossref']);
        $c[] = $this->item('copyright', self::RECOMMENDED, (bool) ($publication->getLocalizedData('copyrightHolder') && $publication->getData('copyrightYear')), '', ['DOAJ']);
        $c[] = $this->item('pages', self::RECOMMENDED, trim((string) $publication->getData('pages')) !== '', (string) $publication->getData('pages'), ['Crossref', 'Scopus']);

        // المراجع
        if (!$exempt) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $publication->getData('citationsRaw')))));
            $c[] = $this->item('references', self::REQUIRED, count($lines) > 0, (string) count($lines), ['Scopus', 'Crossref']);
            if ($lines) {
                $nonRoman = count(array_filter($lines, fn ($x) => self::latinShare($x) < 0.5));
                $c[] = $this->item('referencesRoman', self::RECOMMENDED, $nonRoman === 0, $nonRoman . ' / ' . count($lines), ['Scopus', 'WoS']);
                $withDoi = count(array_filter($lines, fn ($x) => preg_match('#10\.\d{4,9}/\S+#', $x)));
                $c[] = $this->item('referencesDoi', self::RECOMMENDED, $withDoi > 0, $withDoi . ' / ' . count($lines), ['Crossref']);
            }
        }

        // الإصدار والملفات والتواريخ
        $c[] = $this->item('issue', self::REQUIRED, (bool) $publication->getData('issueId'), '', ['Scopus']);
        $galleys = $publication->getData('galleys') ?? collect();
        $c[] = $this->item('galley', self::REQUIRED, count($galleys) > 0, (string) count($galleys), ['Scopus', 'WoS', 'DOAJ']);
        $accepted = DB::table('edit_decisions')->where('submission_id', $submission->getId())->whereIn('decision', [\PKP\decision\Decision::ACCEPT, \PKP\decision\Decision::SKIP_EXTERNAL_REVIEW])->exists();
        $c[] = $this->item('acceptDecision', self::RECOMMENDED, $accepted, '', ['COPE', 'WoS']);
        $c[] = $this->item('funding', self::RECOMMENDED, (bool) array_filter((array) $publication->getData('supportingAgencies')) || trim(self::plain($publication->getLocalizedData('dataAvailability'))) !== '', '', ['Crossref', 'COPE']);

        return $this->summarize($c);
    }

    private function summarize(array $c): array
    {
        $req = array_filter($c, fn ($x) => $x['level'] === self::REQUIRED);
        $rec = array_filter($c, fn ($x) => $x['level'] === self::RECOMMENDED);
        $okReq = count(array_filter($req, fn ($x) => $x['ok'])); $okRec = count(array_filter($rec, fn ($x) => $x['ok']));
        // الدرجة: 75٪ للإلزامي و25٪ للموصى به
        $score = (int) round(100 * (0.75 * ($req ? $okReq / count($req) : 1) + 0.25 * ($rec ? $okRec / count($rec) : 1)));
        return ['checks' => $c, 'score' => $score, 'missingRequired' => count($req) - $okReq, 'missingRecommended' => count($rec) - $okRec];
    }

    // ================================================================ مستوى المجلة
    public function checkJournal($context): array
    {
        $c = []; $cid = (int) $context->getId();
        $txt = fn (string $k, ?string $l = null) => self::plain($l ? $context->getData($k, $l) : $context->getLocalizedData($k));
        $online = (string) $context->getData('onlineIssn'); $print = (string) $context->getData('printIssn');
        $c[] = $this->item('jIssn', self::REQUIRED, ($online !== '' && self::validIssn($online)) || ($print !== '' && self::validIssn($print)), trim($online . ' ' . $print), ['Scopus', 'WoS', 'DOAJ']);
        if ($online !== '' && !self::validIssn($online)) $c[] = $this->item('jIssnChecksum', self::REQUIRED, false, $online, ['DOAJ']);
        if ($print !== '' && !self::validIssn($print)) $c[] = $this->item('jIssnChecksum', self::REQUIRED, false, $print, ['DOAJ']);
        $c[] = $this->item('jPublisher', self::REQUIRED, trim((string) $context->getData('publisherInstitution')) !== '', (string) $context->getData('publisherInstitution'), ['Scopus', 'WoS', 'DOAJ']);
        $c[] = $this->item('jContact', self::REQUIRED, filter_var($context->getData('contactEmail'), FILTER_VALIDATE_EMAIL) && trim($txt('mailingAddress')) !== '', '', ['WoS', 'DOAJ']);
        foreach ($this->cfg['locales'] as $l) {
            $c[] = $this->item('jAbout', self::REQUIRED, self::words($txt('about', $l)) >= 50 || self::words($txt('description', $l)) >= 30, $this->langName($l), ['Scopus', 'WoS', 'DOAJ']);
        }
        $c[] = $this->item('jEnglish', self::REQUIRED, in_array('en', (array) $context->getSupportedLocales(), true), '', ['Scopus', 'WoS']);
        $all = mb_strtolower($txt('about') . ' ' . $txt('reviewGuidelines') . ' ' . $txt('authorGuidelines') . ' ' . $txt('about', 'en') . ' ' . $txt('about', 'ar'));
        $c[] = $this->item('jPeerReview', self::REQUIRED, (bool) preg_match('/peer[\s-]?review|blind|التحكيم|تحكيم|محكم/u', $all), '', ['Scopus', 'WoS', 'DOAJ']);
        $c[] = $this->item('jEthics', self::REQUIRED, (bool) preg_match('/ethic|cope|malpractice|plagiar|أخلاقيات|الانتحال|سوء الممارسة/u', $all), '', ['Scopus', 'WoS', 'DOAJ']);
        $c[] = $this->item('jOpenAccess', self::REQUIRED, trim($txt('openAccessPolicy')) !== '' || (bool) preg_match('/open access|الوصول المفتوح/u', $all), '', ['DOAJ']);
        $c[] = $this->item('jLicense', self::REQUIRED, (bool) $context->getData('licenseUrl'), (string) $context->getData('licenseUrl'), ['DOAJ', 'Crossref']);
        $c[] = $this->item('jCopyright', self::RECOMMENDED, trim($txt('copyrightNotice')) !== '', '', ['DOAJ']);
        $c[] = $this->item('jPrivacy', self::RECOMMENDED, trim($txt('privacyStatement')) !== '', '', ['DOAJ']);
        $c[] = $this->item('jAuthorGuidelines', self::REQUIRED, self::words($txt('authorGuidelines')) >= 30, '', ['WoS', 'DOAJ']);
        $c[] = $this->item('jFees', self::RECOMMENDED, (bool) preg_match('/fee|charge|apc|رسوم/u', $all . ' ' . mb_strtolower($txt('authorGuidelines'))) || (bool) $context->getData('publicationFee'), '', ['DOAJ', 'OASPA']);
        $c[] = $this->item('jArchiving', self::RECOMMENDED, (bool) ($context->getData('enableLockss') || $context->getData('enableClockss')) || (bool) preg_match('/lockss|clockss|portico|pkp pn|archiv|أرشف|الحفظ/u', $all), '', ['DOAJ']);
        $c[] = $this->item('jDoi', self::RECOMMENDED, (bool) $context->getData('enableDois') && trim((string) $context->getData('doiPrefix')) !== '', (string) $context->getData('doiPrefix'), ['Crossref', 'Scopus']);
        $c[] = $this->item('jOai', self::RECOMMENDED, (bool) ($context->getData('enableOai') ?? true), '', ['DOAJ', 'Google Scholar']);

        // هيئة التحرير: انتماءات وتنوع جغرافي
        $groupIds = (array) $context->getData('mastheadUserGroupIds');
        if (!$groupIds) {
            $groupIds = DB::table('user_groups')->where('context_id', $cid)->where('masthead', 1)->pluck('user_group_id')->all();
        }
        $members = $groupIds ? DB::table('user_user_groups')->whereIn('user_group_id', $groupIds)
            ->where(fn ($q) => $q->whereNull('date_end')->orWhere('date_end', '>', now()))->distinct()->pluck('user_id')->all() : [];
        $withAff = 0; $countries = [];
        foreach ($members as $uid) {
            $u = Repo::user()->get((int) $uid, true);
            if (!$u) continue;
            if (trim((string) $u->getLocalizedData('affiliation')) !== '') $withAff++;
            if ($u->getData('country')) $countries[$u->getData('country')] = true;
        }
        $n = count($members);
        $c[] = $this->item('jBoard', self::REQUIRED, $n >= 5, (string) $n, ['Scopus', 'WoS']);
        $c[] = $this->item('jBoardAffiliation', self::REQUIRED, $n > 0 && $withAff === $n, $withAff . ' / ' . $n, ['WoS', 'Scopus']);
        $c[] = $this->item('jBoardDiversity', self::RECOMMENDED, count($countries) >= 3, (string) count($countries), ['Scopus', 'WoS']);

        // تنوع المؤلفين وانتظام الصدور (آخر 3 سنوات)
        $since = date('Y-m-d', strtotime('-3 years'));
        $pubIds = DB::table('publications as p')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $cid)->where('p.status', 3)->where('p.date_published', '>=', $since)->pluck('p.publication_id')->all();
        $acountries = $pubIds ? DB::table('author_settings as s')->join('authors as a', 'a.author_id', '=', 's.author_id')
            ->whereIn('a.publication_id', $pubIds)->where('s.setting_name', 'country')->where('s.setting_value', '<>', '')->pluck('s.setting_value')->all() : [];
        $top = $acountries ? max(array_count_values($acountries)) / count($acountries) : 1;
        $c[] = $this->item('jAuthorDiversity', self::RECOMMENDED, count(array_unique($acountries)) >= 3 && $top <= 0.8, count(array_unique($acountries)) . ' — ' . round($top * 100) . '%', ['Scopus', 'WoS']);
        $years = DB::table('issues')->where('journal_id', $cid)->where('published', 1)->where('date_published', '>=', $since)
            ->selectRaw('EXTRACT(YEAR FROM date_published) as y')->distinct()->pluck('y')->all();
        $c[] = $this->item('jRegularity', self::REQUIRED, count($years) >= 2, count($years) . ' / 3', ['Scopus', 'WoS']);
        $noGalley = $pubIds ? DB::table('publications as p')->whereIn('p.publication_id', $pubIds)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('publication_galleys as g')->whereColumn('g.publication_id', 'p.publication_id'))->count() : 0;
        $c[] = $this->item('jFullText', self::REQUIRED, $noGalley === 0, $noGalley . ' / ' . count($pubIds), ['Scopus', 'WoS']);
        $c[] = $this->item('jScholarPlugin', self::RECOMMENDED, (bool) DB::table('plugin_settings')->where('plugin_name', 'googlescholarplugin')->where('context_id', $cid)->where('setting_name', 'enabled')->where('setting_value', '1')->exists(), '', ['Google Scholar']);
        return $this->summarize($c);
    }
}
