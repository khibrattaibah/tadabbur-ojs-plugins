<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/issueBuilder/IssueBuilderPlugin.php
 * «بنّاء العدد» — تجهيز العدد القادم: إسناد البحوث المقبولة إليه، وترتيبها، والترقيم الآلي للصفحات،
 * وفحص جاهزية كل بحث للنشر، وفهرس محتويات قابل للطباعة.
 */
namespace APP\plugins\generic\tadabburSuite\m\issueBuilder;

use APP\plugins\generic\tadabburSuite\m\issueBuilder\classes\Store;
use APP\plugins\generic\tadabburSuite\m\issueBuilder\pages\BuilderHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class IssueBuilderPlugin extends ModulePlugin
{
    public const PAGE = 'issueBuilder';
    public const ICON = 'Issues';

    public function handler(): object { return new BuilderHandler($this); }
    public function getInstallMigration() { return new Store(); }
}
