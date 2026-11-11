<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/smartTemplate/SmartTemplatePlugin.php
 * «قالب المجلة الذكي» — قالب Word بمواصفات المجلة (الخطوط والأحجام والهوامش وأنماط العناوين والآيات والحواشي والمراجع)
 * وبالعناوين الإلزامية، يُعبّأ ببيانات بحث المؤلف (العنوانان والملخصان والكلمات)، بنسخة كاملة ونسخة مُغفلة للتحكيم.
 * القالب متوافق مع تحويل JATS في الحزمة.
 */
namespace APP\plugins\generic\tadabburSuite\m\smartTemplate;

use APP\plugins\generic\tadabburSuite\m\smartTemplate\pages\TemplateHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;

class SmartTemplatePlugin extends ModulePlugin
{
    public const PAGE = 'smartTemplate';
    public const ICON = 'File';

    public const DEFAULTS = ['fontAr' => 'Traditional Arabic', 'fontEn' => 'Times New Roman', 'fontQuran' => 'KFGQPC HAFS Uthmanic Script', 'size' => 16, 'fnSize' => 12,
        'line' => 1.0, 'margin' => 25, 'wordsMin' => 6000, 'wordsMax' => 12000, 'absMax' => 250, 'kwMax' => 6,
        'headings' => "المقدمة\n## مشكلة البحث\n## أهمية البحث\n## أهداف البحث\n## الدراسات السابقة\n## منهج البحث\n## خطة البحث\nالمبحث الأول: ...\n## المطلب الأول: ...\nالمبحث الثاني: ...\nالخاتمة\n## النتائج\n## التوصيات"];

    public function handler(): object { return new TemplateHandler($this); }

    public function conf(int $cid): array
    {
        $c = json_decode((string) $this->getSetting($cid, 'conf'), true) ?: [];
        return array_merge(self::DEFAULTS, array_intersect_key($c, self::DEFAULTS));
    }

    public function authorSubs(int $cid, int $uid): array
    {
        return DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
            ->where('s.context_id', $cid)->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->whereIn('s.status', [1, 5])->orderByDesc('s.submission_id')->distinct()->pluck('s.submission_id')->map(fn ($x) => (int) $x)->all();
    }

    protected function menuFor($request): ?array
    {
        if ($this->isEditor($request)) return ['menu', 'index'];
        return $this->authorSubs((int) $request->getContext()->getId(), (int) $request->getUser()->getId()) ? ['myMenu', 'mine'] : null;
    }
}
