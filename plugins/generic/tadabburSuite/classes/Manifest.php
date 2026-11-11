<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/Manifest.php
 *
 * سجل الوحدات: مرحلة إطلاق كل وحدة وتبعياتها ووضعها (قراءة فقط / كتابة).
 * الوحدة التي مرحلتها أكبر من STAGE_CURRENT موجودة في الحزمة لكنها غير مُحمَّلة ولا تُنشأ جداولها
 * ولا يمكن تفعيلها من اللوحة. رفع المرحلة قرار إصدار صريح بعد اجتياز اختبارات القبول، لا تحديث صامت.
 *
 * التسليم 1: النواة + لوحة قراءة فقط (editorialCommand) + فحص الجاهزية.
 * التسليم 2: المستندات والشهادات والاستبانات وفحص جودة البيانات الوصفية.
 * المرحلة 3: بقية الوحدات، لا تُحمَّل قبل مراجعتها وإصلاح ملاحظاتها (انظر docs/MODULE_MAPPING.md).
 */
namespace APP\plugins\generic\tadabburSuite\classes;

final class Manifest
{
    /** أعلى مرحلة مُصرَّح بتحميلها في هذا الإصدار */
    public const STAGE_CURRENT = 1;

    /**
     * مفتاح الوحدة => [المرحلة، التبعيات، الوضع]
     * الوضع read: ممنوع على الوحدة إرسال بريد أو تعديل بيانات التحرير في هذه المرحلة.
     */
    public const MODULES = [
        // التسليم 1
        'editorialCommand' => [1, [], 'read'],
        // التسليم 2
        'documentCenter' => [2, ['reviewerCertificate', 'officialLetters'], 'write'],
        'reviewerCertificate' => [2, [], 'write'],
        'officialLetters' => [2, [], 'write'],
        'surveys' => [2, [], 'write'],
        'metadataQuality' => [2, [], 'read'],
        // المرحلة 3 (غير مُصرَّح بتحميلها)
        'abstractAssistant' => [3, [], 'write'],
        'arabicIndexes' => [3, [], 'write'],
        'articleImpact' => [3, [], 'write'],
        'authorResponse' => [3, [], 'write'],
        'authorTracker' => [3, [], 'read'],
        'autoAnnounce' => [3, [], 'write'],
        'boardPortal' => [3, [], 'write'],
        'boardVote' => [3, [], 'write'],
        'citationChecker' => [3, [], 'write'],
        'declarations' => [3, [], 'write'],
        'depositHub' => [3, [], 'read'],
        'deskAssistant' => [3, [], 'write'],
        'earlyView' => [3, [], 'write'],
        'editorialTransparency' => [3, [], 'read'],
        'indexingReadiness' => [3, [], 'read'],
        'integrityLog' => [3, [], 'write'],
        'issueBuilder' => [3, [], 'write'],
        'jatsExport' => [3, [], 'write'],
        'preReviewCheck' => [3, [], 'write'],
        'proofApproval' => [3, [], 'write'],
        'quranVerifier' => [3, [], 'read'],
        'referenceRomanizer' => [3, [], 'write'],
        'reviewCompare' => [3, [], 'read'],
        'reviewerHonor' => [3, [], 'write'],
        'reviewerHub' => [3, [], 'write'],
        'scholarBoost' => [3, [], 'write'],
        'smartTemplate' => [3, [], 'write'],
        'tasksBoard' => [3, [], 'write'],
    ];

    public static function stage(string $key): int { return self::MODULES[$key][0] ?? 99; }
    /** @return string[] */
    public static function requires(string $key): array { return self::MODULES[$key][1] ?? []; }
    public static function readOnly(string $key): bool { return (self::MODULES[$key][2] ?? 'write') === 'read'; }
    public static function released(string $key): bool { return self::stage($key) <= self::STAGE_CURRENT; }

    /** @return string[] مفاتيح الوحدات المُصرَّح بتحميلها في هذا الإصدار */
    public static function releasedKeys(): array
    {
        return array_keys(array_filter(self::MODULES, fn ($m) => $m[0] <= self::STAGE_CURRENT));
    }
}
