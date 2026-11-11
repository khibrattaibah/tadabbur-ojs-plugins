<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/kit/Meta.php
 * لقطة موحّدة لبيانات بحث (عناوين، ملخصات، كلمات، مؤلفون، عدد، DOI، صفحات، ملفات، مراجع)
 * تستعملها وحدات JATS وGoogle Scholar والفهارس العربية وبنّاء العدد.
 */
namespace APP\plugins\generic\tadabburSuite\classes\kit;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;

class Meta
{
    public static function plain($html): string
    {
        $s = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n{3,}/", "\n\n", $s)));
    }

    /** أول قيمة غير فارغة بترتيب اللغات */
    public static function pick($arr, array $order = ['ar', 'en']): string
    {
        if (!is_array($arr)) return (string) $arr;
        foreach ($order as $l) if (!empty($arr[$l])) return (string) $arr[$l];
        foreach ($arr as $v) if ($v !== '' && $v !== null) return (string) $v;
        return '';
    }

    public static function snapshot($submission, $context, $request = null, $publication = null): array
    {
        $p = $publication ?: $submission->getCurrentPublication();
        $sid = (int) $submission->getId();
        $out = ['id' => $sid, 'pubId' => (int) $p->getId(), 'locale' => (string) ($p->getData('locale') ?: $submission->getData('locale') ?: 'ar'),
            'status' => (int) $p->getData('status'), 'stageId' => (int) $submission->getData('stageId')];
        foreach (['title', 'subtitle'] as $k) $out[$k . 's'] = array_map(fn ($v) => self::plain($v), array_filter((array) $p->getData($k)));
        $out['abstracts'] = array_map(fn ($v) => self::plain($v), array_filter((array) $p->getData('abstract')));
        $kw = [];
        foreach ((array) $p->getData('keywords') as $l => $list) foreach ((array) $list as $w) { $w = is_array($w) ? ($w['name'] ?? '') : (string) $w; if (trim($w) !== '') $kw[$l][] = trim($w); }
        $out['keywords'] = $kw;
        $out['authors'] = [];
        foreach ($p->getData('authors') ?? [] as $a) {
            $affs = [];
            foreach ($a->getAffiliations() as $af) {
                $n = (array) $af->getName(null);
                foreach ($n as $l => $v) if ($v !== '' && $v !== null) $affs[$l][] = $v;
                $ror = $af->getRor();
            }
            $given = array_filter((array) $a->getData('givenName')); $family = array_filter((array) $a->getData('familyName'));
            $names = [];
            foreach (array_unique(array_merge(array_keys($given), array_keys($family))) as $l) $names[$l] = trim(($given[$l] ?? '') . ' ' . ($family[$l] ?? ''));
            $orcid = (string) $a->getData('orcid');
            $out['authors'][] = ['given' => $given, 'family' => $family, 'names' => $names, 'affiliations' => array_map(fn ($x) => implode('; ', $x), $affs),
                'ror' => $ror ?? '', 'orcid' => $orcid, 'orcidId' => preg_match('~(\d{4}-\d{4}-\d{4}-\d{3}[\dX])~', $orcid, $m) ? $m[1] : '',
                'email' => (string) $a->getData('email'), 'country' => (string) $a->getData('country'), 'primary' => (int) $a->getId() === (int) $p->getData('primaryContactId')];
            unset($ror);
        }
        $out['doi'] = (string) ($p->getDoi() ?? '');
        $out['pages'] = (string) ($p->getData('pages') ?? '');
        [$out['firstPage'], $out['lastPage']] = array_pad(array_map('trim', explode('-', $out['pages'], 2)), 2, '');
        $out['datePublished'] = (string) ($p->getData('datePublished') ?? '');
        $out['dateSubmitted'] = substr((string) $submission->getData('dateSubmitted'), 0, 10);
        $out['dateAccepted'] = (string) (DB::table('edit_decisions')->where('submission_id', $sid)->whereIn('decision', [2, 17])->max('date_decided') ?? '');
        $out['dateAccepted'] = substr($out['dateAccepted'], 0, 10);
        $out['license'] = (string) ($p->getData('licenseUrl') ?: $context->getData('licenseUrl'));
        $out['copyrightHolder'] = self::pick($p->getData('copyrightHolder'));
        $out['copyrightYear'] = (string) ($p->getData('copyrightYear') ?? '');
        $sec = $p->getData('sectionId') ? Repo::section()->get((int) $p->getData('sectionId')) : null;
        $out['section'] = $sec ? array_filter((array) $sec->getData('title')) : [];
        $out['issue'] = null;
        if ($p->getData('issueId') && ($is = Repo::issue()->get((int) $p->getData('issueId')))) {
            $out['issue'] = ['id' => (int) $is->getId(), 'volume' => (string) $is->getVolume(), 'number' => (string) $is->getNumber(), 'year' => (string) $is->getYear(),
                'title' => array_filter((array) $is->getData('title')), 'published' => (bool) $is->getPublished(), 'datePublished' => substr((string) $is->getDatePublished(), 0, 10)];
        }
        $out['citations'] = DB::table('citations')->where('publication_id', $p->getId())->orderBy('seq')->pluck('raw_citation')->map(fn ($c) => trim($c))->filter()->values()->all();
        $out['galleys'] = [];
        foreach ($p->getData('galleys') ?? [] as $g) {
            $type = (string) ($g->getFileType() ?? '');
            $label = (string) $g->getLabel(); $remote = (string) ($g->getData('urlRemote') ?? '');
            $isPdf = $type === 'application/pdf' || stripos($label, 'pdf') !== false;
            $gid = $g->getBestGalleyId();
            $out['galleys'][] = ['id' => (int) $g->getId(), 'label' => $label, 'locale' => (string) $g->getData('locale'), 'type' => $type, 'pdf' => $isPdf, 'remote' => $remote,
                'view' => $request ? $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$submission->getBestId(), $gid]) : '',
                'download' => $remote !== '' ? $remote : ($request ? $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$submission->getBestId(), $gid]) : '')];
        }
        $out['url'] = $request ? $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$submission->getBestId()]) : '';
        return $out;
    }

    /** بيانات المجلة الثابتة */
    public static function journal($context): array
    {
        return ['names' => array_filter((array) $context->getData('name')), 'abbrev' => array_filter((array) $context->getData('abbreviation')),
            'acronym' => self::pick($context->getData('acronym')), 'issn' => (string) $context->getData('printIssn'), 'eissn' => (string) $context->getData('onlineIssn'),
            'publisher' => (string) $context->getData('publisherInstitution'), 'path' => $context->getPath(), 'locales' => (array) $context->getSupportedSubmissionLocales()];
    }
}
