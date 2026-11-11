<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerHonor/ReviewerHonorPlugin.php
 * «تكريم المحكّمين» — ترتيب سنوي للمحكّمين بمؤشرات الإنجاز والالتزام والجودة، واختيار «محكّم العام»،
 * وصفحة شكر عامة بأسماء من يوافقون على الظهور، وخطاب شكر رسمي على ورق المجلة يستخرجه المحكّم بنفسه.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerHonor;

use APP\plugins\generic\tadabburSuite\m\reviewerHonor\classes\Honor;
use APP\plugins\generic\tadabburSuite\m\reviewerHonor\pages\HonorHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class ReviewerHonorPlugin extends ModulePlugin
{
    public const PAGE = 'reviewerHonor';
    public const ICON = 'Badge';

    public function handler(): object { return new HonorHandler($this); }
    public function getInstallMigration() { return new Honor(); }

    protected function menuFor($request): ?array
    {
        if ($this->isEditor($request)) return ['menu', 'index'];
        return Honor::years((int) $request->getContext()->getId(), (int) $request->getUser()->getId()) ? ['myMenu', 'mine'] : null;
    }
}
