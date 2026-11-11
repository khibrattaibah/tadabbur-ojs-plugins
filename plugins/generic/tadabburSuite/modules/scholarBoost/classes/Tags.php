<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/scholarBoost/classes/Tags.php
 * الوسوم المكمِّلة لإضافة Google Scholar الأساسية في OJS، وبيانات JSON-LD (ScholarlyArticle).
 */
namespace APP\plugins\generic\tadabburSuite\m\scholarBoost\classes;

use APP\plugins\generic\tadabburSuite\classes\kit\Meta;

class Tags
{
    private static function m(string $name, string $content, string $lang = ''): string
    {
        return '<meta name="' . $name . '"' . ($lang !== '' ? ' xml:lang="' . htmlspecialchars($lang) . '"' : '') . ' content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '"/>';
    }

    public static function headers($submission, $ctx, $request, array $o): array
    {
        if ((int) $submission->getData('status') !== 3) return [];
        $m = Meta::snapshot($submission, $ctx, $request); $j = Meta::journal($ctx);
        $lang = substr($m['locale'], 0, 2); $other = $lang === 'ar' ? 'en' : 'ar';
        $h = [];
        if ($o['tags']) {
            // الناشر الحقيقي بدل اسم المجلة (يستبدل وسم الإضافة الأساسية بالاسم نفسه)
            if ($j['publisher'] !== '') $h['googleScholarPublisher'] = self::m('citation_publisher', $j['publisher']);
            $h['Free'] = '<meta name="citation_fulltext_world_readable" content=""/>';
            foreach ($m['authors'] as $i => $a) if ($a['orcidId']) $h['Orcid' . $i] = self::m('citation_author_orcid', 'https://orcid.org/' . $a['orcidId']);
            // الكلمات المفتاحية باللغة الثانية (الإضافة الأساسية تضع لغة البحث فقط؛ والعنوان والملخص الآخران تضعهما إضافة Dublin Core)
            foreach ((array) ($m['keywords'][$other] ?? []) as $i => $k) $h['AltKw' . $i] = self::m('citation_keywords', $k, $other);
        }
        if ($o['jsonld']) $h['JsonLd'] = '<script type="application/ld+json">' . json_encode(self::jsonld($m, $j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        return $h;
    }

    public static function jsonld(array $m, array $j): array
    {
        $lang = substr($m['locale'], 0, 2); $other = $lang === 'ar' ? 'en' : 'ar';
        $per = ['@type' => 'Periodical', 'name' => Meta::pick($j['names'], [$lang, $other])];
        $issn = array_values(array_filter([$j['eissn'], $j['issn']])); if ($issn) $per['issn'] = $issn;
        if ($j['publisher'] !== '') $per['publisher'] = ['@type' => 'Organization', 'name' => $j['publisher']];
        $part = $per;
        if ($m['issue']) {
            if ($m['issue']['volume'] !== '') $part = ['@type' => 'PublicationVolume', 'volumeNumber' => $m['issue']['volume'], 'isPartOf' => $part];
            if ($m['issue']['number'] !== '') $part = ['@type' => 'PublicationIssue', 'issueNumber' => $m['issue']['number'], 'datePublished' => $m['issue']['datePublished'] ?: null, 'isPartOf' => $part];
        }
        $d = ['@context' => 'https://schema.org', '@type' => 'ScholarlyArticle', 'headline' => mb_substr(Meta::pick($m['titles'], [$lang, $other]), 0, 250), 'name' => Meta::pick($m['titles'], [$lang, $other]),
            'inLanguage' => $lang, 'url' => $m['url'], 'isPartOf' => $part, 'isAccessibleForFree' => true];
        if (!empty($m['titles'][$other])) $d['alternativeHeadline'] = $m['titles'][$other];
        if ($m['datePublished']) $d['datePublished'] = substr($m['datePublished'], 0, 10);
        if ($abs = Meta::pick($m['abstracts'], [$lang, $other])) $d['abstract'] = $abs;
        if ($kw = ($m['keywords'][$lang] ?? [])) $d['keywords'] = implode(', ', $kw);
        if ($m['doi']) { $d['identifier'] = ['@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => $m['doi']]; $d['sameAs'] = 'https://doi.org/' . $m['doi']; }
        if ($m['firstPage'] !== '') { $d['pageStart'] = $m['firstPage']; if ($m['lastPage'] !== '') $d['pageEnd'] = $m['lastPage']; }
        if ($m['license']) $d['license'] = $m['license'];
        $d['author'] = array_map(function ($a) use ($lang, $other) {
            $p = ['@type' => 'Person', 'name' => Meta::pick($a['names'], [$lang, $other])];
            if (!empty($a['given'][$lang])) $p['givenName'] = $a['given'][$lang];
            if (!empty($a['family'][$lang])) $p['familyName'] = $a['family'][$lang];
            if ($a['orcidId']) $p['sameAs'] = 'https://orcid.org/' . $a['orcidId'];
            if ($aff = Meta::pick($a['affiliations'], [$lang, $other])) $p['affiliation'] = ['@type' => 'Organization', 'name' => $aff] + ($a['ror'] ? ['sameAs' => $a['ror']] : []);
            return $p;
        }, $m['authors']);
        foreach ($m['galleys'] as $g) if ($g['pdf'] && $g['download']) { $d['encoding'] = ['@type' => 'MediaObject', 'encodingFormat' => 'application/pdf', 'contentUrl' => $g['download']]; break; }
        return array_filter($d, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
