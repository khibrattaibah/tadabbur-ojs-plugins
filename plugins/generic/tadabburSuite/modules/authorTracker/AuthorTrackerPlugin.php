<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/authorTracker/AuthorTrackerPlugin.php
 * «متتبع البحث للمؤلف» — خط زمني واضح لكل بحث يراه المؤلف: أين بحثه الآن، ومن ينتظر، وما المتوقع،
 * مقارنةً بمتوسطات المجلة الفعلية، دون كشف هوية المحكّمين.
 */
namespace APP\plugins\generic\tadabburSuite\m\authorTracker;

use APP\plugins\generic\tadabburSuite\m\authorTracker\classes\Timeline;
use APP\plugins\generic\tadabburSuite\m\authorTracker\pages\TrackerHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class AuthorTrackerPlugin extends ModulePlugin
{
    public const PAGE = 'authorTracker';
    public const ICON = 'Clock';

    public function handler(): object { return new TrackerHandler($this); }

    protected function menuFor($request): ?array
    {
        if ($this->isEditor($request)) return ['menu', 'index'];
        return Timeline::authorSubs((int) $request->getContext()->getId(), (int) $request->getUser()->getId()) ? ['myMenu', 'mine'] : null;
    }
}
