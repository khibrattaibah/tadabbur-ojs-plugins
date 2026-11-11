<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardVote/classes/Store.php
 * جدولا التصويتات والأصوات.
 */
namespace APP\plugins\generic\tadabburSuite\m\boardVote\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const V = 'board_votes';
    public const B = 'board_ballots';

    public function up(): void
    {
        if (!Schema::hasTable(self::V)) Schema::create(self::V, function (Blueprint $t): void {
            $t->bigIncrements('vote_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id')->nullable();
            $t->string('title', 255);
            $t->text('body')->nullable();
            $t->text('options');
            $t->text('voters');
            $t->date('deadline')->nullable();
            $t->smallInteger('secret')->default(0);
            $t->smallInteger('quorum')->default(50);
            $t->string('status', 20)->default('open');
            $t->bigInteger('created_by');
            $t->dateTime('created_at');
            $t->dateTime('closed_at')->nullable();
            $t->string('result', 255)->default('');
            $t->index(['context_id', 'status'], 'board_votes_ctx');
        });
        if (!Schema::hasTable(self::B)) Schema::create(self::B, function (Blueprint $t): void {
            $t->bigInteger('vote_id');
            $t->bigInteger('user_id');
            $t->integer('choice');
            $t->text('comment')->nullable();
            $t->dateTime('cast_at');
            $t->unique(['vote_id', 'user_id'], 'board_ballots_pk');
        });
    }

    public function down(): void {}

    public static function decode(object $v): object { $v->options = json_decode($v->options, true) ?: []; $v->voters = array_map('intval', json_decode($v->voters, true) ?: []); return $v; }

    public function all(int $cid): array { return array_map([self::class, 'decode'], DB::table(self::V)->where('context_id', $cid)->orderByRaw("CASE WHEN status='open' THEN 0 ELSE 1 END")->orderByDesc('vote_id')->limit(200)->get()->all()); }
    public function get(int $id, int $cid): ?object { $v = DB::table(self::V)->where('vote_id', $id)->where('context_id', $cid)->first(); return $v ? self::decode($v) : null; }

    public function forVoter(int $cid, int $uid): array
    {
        return array_values(array_filter($this->all($cid), fn ($v) => in_array($uid, $v->voters, true)));
    }

    public function create(int $cid, array $d): int { return (int) DB::table(self::V)->insertGetId($d + ['context_id' => $cid, 'created_at' => date('Y-m-d H:i:s'), 'status' => 'open'], 'vote_id'); }
    public function ballots(int $id): array { return DB::table(self::B)->where('vote_id', $id)->orderBy('cast_at')->get()->keyBy('user_id')->all(); }
    public function cast(int $id, int $uid, int $choice, string $comment): void { DB::table(self::B)->updateOrInsert(['vote_id' => $id, 'user_id' => $uid], ['choice' => $choice, 'comment' => $comment, 'cast_at' => date('Y-m-d H:i:s')]); }
    public function close(int $id, string $result): void { DB::table(self::V)->where('vote_id', $id)->update(['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'result' => mb_substr($result, 0, 255)]); }

    /** [عدد كل خيار، المصوّتون، النصاب متحقق؟، الفائز أو null عند التعادل] */
    public static function tally(object $v, array $ballots): array
    {
        $n = array_fill(0, count($v->options), 0);
        foreach ($ballots as $b) if (isset($n[(int) $b->choice])) $n[(int) $b->choice]++;
        $cast = count($ballots); $quorum = $v->voters ? (100 * $cast / count($v->voters)) >= (int) $v->quorum : false;
        $max = $n ? max($n) : 0; $top = array_keys($n, $max);
        return [$n, $cast, $quorum, $max > 0 && count($top) === 1 ? $top[0] : null];
    }

    /** المرشحون للتصويت: محررو المجلة وأعضاء الهيئتين المرتبطون بحسابات */
    public static function candidates(int $cid): array
    {
        $ids = DB::table('user_user_groups as uug')->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')->where('ug.context_id', $cid)
            ->whereIn('ug.role_id', [16, 17])->where(fn ($q) => $q->whereNull('uug.date_end')->orWhere('uug.date_end', '>', date('Y-m-d H:i:s')))->pluck('uug.user_id')->all();
        if (Schema::hasTable('board_members')) $ids = array_merge($ids, DB::table('board_members')->where('context_id', $cid)->where('active', 1)->whereNotNull('user_id')->pluck('user_id')->all());
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
