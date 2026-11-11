<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/indexingReadiness/classes/Criteria.php
 *
 * معايير التقييم كما تعلنها القواعد:
 *  - Scopus (Content Selection & Advisory Board): الشروط الأولية + 14 معيارًا في 5 فئات.
 *  - Web of Science Core Collection: 24 معيار جودة (الفرز الأولي، الفرز التحريري، التقييم التحريري) + 4 معايير أثر.
 *  - DOAJ: المعايير الأساسية للإدراج.
 * لكل معيار: قياس آلي من بيانات OJS إن أمكن، وإلا يقيّمه المحرر يدويًا مع رابط الدليل.
 * العتبات الرقمية إرشادية من خبرة التقييم، وليست أرقامًا رسمية معلنة من القواعد.
 */
namespace APP\plugins\generic\tadabburSuite\m\indexingReadiness\classes;

class Criteria
{
    public const MET = 'met', PARTIAL = 'partial', UNMET = 'unmet', INFO = 'info', MANUAL = 'manual';

    /** [db => [group => [criterionId => level]]]  level: req|rec|impact */
    public const MAP = [
        'scopus' => [
            'pre' => ['issn' => 'req', 'peerReview' => 'req', 'regular' => 'req', 'englishAbstracts' => 'req', 'romanRefs' => 'req', 'ethics' => 'req'],
            'policy' => ['editorialPolicy' => 'req', 'reviewType' => 'req', 'boardDiversity' => 'req', 'authorDiversity' => 'req'],
            'content' => ['contribution' => 'req', 'abstractClarity' => 'req', 'scopeConformity' => 'req', 'readability' => 'req'],
            'standing' => ['citedness' => 'req', 'editorStanding' => 'req', 'selfCitation' => 'rec'],
            'regularity' => ['noDelays' => 'req'],
            'online' => ['fullTextOnline' => 'req', 'englishHome' => 'req', 'homeQuality' => 'req'],
        ],
        'wos' => [
            'initial' => ['issn' => 'req', 'title' => 'req', 'publisher' => 'req', 'url' => 'req', 'access' => 'req', 'peerReview' => 'req', 'contact' => 'req'],
            'editorial' => ['scholarly' => 'req', 'englishAbstracts' => 'req', 'romanBiblio' => 'req', 'languageClarity' => 'req', 'timeliness' => 'req', 'website' => 'req', 'ethics' => 'req', 'boardAffiliation' => 'req', 'authorAffiliation' => 'req'],
            'evaluation' => ['boardComposition' => 'req', 'validStatements' => 'req', 'peerReviewEvidence' => 'req', 'contentRelevance' => 'req', 'grantDetails' => 'rec', 'communityStandards' => 'req', 'authorDistribution' => 'req', 'appropriateCitations' => 'req'],
            'impact' => ['comparativeCitation' => 'impact', 'authorCitation' => 'impact', 'boardCitation' => 'impact', 'significance' => 'impact'],
        ],
        'doaj' => [
            'basic' => ['issn' => 'req', 'oaNoEmbargo' => 'req', 'license' => 'req', 'peerReview' => 'req', 'aims' => 'req', 'board' => 'req', 'authorGuidelines' => 'req', 'fees' => 'req', 'copyright' => 'req', 'volume' => 'req', 'plagiarism' => 'req'],
            'best' => ['archiving' => 'rec', 'pid' => 'rec', 'oaiPmh' => 'rec', 'orcid' => 'rec', 'ethicsPolicies' => 'rec'],
        ],
    ];

    private static function r(string $st, string $val = ''): array { return ['status' => $st, 'value' => $val]; }
    private static function pctStatus(?float $v, float $met, float $partial): array
    {
        if ($v === null) return self::r(self::UNMET, '—');
        return self::r($v >= $met ? self::MET : ($v >= $partial ? self::PARTIAL : self::UNMET), $v . '%');
    }
    private static function bool(bool $b, string $val = ''): array { return self::r($b ? self::MET : self::UNMET, $val); }

    /** تقييم آلي لمعيار؛ null = يدوي */
    public static function auto(string $db, string $id, array $m, ?array $oa): ?array
    {
        $p = $m['policy'];
        $years = implode(' · ', array_map(fn ($y, $n) => "$y: $n", array_keys($m['articlesByYear']), $m['articlesByYear']));
        switch ($id) {
            case 'issn': return self::bool($m['issnValid'], trim($m['issnOnline'] . ' ' . $m['issnPrint']));
            case 'peerReview': return self::bool($p['peerReview']);
            case 'reviewType': return self::bool($p['reviewType'] && $p['peerReview']);
            case 'regular': case 'timeliness':
                $v = implode(' · ', array_map(fn ($y, $n) => "$y: $n", array_keys($m['issuesByYear']), $m['issuesByYear']));
                return self::r($m['regularYears'] >= 2 && !$m['delayed'] ? self::MET : ($m['regularYears'] >= 1 ? self::PARTIAL : self::UNMET), $v);
            case 'noDelays':
                return self::r(!$m['delayed'] && $m['maxGapDays'] <= 1.6 * 365 / max(1, $m['expectedPerYear']) ? self::MET : ($m['delayed'] ? self::UNMET : self::PARTIAL), ($m['lastIssue'] ?? '—') . ' · max gap ' . $m['maxGapDays'] . 'd');
            case 'englishAbstracts':
                $a = self::pctStatus($m['enAbstractPct'], 95, 70); $t = self::pctStatus($m['enTitlePct'], 95, 70);
                $st = [$a['status'], $t['status']];
                return self::r(in_array(self::UNMET, $st, true) ? self::UNMET : (in_array(self::PARTIAL, $st, true) ? self::PARTIAL : self::MET), 'abs ' . $a['value'] . ' · title ' . $t['value']);
            case 'romanRefs': case 'romanBiblio':
                if (!$m['refsTotal']) return self::r(self::UNMET, '0 refs');
                return self::pctStatus($m['refsRomanPct'], 90, 50);
            case 'ethics': return self::bool($p['ethics']);
            case 'ethicsPolicies':
                $n = (int) $p['ethics'] + (int) $p['conflicts'] + (int) $p['retraction'] + (int) $p['complaints'] + (int) $p['aiPolicy'];
                return self::r($n >= 4 ? self::MET : ($n >= 2 ? self::PARTIAL : self::UNMET), $n . '/5');
            case 'boardDiversity': case 'boardComposition':
                $b = $m['board'];
                $ok = $b['n'] >= 7 && $b['countries'] >= 3; $part = $b['n'] >= 5 && $b['countries'] >= 2;
                return self::r($ok ? self::MET : ($part ? self::PARTIAL : self::UNMET), $b['n'] . ' / ' . $b['countries'] . ' countries' . ($b['foreignPct'] !== null ? ' · intl ' . $b['foreignPct'] . '%' : ''));
            case 'authorDiversity': case 'authorDistribution':
                if (!$m['authorCountries']) return self::r(self::UNMET, '—');
                $top = $m['authorTopShare'] ?? 100;
                return self::r($m['authorCountries'] >= 3 && $top <= 60 ? self::MET : ($m['authorCountries'] >= 2 && $top <= 85 ? self::PARTIAL : self::UNMET),
                    $m['authorCountries'] . ' countries · top ' . ($m['authorTopCountry'] ?? '') . ' ' . $top . '%');
            case 'selfCitation':
                if (!$m['refsTotal']) return self::r(self::INFO, '—');
                return self::r($m['selfCitePct'] <= 15 ? self::MET : ($m['selfCitePct'] <= 30 ? self::PARTIAL : self::UNMET), $m['selfCitePct'] . '% (' . $m['selfCites'] . '/' . $m['refsTotal'] . ')');
            case 'citedness': case 'comparativeCitation':
                if (!$oa || empty($oa['ok'])) return self::r(self::INFO, 'OpenAlex —');
                $s = $oa['source'];
                return self::r(self::INFO, 'cited ' . $s['cited'] . ' · h ' . $s['h'] . ' · 2y ' . $s['meanCitedness2y']);
            case 'editorStanding': case 'boardCitation':
                if (!$oa || empty($oa['board'])) return self::r(self::INFO, 'ORCID ' . ($m['board']['orcidPct'] ?? 0) . '%');
                $hs = array_column($oa['board'], 'h');
                return self::r(self::INFO, count($hs) . ' in OpenAlex · median h ' . self::median($hs) . ' · max h ' . max($hs));
            case 'fullTextOnline': case 'access': case 'oaNoEmbargo': return self::bool($p['oa'] && $m['articles'] > 0, $m['articles'] . ' articles');
            case 'englishHome': return self::bool($m['englishSite']);
            case 'title': return self::r(self::MET, '');
            case 'publisher': return self::bool($m['publisher'] !== '', $m['publisher']);
            case 'url': return self::r(self::MET, '');
            case 'contact': return self::bool(filter_var($m['contactEmail'], FILTER_VALIDATE_EMAIL) !== false, $m['contactEmail']);
            case 'boardAffiliation': return self::pctStatus($m['board']['affPct'], 100, 80);
            case 'authorAffiliation': return self::pctStatus($m['affPct'], 95, 75);
            case 'license': return self::r($m['licenseCC'] ? self::MET : ($m['license'] !== '' ? self::PARTIAL : self::UNMET), $m['license']);
            case 'aims': return self::bool($p['aims']);
            case 'board': return self::r($m['board']['n'] >= 5 && ($m['board']['affPct'] ?? 0) >= 90 ? self::MET : ($m['board']['n'] > 0 ? self::PARTIAL : self::UNMET), (string) $m['board']['n']);
            case 'authorGuidelines': return self::bool($p['guidelines']);
            case 'fees': return self::bool($p['fees']);
            case 'copyright': return self::bool($p['copyright']);
            case 'volume': return self::r($m['articlesLastYear'] >= 5 ? self::MET : self::UNMET, $years);
            case 'plagiarism': return self::bool($p['plagiarism']);
            case 'archiving': return self::bool($p['archiving']);
            case 'pid': return self::r($m['doiEnabled'] && ($m['doiPct'] ?? 0) >= 95 ? self::MET : ($m['doiEnabled'] ? self::PARTIAL : self::UNMET), 'DOI ' . ($m['doiPct'] ?? 0) . '%');
            case 'oaiPmh': return self::bool($m['oai']);
            case 'orcid': return self::pctStatus($m['orcidPct'], 80, 40);
            case 'grantDetails': return null;
        }
        return null;
    }

    private static function median(array $v): float
    {
        sort($v); $n = count($v); if (!$n) return 0;
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /**
     * تقييم كل المعايير مع التقييمات اليدوية والتجاوزات.
     * @param array $manual [db.id => ['status'=>..,'url'=>..,'note'=>..]]
     */
    public static function evaluate(array $m, ?array $oa, array $manual): array
    {
        $out = [];
        foreach (self::MAP as $db => $groups) {
            $sum = 0; $max = 0; $counts = [self::MET => 0, self::PARTIAL => 0, self::UNMET => 0, self::MANUAL => 0, self::INFO => 0];
            foreach ($groups as $g => $items) {
                foreach ($items as $id => $level) {
                    $auto = self::auto($db, $id, $m, $oa);
                    $man = $manual[$db . '.' . $id] ?? null;
                    $st = $auto['status'] ?? self::MANUAL; $source = $auto ? 'auto' : 'manual';
                    if ($man && in_array($man['status'] ?? '', [self::MET, self::PARTIAL, self::UNMET], true)) { $st = $man['status']; $source = $auto ? 'override' : 'manual'; }
                    $counts[$st]++;
                    if ($level !== 'impact' && $st !== self::INFO) {
                        $w = $level === 'req' ? 2 : 1;
                        $max += $w;
                        $sum += $w * ($st === self::MET ? 1 : ($st === self::PARTIAL ? 0.5 : 0));
                    }
                    $out[$db]['groups'][$g][$id] = ['level' => $level, 'status' => $st, 'value' => $auto['value'] ?? '', 'source' => $source,
                        'autoStatus' => $auto['status'] ?? null, 'url' => (string) ($man['url'] ?? ''), 'note' => (string) ($man['note'] ?? '')];
                }
            }
            $out[$db]['score'] = $max ? (int) round(100 * $sum / $max) : 0;
            $out[$db]['counts'] = $counts;
        }
        return $out;
    }
}
