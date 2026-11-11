<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/arabicIndexes/classes/Fields.php
 * الحقول المتاحة للتصدير، والقوالب المقترحة لكل قاعدة (قابلة للتعديل من الإعدادات).
 */
namespace APP\plugins\generic\tadabburSuite\m\arabicIndexes\classes;

use APP\plugins\generic\tadabburSuite\classes\kit\Meta;

class Fields
{
    /** مفتاح => [عنوان عربي، عنوان إنجليزي] */
    public const ALL = [
        'journal_ar' => ['اسم المجلة', 'Journal (Arabic)'], 'journal_en' => ['اسم المجلة بالإنجليزية', 'Journal (English)'], 'issn' => ['ردمد ورقي', 'Print ISSN'], 'eissn' => ['ردمد إلكتروني', 'E-ISSN'],
        'publisher' => ['الناشر', 'Publisher'], 'volume' => ['المجلد', 'Volume'], 'issue' => ['العدد', 'Issue'], 'year' => ['السنة', 'Year'], 'hijri' => ['السنة الهجرية', 'Hijri year'],
        'month' => ['الشهر', 'Month'], 'date' => ['تاريخ النشر', 'Publication date'], 'id' => ['رقم البحث', 'Article ID'], 'section' => ['القسم', 'Section'], 'language' => ['لغة البحث', 'Language'],
        'title_ar' => ['العنوان بالعربية', 'Title (Arabic)'], 'title_en' => ['العنوان بالإنجليزية', 'Title (English)'],
        'authors_ar' => ['المؤلفون بالعربية', 'Authors (Arabic)'], 'authors_en' => ['المؤلفون بالإنجليزية', 'Authors (English)'], 'author1_ar' => ['المؤلف الأول', 'First author'],
        'affiliations_ar' => ['جهات الانتماء', 'Affiliations (Arabic)'], 'affiliations_en' => ['جهات الانتماء بالإنجليزية', 'Affiliations (English)'], 'countries' => ['الدولة', 'Country'],
        'emails' => ['البريد الإلكتروني', 'Emails'], 'orcids' => ['ORCID', 'ORCID'],
        'abstract_ar' => ['الملخص بالعربية', 'Abstract (Arabic)'], 'abstract_en' => ['الملخص بالإنجليزية', 'Abstract (English)'],
        'keywords_ar' => ['الكلمات المفتاحية', 'Keywords (Arabic)'], 'keywords_en' => ['الكلمات المفتاحية بالإنجليزية', 'Keywords (English)'],
        'pages' => ['الصفحات', 'Pages'], 'first_page' => ['من صفحة', 'First page'], 'last_page' => ['إلى صفحة', 'Last page'], 'page_count' => ['عدد الصفحات', 'Page count'],
        'doi' => ['DOI', 'DOI'], 'url' => ['رابط البحث', 'Article URL'], 'pdf_url' => ['رابط PDF', 'PDF URL'], 'pdf_file' => ['اسم ملف PDF', 'PDF file name'],
        'refs_count' => ['عدد المراجع', 'References count'], 'received' => ['تاريخ الاستلام', 'Received'], 'accepted' => ['تاريخ القبول', 'Accepted'], 'license' => ['الترخيص', 'Licence'],
    ];

    /** قوالب مقترحة؛ تختلف نماذج القواعد من وقت لآخر فتُعدَّل من الإعدادات */
    public const PRESETS = [
        'mandumah' => ['دار المنظومة', 'journal_ar,issn,eissn,volume,issue,year,hijri,title_ar,title_en,authors_ar,authors_en,affiliations_ar,countries,language,section,pages,page_count,abstract_ar,abstract_en,keywords_ar,keywords_en,doi,url,pdf_file'],
        'emarefa' => ['معرفة (e-Marefa)', 'journal_ar,journal_en,issn,eissn,publisher,volume,issue,year,month,title_ar,title_en,authors_ar,authors_en,affiliations_ar,affiliations_en,countries,emails,language,first_page,last_page,abstract_ar,abstract_en,keywords_ar,keywords_en,doi,url,pdf_file'],
        'almanhal' => ['المنهل', 'journal_ar,eissn,publisher,volume,issue,year,date,title_ar,title_en,authors_ar,authors_en,affiliations_ar,language,section,pages,abstract_ar,abstract_en,keywords_ar,keywords_en,doi,pdf_url'],
        'arcif' => ['معامل التأثير والاستشهادات المرجعية العربي (ARCIF)', 'journal_ar,issn,eissn,volume,issue,year,id,title_ar,authors_ar,affiliations_ar,countries,pages,refs_count,doi,url'],
        'custom' => ['قالب خاص', 'id,title_ar,title_en,authors_ar,pages,doi,url'],
    ];

    public static function row(array $m, array $j, string $pdfName): array
    {
        $names = fn ($l) => implode('؛ ', array_filter(array_map(fn ($a) => $a['names'][$l] ?? '', $m['authors'])));
        $affs = fn ($l) => implode('؛ ', array_unique(array_filter(array_map(fn ($a) => $a['affiliations'][$l] ?? '', $m['authors']))));
        $date = substr($m['datePublished'] ?: ($m['issue']['datePublished'] ?? ''), 0, 10);
        $hijri = '';
        if ($date !== '' && class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::TRADITIONAL, 'y');
            $hijri = (string) $f->format(new \DateTime($date . ' 12:00:00', new \DateTimeZone('UTC')));
            $hijri = strtr($hijri, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
            $hijri = preg_replace('/[^\d]/', '', $hijri);
        }
        $pdf = ''; foreach ($m['galleys'] as $g) if ($g['pdf']) { $pdf = $g['download']; break; }
        $fp = $m['firstPage']; $lp = $m['lastPage'];
        return [
            'journal_ar' => $j['names']['ar'] ?? '', 'journal_en' => $j['names']['en'] ?? '', 'issn' => $j['issn'], 'eissn' => $j['eissn'], 'publisher' => $j['publisher'],
            'volume' => $m['issue']['volume'] ?? '', 'issue' => $m['issue']['number'] ?? '', 'year' => $m['issue']['year'] ?? substr($date, 0, 4), 'hijri' => $hijri,
            'month' => $date !== '' ? (string) (int) substr($date, 5, 2) : '', 'date' => $date, 'id' => (string) $m['id'], 'section' => Meta::pick($m['section']),
            'language' => substr($m['locale'], 0, 2) === 'ar' ? 'العربية' : 'English',
            'title_ar' => $m['titles']['ar'] ?? '', 'title_en' => $m['titles']['en'] ?? '', 'authors_ar' => $names('ar'), 'authors_en' => $names('en'),
            'author1_ar' => $m['authors'] ? Meta::pick($m['authors'][0]['names']) : '', 'affiliations_ar' => $affs('ar'), 'affiliations_en' => $affs('en'),
            'countries' => implode('؛ ', array_unique(array_filter(array_map(fn ($a) => $a['country'], $m['authors'])))),
            'emails' => implode('؛ ', array_filter(array_map(fn ($a) => $a['email'], $m['authors']))), 'orcids' => implode('؛ ', array_filter(array_map(fn ($a) => $a['orcidId'], $m['authors']))),
            'abstract_ar' => $m['abstracts']['ar'] ?? '', 'abstract_en' => $m['abstracts']['en'] ?? '',
            'keywords_ar' => implode('، ', $m['keywords']['ar'] ?? []), 'keywords_en' => implode(', ', $m['keywords']['en'] ?? []),
            'pages' => $m['pages'], 'first_page' => $fp, 'last_page' => $lp, 'page_count' => ctype_digit($fp) && ctype_digit($lp) ? (string) ((int) $lp - (int) $fp + 1) : '',
            'doi' => $m['doi'], 'url' => $m['url'], 'pdf_url' => $pdf, 'pdf_file' => $pdfName, 'refs_count' => (string) count($m['citations']),
            'received' => $m['dateSubmitted'], 'accepted' => $m['dateAccepted'], 'license' => $m['license'],
        ];
    }
}
