<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/quranVerifier/classes/Store.php
 * جدول تقارير التدقيق (يُنشأ عند التثبيت ولا يُحذف عند الترقية).
 */
namespace APP\plugins\generic\tadabburSuite\m\quranVerifier\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const TABLE = 'quran_verifier_reports';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->bigIncrements('report_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id')->nullable();
            $t->bigInteger('submission_file_id')->nullable();
            $t->string('file_name', 255)->default('');
            $t->bigInteger('user_id')->nullable();
            $t->smallInteger('auto')->default(0);
            $t->dateTime('created_at');
            $t->text('stats');
            $t->longText('result');
            $t->index(['context_id', 'submission_id'], 'qv_ctx_sub');
        });
    }

    public function down(): void {} // تُحفظ التقارير عند إزالة الإضافة

    public function add(array $row): int
    {
        return (int) DB::table(self::TABLE)->insertGetId($row, 'report_id');
    }

    public function get(int $id, int $contextId): ?object
    {
        return DB::table(self::TABLE)->where('report_id', $id)->where('context_id', $contextId)->first();
    }

    /** آخر تقرير لكل بحث @return array<int, object> */
    public function latestBySubmission(int $contextId, array $submissionIds): array
    {
        if (!$submissionIds) return [];
        $rows = DB::table(self::TABLE)->where('context_id', $contextId)->whereIn('submission_id', $submissionIds)
            ->orderBy('report_id', 'desc')->get(['report_id', 'submission_id', 'created_at', 'stats', 'file_name', 'auto']);
        $out = [];
        foreach ($rows as $r) if (!isset($out[(int) $r->submission_id])) $out[(int) $r->submission_id] = $r;
        return $out;
    }

    public function forSubmission(int $contextId, int $submissionId): array
    {
        return DB::table(self::TABLE)->where('context_id', $contextId)->where('submission_id', $submissionId)
            ->orderBy('report_id', 'desc')->get(['report_id', 'created_at', 'stats', 'file_name', 'auto', 'user_id'])->all();
    }

    public function delete(int $id, int $contextId): void
    {
        DB::table(self::TABLE)->where('report_id', $id)->where('context_id', $contextId)->delete();
    }
}
