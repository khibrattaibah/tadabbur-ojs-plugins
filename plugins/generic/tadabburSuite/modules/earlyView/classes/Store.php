<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/earlyView/classes/Store.php
 */
namespace APP\plugins\generic\tadabburSuite\m\earlyView\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const T = 'early_view';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('submission_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_file_id')->nullable();
            $t->smallInteger('shown')->default(0);
            $t->dateTime('posted_at')->nullable();
            $t->unique(['submission_id'], 'early_view_pk');
        });
    }

    public function down(): void {}

    public function all(int $cid): array { return DB::table(self::T)->where('context_id', $cid)->get()->keyBy('submission_id')->all(); }

    /** المعروض حاليًا ولم يُنشر بعد */
    public function visible(int $cid): array
    {
        return DB::table(self::T . ' as e')->join('submissions as s', 's.submission_id', '=', 'e.submission_id')->where('e.context_id', $cid)->where('e.shown', 1)->whereIn('s.status', [1, 5])
            ->orderByDesc('e.posted_at')->select('e.*')->get()->keyBy('submission_id')->all();
    }

    public function set(int $sid, int $cid, bool $shown, ?int $fileId): void
    {
        $cur = DB::table(self::T)->where('submission_id', $sid)->first();
        DB::table(self::T)->updateOrInsert(['submission_id' => $sid], ['context_id' => $cid, 'shown' => $shown ? 1 : 0, 'submission_file_id' => $fileId,
            'posted_at' => $shown ? ($cur->posted_at ?? date('Y-m-d H:i:s')) : ($cur->posted_at ?? null)]);
    }
}
