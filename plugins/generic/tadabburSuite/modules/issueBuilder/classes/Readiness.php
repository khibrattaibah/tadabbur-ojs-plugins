<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/issueBuilder/classes/Readiness.php
 * فحوص جاهزية البحث للنشر في العدد.
 */
namespace APP\plugins\generic\tadabburSuite\m\issueBuilder\classes;

class Readiness
{
    /** @return array<string,bool> */
    public static function check(array $m): array
    {
        $authorsOk = $m['authors'] && !array_filter($m['authors'], fn ($a) => empty($a['affiliations']));
        return [
            'titleAr' => !empty($m['titles']['ar']), 'titleEn' => !empty($m['titles']['en']),
            'absAr' => !empty($m['abstracts']['ar']), 'absEn' => !empty($m['abstracts']['en']),
            'kw' => !empty($m['keywords']['ar']) && !empty($m['keywords']['en']),
            'authors' => $authorsOk, 'orcid' => $m['authors'] && !array_filter($m['authors'], fn ($a) => $a['orcidId'] === ''),
            'refs' => count($m['citations']) >= 5, 'doi' => $m['doi'] !== '', 'pages' => (bool) preg_match('/^\d+\s*-\s*\d+$/', $m['pages']),
            'pdf' => (bool) array_filter($m['galleys'], fn ($g) => $g['pdf']), 'section' => !empty($m['section']),
        ];
    }

    /** الفحوص التي لا يصح النشر بدونها */
    public const REQUIRED = ['titleAr', 'titleEn', 'absAr', 'absEn', 'authors', 'pdf', 'pages', 'section'];
}
