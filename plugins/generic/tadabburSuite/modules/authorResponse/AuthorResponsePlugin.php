<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/authorResponse/AuthorResponsePlugin.php
 * «رد الباحث على المحكّمين» — مصفوفة ردود منظمة: لكل ملاحظة من ملاحظات المحكّمين ردّ الباحث وموضع التعديل ونوع الاستجابة،
 * يراها المحرر والمحكّم في الجولة التالية، وتُطبع وثيقة رسمية.
 */
namespace APP\plugins\generic\tadabburSuite\m\authorResponse;

use APP\plugins\generic\tadabburSuite\m\authorResponse\classes\Store;
use APP\plugins\generic\tadabburSuite\m\authorResponse\pages\ResponseHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class AuthorResponsePlugin extends ModulePlugin
{
    public const PAGE = 'authorResponse';
    public const ICON = 'Comment';

    public function handler(): object { return new ResponseHandler($this); }
    public function getInstallMigration() { return new Store(); }

    protected function menuFor($request): ?array
    {
        if ($this->isEditor($request)) return ['menu', 'index'];
        return (new Store())->eligibleFor((int) $request->getContext()->getId(), (int) $request->getUser()->getId()) ? ['myMenu', 'mine'] : null;
    }
}
