<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/Ui.php
 *
 * واجهة الحزمة الموحّدة (UI kit 2): يلفّ محتوى كل صفحات الوحدات في حاوية .tdb ويحمّل ورقة الأنماط،
 * ويحوّل الأنماط المضمّنة القديمة (صناديق وجداول وأزرار الإصدارات السابقة) إلى أصناف النظام الجديد،
 * فتتوحّد كل الشاشات بلا إعادة كتابة كل وحدة على حدة.
 */
namespace APP\plugins\generic\tadabburSuite\classes;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\TadabburSuitePlugin;

class Ui
{
    /** نمط مضمّن قديم => صنف جديد */
    private const MAP = [
        'tdb-card'          => '~style="border:1px solid #d5dbe1;border-radius:[68]px;padding:[^;"]+;margin:0 0 1[46]px;background:#fff;?~',
        'tdb-table'         => '~style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;?~',
        'tdb-th'            => '~style="background:#(?:f1f3f5|ecf0f3);border:1px solid #d5dbe1;padding:\d+px;text-align:(?:start|right);vertical-align:bottom;?(?:white-space:nowrap;)?~',
        'tdb-td'            => '~style="border:1px solid #d5dbe1;padding:\d+px;vertical-align:top;?~',
        'tdb-btn tdb-btn--primary' => '~style="display:inline-block;margin:2px;padding:[^;"]+;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:1[23]px;cursor:pointer;?(?:text-decoration:none;line-height:1\.5;?)?~',
        'tdb-btn'           => '~style="display:inline-block;margin:2px;padding:[^;"]+;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:1[23]px;cursor:pointer;?(?:text-decoration:none;line-height:1\.5;?)?~',
        'tdb-btn tdb-btn--danger' => '~style="display:inline-block;margin:2px;padding:[^;"]+;border:1px solid #b02a2a;border-radius:4px;background:#fff;color:#b02a2a;font-weight:700;font-size:1[23]px;cursor:pointer;?~',
        'tdb-input'         => '~style="border:1px solid #9aa5b1;border-radius:4px;padding:[^;"]+;font-size:13px;background:#fff;color:#222;min-height:30px;?(?:max-width:100%;|width:100%;box-sizing:border-box;font-family:inherit;)?~',
    ];

    public static function css(): string
    {
        $req = Application::get()->getRequest();
        return rtrim($req->getBaseUrl(), '/') . '/plugins/generic/tadabburSuite/assets/ui/tdb-ui.css?v=' . TadabburSuitePlugin::VERSION;
    }

    /** يحوّل الأنماط القديمة إلى أصناف، ثم يدمج سمات class المكررة في الوسم نفسه */
    public static function upgrade($html): string
    {
        $html = (string) $html;
        foreach (self::MAP as $cls => $re) $html = preg_replace($re, 'class="' . $cls . '" style="', $html);
        $html = str_replace(' style=""', '', $html);
        // دمج class مكررة: <x class="a" ... class="b"> => <x class="a b" ...>
        $html = preg_replace_callback('~<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\bclass="[^"]*"[^>]*\bclass="[^"]*"[^>]*>~', function ($m) {
            preg_match_all('~\bclass="([^"]*)"~', $m[0], $all);
            $tag = preg_replace('~\s\bclass="[^"]*"~', '', $m[0]);
            return preg_replace('~^<([a-zA-Z][a-zA-Z0-9]*)~', '<$1 class="' . htmlspecialchars(implode(' ', $all[1]), ENT_QUOTES) . '"', $tag, 1);
        }, $html);
        return $html;
    }

    /** المُعدِّل الذي تستعمله قوالب الوحدات: {$pluginHtml|tdbui nofilter} */
    public static function modifier($html): string
    {
        return '<link rel="stylesheet" href="' . htmlspecialchars(self::css(), ENT_QUOTES) . '"><div class="tdb">' . self::upgrade($html) . '</div>';
    }
}
