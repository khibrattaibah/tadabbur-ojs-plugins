<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/kit/Access.php
 *
 * نطاق الاطلاع على البحوث: السياق + الإسناد. وجود دور محرر/مساعد في المجلة لا يكفي للاطلاع على كل بحوثها.
 *  - مدير المجلة ومدير الموقع: كل بحوث المجلة.
 *  - محرر القسم والمساعد: البحوث المسندة إليهم (stage_assignments) فقط.
 *  - غير ذلك: لا شيء.
 */
namespace APP\plugins\generic\tadabburSuite\classes\kit;

use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\security\Role;

final class Access
{
    /**
     * @return int[]|null null = كل بحوث المجلة؛ مصفوفة = المعرفات المسموح بها فقط (فارغة = لا شيء)
     */
    public static function visibleSubmissionIds($request): ?array
    {
        $u = $request->getUser(); $c = $request->getContext();
        if (!$u || !$c) return [];
        $cid = (int) $c->getId();
        if ($u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID) || $u->hasRole([Role::ROLE_ID_MANAGER], $cid)) return null;
        if (!$u->hasRole([Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], $cid)) return [];
        return array_map('intval', DB::table('stage_assignments as sa')
            ->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
            ->where('s.context_id', $cid)->where('sa.user_id', (int) $u->getId())
            ->distinct()->pluck('sa.submission_id')->all());
    }

    /** هل يستطيع المستخدم الاطلاع على هذا البحث (سياق + إسناد)؟ */
    public static function canSeeSubmission($request, int $submissionId): bool
    {
        $c = $request->getContext();
        if (!$c) return false;
        $ctx = DB::table('submissions')->where('submission_id', $submissionId)->value('context_id');
        if ($ctx === null || (int) $ctx !== (int) $c->getId()) return false;
        $ids = self::visibleSubmissionIds($request);
        return $ids === null || in_array($submissionId, $ids, true);
    }

    /**
     * يرشّح صفوفًا فيها مفتاح معرف البحث.
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    public static function filterRows($request, array $rows, string $idKey = 'id'): array
    {
        $ids = self::visibleSubmissionIds($request);
        if ($ids === null) return $rows;
        $set = array_flip($ids);
        return array_values(array_filter($rows, fn ($r) => isset($set[(int) ($r[$idKey] ?? 0)])));
    }
}
