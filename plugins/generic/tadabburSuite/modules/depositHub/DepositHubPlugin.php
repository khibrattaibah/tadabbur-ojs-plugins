<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/depositHub/DepositHubPlugin.php
 * «مركز الإيداع والربط» — حالة إيداع DOI لكل بحث منشور، والتحقق الحي من Crossref وتوجيه DOI إلى صفحة البحث،
 * ووجود البحث في OpenAlex، وتغطية Crossref للمجلة (ملخصات، مراجع، ORCID، انتماءات)، وحضورها في DOAJ.
 */
namespace APP\plugins\generic\tadabburSuite\m\depositHub;

use APP\plugins\generic\tadabburSuite\m\depositHub\classes\Checker;
use APP\plugins\generic\tadabburSuite\m\depositHub\pages\HubHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class DepositHubPlugin extends ModulePlugin
{
    public const PAGE = 'depositHub';
    public const ICON = 'Globe';

    public function handler(): object { return new HubHandler($this); }
    public function getInstallMigration() { return new Checker(); }
}
