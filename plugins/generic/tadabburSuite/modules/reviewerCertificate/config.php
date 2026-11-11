<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * إعدادات إضافة «شهادات المحكّمين وحزمة التحكيم» — يُعدّلها مدير الموقع دون المساس بالكود.
 * القيم الفارغة تُستكمل تلقائيًا من بيانات المجلة في OJS (الاسم، رابط المنصة).
 */
return [
    // ---------- الشهادات ----------
    'journal_prefix' => 'TADABBUR',   // بادئة رقم الشهادة؛ null = مسار المجلة في OJS
    'honorifics' => [
        'none' => '',
        'prof_m' => 'الأستاذ الدكتور', 'prof_f' => 'الأستاذة الدكتورة',
        'assoc_m' => 'الأستاذ المشارك الدكتور', 'assoc_f' => 'الأستاذة المشاركة الدكتورة',
        'dr_m' => 'الدكتور', 'dr_f' => 'الدكتورة',
        'mr' => 'الأستاذ', 'ms' => 'الأستاذة', 'sh' => 'الشيخ',
    ],
    'feminine_honorifics' => ['prof_f', 'assoc_f', 'dr_f', 'ms'],   // لضبط «سلمها/سلمه»
    'default_honorific_male'   => 'dr_m',
    'default_honorific_female' => 'dr_f',
    'date_format' => 'd-m-Y',
    'certificate_date' => 'completed',   // completed = تاريخ اكتمال التحكيم، issued = تاريخ الإصدار
    'email_attach_pdf' => true,          // إرفاق PDF بالبريد إن توفرت مكتبة mPDF (مضمّنة في OJS 3.5)
    'reviewer_can_see_kit' => true,      // يرى المحكّم نماذج التحكيم في صفحة «شهاداتي»

    // ---------- ملفات التحكيم ----------
    'journal_name'   => 'مجلة تدبر',     // فارغ = اسم المجلة في OJS
    'publisher_name' => 'مكتب خبرات طيبة للبحوث والدراسات',
    'publisher_city' => 'المدينة المنورة',
    'platform_url'   => '',              // فارغ = رابط المجلة في OJS
    'policy_url'     => 'https://tadabburmag.sa/tadabburmag/ar/review',
    'issn_online'    => '1658-9718',
    'issn_print'     => '1658-7642',
    'form_version'   => '2.0',
    'codes' => ['inv' => 'TDB-LTR-INV-2.0', 'rev' => 'TDB-LTR-REV-2.0', 'res' => 'TDB-FRM-RES-2.0', 'eval' => 'TDB-FRM-EVAL-2.0', 'rsp' => 'TDB-FRM-RSP-2.0'],
    'default_review_days' => 10,
    'invitation_attachments' => 'نسخة من البحث، نماذج وخطاب التحكيم',
    'revision_attachments'   => 'نسخة من تقارير المحكّمين',
    'hijri_calendar' => 'islamic',       // islamic | islamic-umalqura | islamic-civil (يُراجَع التاريخ قبل الإصدار)
    'review_scale' => [
        ['مطابقة المحتوى لعنوان البحث.', 5],
        ['الالتزام بمخطط البحث.', 10],
        ['ظهور شخصية الباحث وقدرته على التحليل والاستنباط.', 10],
        ['العرض وتنظيمه وترابط أجزائه.', 10],
        ['سلامة البحث من الأخطاء اللغوية والإملائية.', 10],
        ['الإضافة العلمية للبحث.', 15],
        ['ارتباط مباحث الموضوع بمجالات التدبر.', 10],
        ['كفاية مراجع البحث وتنوعها، والرجوع لأبحاث حديثة من مجلات أو مصادر علمية مرموقة آخر خمس سنوات، مع فهرستها برقم DOI.', 10],
        ['دقة وحسن التوثيق العلمي.', 10],
        ['وضوح نتائج البحث وأهميتها.', 10],
    ],
    'recommendations' => [
        ['قبول البحث بصورته الحالية', 'البحث جاهز للنشر من غير تعديل.', 'Accept'],
        ['البحث مناسب، ولكن بحاجة إلى تعديلات', 'تعديلات طفيفة يمكن للمحرر مراجعتها وقبولها.', 'Revisions Required'],
        ['إعادة تحكيم وتقييم البحث', 'تعديلات جوهرية، ويُعاد إرساله بعد إجراء التعديلات.', 'Resubmit for Review'],
        ['تقديم البحث لمكان آخر', 'لا يتناسب مع توجهات المجلة ومجال تخصصها.', 'Resubmit Elsewhere'],
        ['رفض البحث', 'ضعيف جدًا ولا يمكن قبوله للنشر بصورته الحالية.', 'Decline'],
    ],
];
