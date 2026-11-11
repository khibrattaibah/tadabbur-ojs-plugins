<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/earlyView/EarlyViewPlugin.php
 * «النشر المبكر» — صفحة عامة للبحوث المقبولة قبل صدور عددها: العنوان والمؤلفون والملخص وتاريخ القبول ونسخة المخطوط المقبول،
 * مع صيغة استشهاد «قيد النشر». يختار المحرر ما يُعرض والملف، ويختفي البحث تلقائيًا عند نشره في عدد.
 */
namespace APP\plugins\generic\tadabburSuite\m\earlyView;

use APP\plugins\generic\tadabburSuite\m\earlyView\classes\Store;
use APP\plugins\generic\tadabburSuite\m\earlyView\pages\EarlyHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class EarlyViewPlugin extends ModulePlugin
{
    public const PAGE = 'earlyView';
    public const ICON = 'Visibility';

    public function handler(): object { return new EarlyHandler($this); }
    public function getInstallMigration() { return new Store(); }
    protected function menuFor($request): ?array { return $this->isEditor($request) ? ['menu', 'manage'] : null; }
}
