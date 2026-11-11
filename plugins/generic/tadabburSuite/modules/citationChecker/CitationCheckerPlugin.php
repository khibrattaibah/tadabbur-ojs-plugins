<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/citationChecker/CitationCheckerPlugin.php
 * «مدقّق التوثيق» — يفحص قائمة مراجع البحث: صحة DOI ووجوده في Crossref ومطابقته للمرجع، والمراجع المسحوبة،
 * واقتراح DOI للمراجع اللاتينية التي تفتقده، ونواقص المراجع العربية (المؤلف، العنوان، الناشر، السنة)، والتكرار والترتيب الهجائي.
 */
namespace APP\plugins\generic\tadabburSuite\m\citationChecker;

use APP\plugins\generic\tadabburSuite\m\citationChecker\classes\Checker;
use APP\plugins\generic\tadabburSuite\m\citationChecker\pages\CheckHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class CitationCheckerPlugin extends ModulePlugin
{
    public const PAGE = 'citationChecker';
    public const ICON = 'List';

    public function handler(): object { return new CheckHandler($this); }
    public function getInstallMigration() { return new Checker(); }
}
