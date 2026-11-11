<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/themes/tadabbur/index.php
 * مدخل تحميل قالب تدبر (يتطلب OJS 3.5).
 */
require_once __DIR__ . '/TadabburThemePlugin.php';
require_once __DIR__ . '/classes/BackendStyleBuilder.php';
return new \APP\plugins\themes\tadabbur\TadabburThemePlugin();
