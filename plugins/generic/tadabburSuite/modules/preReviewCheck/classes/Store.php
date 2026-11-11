<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/preReviewCheck/classes/Store.php
 */
namespace APP\plugins\generic\tadabburSuite\m\preReviewCheck\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const TABLE = 'prereview_checks';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->bigIncrements('check_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id');
            $t->bigInteger('submission_file_id')->nullable();
            $t->string('file_name', 255)->default('');
            $t->smallInteger('auto')->default(0);
            $t->integer('failed_req')->default(0);
            $t->integer('failed_rec')->default(0);
            $t->longText('result');
            $t->dateTime('created_at');
            $t->dateTime('sent_at')->nullable();
            $t->bigInteger('sent_by')->nullable();
            $t->index(['context_id', 'submission_id'], 'prereview_checks_sub');
        });
    }

    public function down(): void {}

    public function add(array $row): int
    {
        return (int) DB::table(self::TABLE)->insertGetId($row + ['created_at' => date('Y-m-d H:i:s')], 'check_id');
    }

    public function get(int $id, int $cid): ?object
    {
        return DB::table(self::TABLE)->where('check_id', $id)->where('context_id', $cid)->first();
    }

    public function markSent(int $id, int $uid): void
    {
        DB::table(self::TABLE)->where('check_id', $id)->update(['sent_at' => date('Y-m-d H:i:s'), 'sent_by' => $uid]);
    }

    public function latest(int $cid, array $sids): array
    {
        if (!$sids) return [];
        $out = [];
        foreach (DB::table(self::TABLE)->where('context_id', $cid)->whereIn('submission_id', $sids)->orderBy('check_id', 'desc')->get(['check_id', 'submission_id', 'failed_req', 'failed_rec', 'created_at', 'sent_at', 'auto']) as $r) {
            if (!isset($out[(int) $r->submission_id])) $out[(int) $r->submission_id] = $r;
        }
        return $out;
    }
}
