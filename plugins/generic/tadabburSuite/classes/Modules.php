<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/Modules.php
 *
 * اكتشاف الوحدات داخل modules/ وتحميلها. أي مجلد فيه <Name>Plugin.php يُعدّ وحدة،
 * فإضافة وحدة جديدة = نسخ مجلدها إلى modules/ ورفع الحزمة كتحديث.
 */
namespace APP\plugins\generic\tadabburSuite\classes;

class Modules
{
    /** فضاء أسماء معزول: لا يتعارض مع أي نسخة منفردة من الإضافة نفسها في plugins/generic */
    public const NS = 'APP\\plugins\\generic\\tadabburSuite\\m\\';
    private static bool $loader = false;
    private static ?array $all = null;

    public static function root(): string { return dirname(__DIR__) . '/modules'; }

    /** مُحمِّل الأصناف: APP\plugins\generic\tadabburSuite\m\<module>\... => modules/<module>/... */
    public static function registerAutoloader(): void
    {
        if (self::$loader) return;
        self::$loader = true;
        spl_autoload_register(function (string $class): void {
            if (strncmp($class, self::NS, strlen(self::NS)) !== 0) return;
            $rest = substr($class, strlen(self::NS));
            $pos = strpos($rest, '\\');
            if ($pos === false) return;
            $module = substr($rest, 0, $pos);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $module) || !is_dir(self::root() . '/' . $module)) return;
            $file = self::root() . '/' . $module . '/' . str_replace('\\', '/', substr($rest, $pos + 1)) . '.php';
            if (is_file($file)) require_once $file;
        });
    }

    /** @return array<string,array{key:string,class:string,dir:string,version:string,standalone:bool}> */
    public static function all(): array
    {
        if (self::$all !== null) return self::$all;
        $out = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $key = basename($dir);
            if (!is_file($dir . '/' . ucfirst($key) . 'Plugin.php')) continue;
            $ver = '';
            if (is_file($dir . '/module.xml') && preg_match('~<release>([^<]+)</release>~', (string) file_get_contents($dir . '/module.xml'), $m)) $ver = preg_replace('/\.0$/', '', trim($m[1]));
            $out[$key] = ['key' => $key, 'class' => self::NS . $key . '\\' . ucfirst($key) . 'Plugin', 'dir' => $dir, 'version' => $ver,
                // نسخة مستقلة من الإضافة نفسها مرفوعة مباشرة في plugins/generic (يجب حذفها حتى لا تتعارض)
                'standalone' => is_file(dirname(__DIR__, 2) . '/' . $key . '/' . ucfirst($key) . 'Plugin.php')];
        }
        ksort($out);
        return self::$all = $out;
    }

    /** ينشئ كائن الوحدة (لا يسجّلها في OJS) */
    public static function make(string $key): ?object
    {
        $m = self::all()[$key] ?? null;
        if (!$m || !class_exists($m['class'])) return null;
        return new $m['class']();
    }

    /** اسم مساحة إعدادات الوحدة في plugin_settings (ليس منتجًا في OJS ولا يظهر في صفحة الإضافات) */
    public static function pluginName(string $key): string { return 'tdb' . strtolower($key) . 'plugin'; }

    /** الاسم القديم الذي سُجِّلت به الوحدة سابقًا (وهو اسم النسخة المنفردة أيضًا) — للقراءة والتقرير فقط */
    public static function legacyName(string $key): string { return strtolower($key) . 'plugin'; }

    /** @var array<string,object> الوحدات المُحمَّلة في هذا الطلب */
    private static array $loaded = [];

    /** الوحدة المُحمَّلة (أي المفعّلة لمجلة هذا الطلب) أو null */
    public static function instance(string $key): ?object { return self::$loaded[$key] ?? null; }
    public static function isActive(string $key): bool { return isset(self::$loaded[$key]); }
    public static function markLoaded(string $key, object $plugin): void { self::$loaded[$key] = $plugin; }

    /** مفاتيح الوحدات الموجودة على القرص والمُصرَّح بتحميلها في هذا الإصدار */
    public static function released(): array
    {
        return array_values(array_filter(array_keys(self::all()), fn ($k) => Manifest::released($k)));
    }

    /**
     * التبعيات الناقصة لوحدة بالنسبة لقائمة الوحدات المفعّلة.
     * @param string[] $enabled
     * @return string[]
     */
    public static function missingRequires(string $key, array $enabled): array
    {
        return array_values(array_filter(Manifest::requires($key), fn ($r) => !in_array($r, $enabled, true) || !isset(self::all()[$r])));
    }

    /**
     * يرتّب المفاتيح بحيث تأتي التبعيات قبل ما يعتمد عليها، ويُسقط ما تنقصه تبعية أو لم يُصرَّح بإطلاقه.
     * @param string[] $enabled
     * @return string[]
     */
    public static function loadOrder(array $enabled): array
    {
        $enabled = array_values(array_filter($enabled, fn ($k) => isset(self::all()[$k]) && Manifest::released($k)));
        $out = []; $guard = 0;
        while ($enabled && $guard++ < 100) {
            $progress = false;
            foreach ($enabled as $i => $k) {
                $deps = Manifest::requires($k);
                if (!array_diff($deps, $out)) { $out[] = $k; unset($enabled[$i]); $progress = true; }
            }
            if (!$progress) break; // ما تبقى تنقصه تبعية غير مفعّلة
        }
        return $out;
    }

    /**
     * جرد للقراءة فقط لبقايا التثبيت القديم: لا يغيّر شيئًا ولا يحذف.
     * @return array{dirs:string[],versions:array<int,array{type:string,product:string,version:string}>,settings:array<string,int>}
     */
    public static function legacyScan(): array
    {
        $keys = array_keys(self::all());
        $out = ['dirs' => [], 'versions' => [], 'settings' => []];
        foreach ($keys as $key) {
            if (is_file(dirname(__DIR__, 2) . '/' . $key . '/' . ucfirst($key) . 'Plugin.php')) $out['dirs'][] = 'plugins/generic/' . $key;
        }
        try {
            $rows = \Illuminate\Support\Facades\DB::table('versions')->whereIn('product', $keys)->get(['product_type', 'product', 'major', 'minor', 'revision', 'build', 'current']);
            foreach ($rows as $r) $out['versions'][] = ['type' => (string) $r->product_type, 'product' => (string) $r->product, 'version' => $r->major . '.' . $r->minor . '.' . $r->revision . '.' . $r->build . ((int) $r->current ? '' : ' (غير حالي)')];
            $names = array_merge(array_map([self::class, 'legacyName'], $keys));
            foreach (\Illuminate\Support\Facades\DB::table('plugin_settings')->whereIn('plugin_name', $names)->selectRaw('plugin_name, count(*) as n')->groupBy('plugin_name')->get() as $r) $out['settings'][(string) $r->plugin_name] = (int) $r->n;
        } catch (\Throwable $e) { error_log('[tadabburSuite] legacyScan: ' . $e->getMessage()); }
        return $out;
    }
}
