<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/SuiteMigration.php
 *
 * ترحيل قاعدة البيانات للحزمة. يعمل في مسارين رسميين فقط، ولا يعمل أثناء تحميل الصفحات:
 *   1) عند تثبيت/ترقية الحزمة عبر OJS (Installer::postInstall ← Plugin::updateSchema ← getInstallMigration).
 *   2) عند ضغط المدير «تشغيل الترحيل» في اللوحة (POST + CSRF) إن رُفعت الملفات يدويًا.
 *
 * الضمانات:
 *   - سجل tdb_migrations: صف لكل وحدة بحالتها (ok/failed) ومحاولتها وخطئها وبصمة ملف ترحيلها.
 *   - قفل ذري: تشغيل متزامن ثانٍ يُرفض ما دام الأول لم ينتهِ (ينتهي القفل المعلّق بعد 15 دقيقة).
 *   - فشل وحدة لا يُسجَّل نجاحًا ولا يوقف بقية الوحدات، ويُعاد المحاولة عند التشغيل التالي.
 *   - لا تحذف أي بيانات؛ كل ترحيل وحدة ينشئ ما ينقصه فقط.
 *   - لا يُرحَّل إلا ما صُرِّح بإطلاقه في Manifest، فلا تُنشأ جداول وحدات لم تُعتمد.
 */
namespace APP\plugins\generic\tadabburSuite\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuiteMigration extends Migration
{
    private const LOCK = ['module' => '_core', 'name' => 'lock'];
    private const STALE = 900;

    public function up(): void
    {
        // لا نرمي استثناء: OJS يحذف ملفات الإضافة كاملة إذا فشلت ترقيتها. الفشل يُسجَّل في tdb_migrations،
        // وتمتنع الحزمة عن تحميل الوحدة المتأثرة فقط، ويظهر ذلك في فحص الجاهزية.
        foreach (self::run() as $err) error_log('[tadabburSuite] migration: ' . $err);
    }

    public function down(): void {}

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public static function ensureLedger(): void
    {
        if (Schema::hasTable('tdb_migrations')) return;
        Schema::create('tdb_migrations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('module', 64);
            $t->string('name', 128);
            $t->string('checksum', 64)->default('');
            $t->string('state', 16)->default('idle');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('started_at', 19)->nullable();
            $t->string('finished_at', 19)->nullable();
            $t->text('error')->nullable();
            $t->unique(['module', 'name'], 'tdb_migrations_uq');
        });
    }

    /** يأخذ القفل ذريًا. false = تشغيل آخر جارٍ. */
    private static function lock(): bool
    {
        DB::table('tdb_migrations')->insertOrIgnore(self::LOCK + ['checksum' => '', 'state' => 'idle', 'attempts' => 0]);
        $cut = gmdate('Y-m-d H:i:s', time() - self::STALE);
        $n = DB::table('tdb_migrations')->where(self::LOCK)
            ->where(fn ($q) => $q->where('state', '!=', 'running')->orWhereNull('started_at')->orWhere('started_at', '<', $cut))
            ->update(['state' => 'running', 'started_at' => self::now(), 'finished_at' => null, 'error' => null]);
        return $n === 1;
    }

    private static function unlock(string $state): void
    {
        DB::table('tdb_migrations')->where(self::LOCK)->update(['state' => $state, 'finished_at' => self::now()]);
    }

    /** @return string[] الأخطاء (فارغ = نجحت كل الوحدات المصرَّح بها) */
    public static function run(): array
    {
        Modules::registerAutoloader();
        self::ensureLedger();
        if (!self::lock()) return ['locked: ترحيل آخر قيد التنفيذ'];
        $errors = [];
        try {
            foreach (Modules::released() as $key) {
                $m = Modules::all()[$key];
                $row = ['module' => $key, 'name' => 'install'];
                DB::table('tdb_migrations')->insertOrIgnore($row + ['checksum' => '', 'state' => 'idle', 'attempts' => 0]);
                DB::table('tdb_migrations')->where($row)->update(['state' => 'running', 'started_at' => self::now(), 'finished_at' => null, 'error' => null, 'attempts' => DB::raw('attempts + 1')]);
                try {
                    if (!class_exists($m['class'])) throw new \RuntimeException('class not found: ' . $m['class']);
                    $plugin = new $m['class']();
                    $mig = $plugin->getInstallMigration();
                    if ($mig) $mig->up();
                    $file = (new \ReflectionClass($mig ?: $plugin))->getFileName();
                    DB::table('tdb_migrations')->where($row)->update(['state' => 'ok', 'finished_at' => self::now(), 'checksum' => $file ? hash_file('sha256', $file) : '']);
                } catch (\Throwable $e) {
                    $errors[] = $key . ': ' . $e->getMessage();
                    DB::table('tdb_migrations')->where($row)->update(['state' => 'failed', 'finished_at' => self::now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
                }
            }
        } finally {
            self::unlock($errors ? 'failed' : 'ok');
        }
        return $errors;
    }

    private static ?array $okCache = null;

    /** @return string[] الوحدات التي نجح ترحيلها (استعلام واحد لكل طلب؛ فارغ إن لم يوجد السجل) */
    public static function okModules(): array
    {
        if (self::$okCache !== null) return self::$okCache;
        try {
            return self::$okCache = DB::table('tdb_migrations')->where('name', 'install')->where('state', 'ok')->pluck('module')->all();
        } catch (\Throwable $e) { return self::$okCache = []; }
    }

    /** حالة الترحيل لكل وحدة (للقراءة فقط، لفحص الجاهزية) @return array<string,object> */
    public static function status(): array
    {
        try {
            if (!Schema::hasTable('tdb_migrations')) return [];
            return DB::table('tdb_migrations')->where('module', '!=', '_core')->get()->keyBy('module')->all();
        } catch (\Throwable $e) { return []; }
    }
}
