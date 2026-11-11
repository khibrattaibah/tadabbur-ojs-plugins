<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/jatsExport/JatsExportPlugin.php
 * «الإخراج الآلي وJATS» — يحوّل ملف Word النهائي للبحث مع بياناته في OJS إلى ملف JATS 1.3 كامل
 * (البيانات الوصفية، والمتن بأقسامه وحواشيه وجداوله، والمراجع)، ونسخة HTML للقراءة، ويفحص البنية،
 * ويُرفق ملف JATS بالنشرة في تبويب JATS الرسمي في OJS 3.5.
 */
namespace APP\plugins\generic\tadabburSuite\m\jatsExport;

use APP\plugins\generic\tadabburSuite\m\jatsExport\classes\Store;
use APP\plugins\generic\tadabburSuite\m\jatsExport\pages\JatsHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class JatsExportPlugin extends ModulePlugin
{
    public const PAGE = 'jatsExport';
    public const ICON = 'File';

    public function handler(): object { return new JatsHandler($this); }
    public function getInstallMigration() { return new Store(); }
}
