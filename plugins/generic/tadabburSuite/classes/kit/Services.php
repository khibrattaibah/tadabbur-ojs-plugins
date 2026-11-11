<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/kit/Services.php
 * مفاتيح الخدمات الخارجية المشتركة بين الوحدات (OpenAlex) وطلبات HTTP المهذبة.
 * المفتاح يُحفظ مرة واحدة في لوحة الحزمة، ويمكن لأي وحدة أن تتجاوزه بإعدادها الخاص.
 */
namespace APP\plugins\generic\tadabburSuite\classes\kit;

use APP\core\Application;
use PKP\plugins\PluginRegistry;

class Services
{
    public static function suiteSetting(int $cid, string $k): string
    {
        $p = PluginRegistry::getPlugin('generic', 'tadabbursuiteplugin');
        return $p ? (string) ($p->getSetting($cid, $k) ?? '') : '';
    }

    public static function openalexKey(int $cid, string $own = ''): string { return $own !== '' ? $own : self::suiteSetting($cid, 'openalexKey'); }

    public static function mailto($context): string { return (string) ($context->getData('contactEmail') ?? ''); }

    /** طلب OpenAlex بالمفتاح، وعند رفضه (مفتاح خاطئ أو منتهٍ) يُعاد الطلب دون مفتاح */
    public static function openalex(string $path, string $key, string $mailto = '', int $timeout = 12): array
    {
        $base = 'https://api.openalex.org/' . $path . (str_contains($path, '?') ? '&' : '?') . ($mailto !== '' ? 'mailto=' . rawurlencode($mailto) : '');
        if ($key !== '') {
            $r = self::getJson($base . '&api_key=' . rawurlencode($key), $timeout, $mailto);
            if (!in_array($r[0], [401, 403], true)) return $r;
        }
        return self::getJson($base, $timeout, $mailto);
    }

    /** إرسال بريد HTML عبر بريد OJS المضبوط؛ يعيد false عند الفشل دون إيقاف الصفحة */
    public static function mail($context, string $to, string $name, string $subject, string $html): bool
    {
        if ($to === '') return false;
        try {
            $m = new \PKP\mail\Mailable([$context]);
            $m->from((string) $context->getData('contactEmail'), (string) $context->getData('contactName'))->to($to, $name)->subject(mb_substr($subject, 0, 250))
                ->body('<div dir="auto" style="font-family:Tahoma,Arial,sans-serif;line-height:1.7">' . $html . '</div>');
            \Illuminate\Support\Facades\Mail::send($m);
            return true;
        } catch (\Throwable $e) {
            error_log('[tadabburSuite] mail: ' . $e->getMessage());
            return false;
        }
    }

    /** GET يعيد [status, json|null] دون رمي استثناءات */
    public static function getJson(string $url, int $timeout = 12, string $mailto = ''): array
    {
        try {
            $res = Application::get()->getHttpClient()->request('GET', $url, ['timeout' => $timeout, 'http_errors' => false,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'OJS-TadabburSuite/1.2 (' . ($mailto ?: 'journal') . ')']]);
            return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
        } catch (\Throwable $e) {
            return [0, null];
        }
    }
}
