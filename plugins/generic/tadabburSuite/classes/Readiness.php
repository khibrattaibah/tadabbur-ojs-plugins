<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/Readiness.php
 *
 * فحص جاهزية البيئة للقراءة فقط. كل فحص يعيد إحدى أربع حالات:
 *   ok      متحقق: قرأنا الواقع وطابق المطلوب.
 *   fail    فشل: قرأنا الواقع وخالف المطلوب.
 *   unknown غير قابل للتحقق من داخل التطبيق: لا نملك دليلًا (مثل وصول البريد فعليًا أو سلامة النسخ الاحتياطي).
 *   info    معلومة للسياق، لا حكم عليها.
 * وجود إعداد لا يُعدّ دليلًا على أن الخدمة تعمل.
 */
namespace APP\plugins\generic\tadabburSuite\classes;

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\core\Core;

final class Readiness
{
    /** إصدارات OJS التي اختُبرت عليها الحزمة فعليًا */
    public const TESTED_OJS = ['3.5.0.6'];
    public const MIN_PHP = '8.2.0';
    public const EXTENSIONS = ['zip', 'dom', 'mbstring', 'intl', 'gd', 'curl', 'xml', 'json'];
    /** عمر أقدم مهمة معلّقة (بالدقائق) نعدّه دليلًا على توقف العامل */
    public const STUCK_JOB_MINUTES = 15;

    /** @return array<int,array{group:string,id:string,status:string,label:string,detail:string}> */
    public static function run(int $cid): array
    {
        $c = [];
        $add = function (string $group, string $id, string $status, string $label, string $detail = '') use (&$c) {
            $c[] = ['group' => $group, 'id' => $id, 'status' => $status, 'label' => $label, 'detail' => $detail];
        };

        // ---------------- المنصة
        $ojs = '';
        try { $ojs = (string) Application::get()->getCurrentVersion()->getVersionString(false); } catch (\Throwable $e) {}
        $add('platform', 'ojs', in_array($ojs, self::TESTED_OJS, true) ? 'ok' : 'unknown', 'OJS ' . $ojs,
            in_array($ojs, self::TESTED_OJS, true) ? 'الإصدار مُختبَر' : 'لم تُختبر الحزمة على هذا الإصدار (المُختبَر: ' . implode('، ', self::TESTED_OJS) . ')');
        $add('platform', 'php', version_compare(PHP_VERSION, self::MIN_PHP, '>=') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION, 'الحد الأدنى ' . self::MIN_PHP);
        $missing = array_values(array_filter(self::EXTENSIONS, fn ($e) => !extension_loaded($e)));
        $add('platform', 'ext', $missing ? 'fail' : 'ok', 'امتدادات PHP', $missing ? 'ناقصة: ' . implode('، ', $missing) : implode('، ', self::EXTENSIONS));
        $drv = (string) Config::getVar('database', 'driver');
        $add('platform', 'db', 'unknown', 'قاعدة البيانات: ' . $drv, 'اختُبرت الحزمة على PostgreSQL فقط؛ لم تُختبر على MySQL/MariaDB');
        $add('platform', 'mpdf', class_exists(\Mpdf\Mpdf::class) ? 'ok' : 'unknown', 'مولّد PDF (mPDF)', class_exists(\Mpdf\Mpdf::class) ? 'محمّل' : 'غير محمّل؛ مطلوب عند إطلاق المستندات (التسليم 2)');

        // ---------------- هوية المنتج
        $verFile = dirname(__DIR__) . '/version.xml';
        $fileVer = '';
        if (is_file($verFile) && preg_match('~<release>([^<]+)</release>~', (string) file_get_contents($verFile), $m)) $fileVer = trim($m[1]);
        $dbVer = '';
        try {
            $r = DB::table('versions')->where('product_type', 'plugins.generic')->where('product', 'tadabburSuite')->where('current', 1)->first();
            if ($r) $dbVer = $r->major . '.' . $r->minor . '.' . $r->revision . '.' . $r->build;
        } catch (\Throwable $e) {}
        $add('identity', 'product', $fileVer !== '' && $fileVer === $dbVer ? 'ok' : 'fail', 'منتج tadabburSuite',
            'ملف version.xml: ' . ($fileVer ?: '—') . ' · سجل OJS: ' . ($dbVer ?: 'غير مسجل') . ($fileVer !== $dbVer ? ' — يجب أن يتطابقا؛ لا يُعدَّل السجل يدويًا' : ''));
        $leg = Modules::legacyScan();
        $add('identity', 'legacyDirs', $leg['dirs'] ? 'fail' : 'ok', 'نسخ منفردة قديمة في plugins/generic', $leg['dirs'] ? implode('، ', $leg['dirs']) . ' — تُحذف مجلداتها بقرار صريح بعد الجرد؛ لا تُحذف تلقائيًا' : 'لا يوجد');
        $vs = array_map(fn ($v) => $v['type'] . '/' . $v['product'] . ' ' . $v['version'], $leg['versions']);
        $add('identity', 'legacyVersions', $vs ? 'fail' : 'ok', 'سجلات إصدارات قديمة لوحدات كمنتجات مستقلة', $vs ? implode('، ', $vs) . ' — تُعالج بخطة ترحيل موثقة مع OJT، لا بحذف أو خفض يدوي' : 'لا يوجد');
        $st = array_map(fn ($k, $n) => $k . ' (' . $n . ')', array_keys($leg['settings']), $leg['settings']);
        $add('identity', 'legacySettings', 'info', 'إعدادات قديمة محفوظة في plugin_settings', $st ? implode('، ', $st) . ' — محفوظة ولم تُمسّ' : 'لا يوجد');

        // ---------------- الترحيل
        $status = SuiteMigration::status();
        if (!$status) {
            $add('migration', 'ledger', 'fail', 'سجل الترحيل', 'لم يُشغَّل الترحيل بعد (جدول tdb_migrations غير موجود أو فارغ)');
        }
        foreach (Modules::released() as $key) {
            $row = $status[$key] ?? null;
            $add('migration', 'm_' . $key, $row && $row->state === 'ok' ? 'ok' : 'fail', 'ترحيل ' . $key,
                !$row ? 'لم يُنفَّذ' : ($row->state === 'ok' ? 'آخر نجاح: ' . $row->finished_at . ' UTC' : 'فشل (محاولات: ' . $row->attempts . '): ' . mb_substr((string) $row->error, 0, 300)));
        }

        // ---------------- الملفات
        $files = rtrim((string) Config::getVar('files', 'files_dir'), '/');
        $add('storage', 'files', is_dir($files) && is_writable($files) ? 'ok' : 'fail', 'مجلد الملفات', $files . ' — ' . (is_dir($files) ? (is_writable($files) ? 'قابل للكتابة' : 'غير قابل للكتابة') : 'غير موجود'));
        $add('storage', 'filesOutside', 'unknown', 'مجلد الملفات خارج الجذر العام', 'يتطلب فحص إعداد الخادم؛ لا نستطيع التحقق من التطبيق ما إذا كان الوصول المباشر عبر الويب ممنوعًا');
        $cache = rtrim((string) Core::getBaseDir(), '/') . '/cache';
        $add('storage', 'cache', is_dir($cache) && is_writable($cache) ? 'ok' : 'fail', 'مجلد التخزين المؤقت', $cache);

        // ---------------- البريد والمهام
        $mail = (string) Config::getVar('email', 'default');
        $add('ops', 'mail', $mail === 'log' ? 'fail' : 'unknown', 'ناقل البريد: ' . ($mail ?: '—'),
            $mail === 'log' ? 'النظام يكتب الرسائل في السجل فقط ولا يرسلها' : 'الإعداد موجود لكن وصول الرسالة إلى صندوق مستلم لا يمكن إثباته من داخل التطبيق؛ يلزم اختبار إرسال فعلي ومراجعة SPF/DKIM مع الاستضافة');
        $jobs = 0; $oldest = null; $failed = 0; $jobsErr = '';
        try {
            $jobs = (int) DB::table('jobs')->count();
            $min = DB::table('jobs')->min('created_at');
            $oldest = $min ? (int) $min : null;
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable $e) { $jobsErr = $e->getMessage(); }
        $runner = strtolower((string) Config::getVar('queues', 'job_runner')) === 'on';
        if ($jobsErr !== '') {
            $add('ops', 'queue', 'fail', 'طابور المهام', 'تعذرت قراءة جدولي jobs/failed_jobs');
        } else {
            $ageMin = $oldest ? (int) floor((time() - $oldest) / 60) : 0;
            $stuck = $jobs > 0 && $ageMin >= self::STUCK_JOB_MINUTES;
            $add('ops', 'queue', ($failed > 0 || $stuck) ? 'fail' : 'unknown', 'طابور المهام (database)',
                'معلّقة: ' . $jobs . ($jobs ? ' · أقدمها منذ ' . $ageMin . ' د' : '') . ' · فاشلة: ' . $failed . ' · job_runner في config: ' . ($runner ? 'On' : 'Off')
                . ($stuck ? ' — مهام عالقة؛ العامل لا يعمل فيما يبدو' : ' — لا دليل على أن عاملًا ينفّذ المهام فعلًا؛ يلزم تأكيد طريقة تشغيله مع OJT وتنفيذ مهمة اختبار'));
        }
        $add('ops', 'scheduler', 'unknown', 'المهام المجدولة (cron)', 'لا يمكن التحقق من داخل التطبيق أن cron الخادم يستدعي المجدول');
        $add('ops', 'backup', 'unknown', 'النسخ الاحتياطي والاستعادة', 'خارج نطاق التطبيق؛ لا يُعدّ وجود ملف نسخة دليلًا على نجاح استعادته');

        // ---------------- السياق
        $n = 0; try { $n = (int) DB::table('journals')->count(); } catch (\Throwable $e) {}
        $add('context', 'journals', 'info', 'عدد المجلات في المنصة: ' . $n, 'هوية كل مجلة (التوقيع والختم والترويسة) معزولة في مجلد مستقل');
        $enabled = [];
        try { $enabled = array_filter(explode(',', (string) DB::table('plugin_settings')->where('plugin_name', 'tadabbursuiteplugin')->where('context_id', $cid)->where('setting_name', 'enabledModules')->value('setting_value'))); } catch (\Throwable $e) {}
        $add('context', 'enabled', 'info', 'وحدات مفعّلة لهذه المجلة: ' . count($enabled), $enabled ? implode('، ', $enabled) : 'لا شيء — لا تُفعَّل وحدة تلقائيًا');
        return $c;
    }

    /** @param array<int,array{status:string}> $checks @return array<string,int> */
    public static function tally(array $checks): array
    {
        $t = ['ok' => 0, 'fail' => 0, 'unknown' => 0, 'info' => 0];
        foreach ($checks as $c) $t[$c['status']] = ($t[$c['status']] ?? 0) + 1;
        return $t;
    }
}
