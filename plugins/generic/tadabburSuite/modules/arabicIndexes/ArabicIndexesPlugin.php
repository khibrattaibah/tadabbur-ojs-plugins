<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/arabicIndexes/ArabicIndexesPlugin.php
 * «حزمة الفهارس العربية» — تصدير بيانات كل عدد بقوالب قواعد البيانات العربية (دار المنظومة، معرفة، المنهل، معامل التأثير العربي...)
 * بصيغة Excel وCSV، وحزمة ملفات PDF للعدد، وسجل متابعة لما أُرسل إلى كل قاعدة وما فُهرس.
 */
namespace APP\plugins\generic\tadabburSuite\m\arabicIndexes;

use APP\plugins\generic\tadabburSuite\m\arabicIndexes\classes\Store;
use APP\plugins\generic\tadabburSuite\m\arabicIndexes\pages\IndexesHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class ArabicIndexesPlugin extends ModulePlugin
{
    public const PAGE = 'arabicIndexes';
    public const ICON = 'Catalog';

    public function handler(): object { return new IndexesHandler($this); }
    public function getInstallMigration() { return new Store(); }
}
