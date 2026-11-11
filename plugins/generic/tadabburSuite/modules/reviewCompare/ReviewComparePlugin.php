<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewCompare/ReviewComparePlugin.php
 * «مقارنة تقارير المحكّمين» — تقارير الجولة متجاورة: التوصيات، وإجابات نموذج التحكيم سؤالًا بسؤال، والملاحظات للمؤلف والمحرر،
 * ومدة كل تقرير، مع مؤشر توافق المحكّمين واقتراح قرار ومذكرة قرار قابلة للطباعة.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewCompare;

use APP\plugins\generic\tadabburSuite\m\reviewCompare\pages\CompareHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class ReviewComparePlugin extends ModulePlugin
{
    public const PAGE = 'reviewCompare';
    public const ICON = 'Compare';

    public function handler(): object { return new CompareHandler($this); }
}
