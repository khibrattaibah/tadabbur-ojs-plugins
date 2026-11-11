<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/surveys/classes/Store.php
 * الاستبانات ودعواتها وإجاباتها.
 */
namespace APP\plugins\generic\tadabburSuite\m\surveys\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const S = 'tdb_surveys';
    public const I = 'tdb_survey_invites';
    public const A = 'tdb_survey_answers';
    public const TRIGGERS = ['manual', 'decision', 'review', 'publish', 'open'];

    public function up(): void
    {
        if (!Schema::hasTable(self::S)) Schema::create(self::S, function (Blueprint $t): void {
            $t->bigIncrements('survey_id');
            $t->bigInteger('context_id');
            $t->string('title', 255);
            $t->text('intro')->nullable();
            $t->string('audience', 12)->default('author');
            $t->string('trigger_on', 12)->default('manual');
            $t->text('questions');
            $t->tinyInteger('active')->default(1);
            $t->dateTime('created_at');
            $t->index(['context_id'], 'tdb_surveys_ctx');
        });
        if (!Schema::hasTable(self::I)) Schema::create(self::I, function (Blueprint $t): void {
            $t->bigIncrements('invite_id');
            $t->bigInteger('survey_id');
            $t->bigInteger('context_id');
            $t->bigInteger('user_id')->nullable();
            $t->string('email', 255)->nullable();
            $t->string('name', 255)->nullable();
            $t->bigInteger('submission_id')->nullable();
            $t->bigInteger('review_id')->nullable();
            $t->string('token', 40);
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->index(['survey_id'], 'tdb_survey_inv_s');
            $t->index(['token'], 'tdb_survey_inv_t');
        });
        if (!Schema::hasTable(self::A)) Schema::create(self::A, function (Blueprint $t): void {
            $t->bigIncrements('answer_id');
            $t->bigInteger('survey_id');
            $t->bigInteger('invite_id')->nullable();
            $t->text('answers');
            $t->dateTime('created_at');
            $t->index(['survey_id'], 'tdb_survey_ans_s');
        });
    }

    public function down(): void {}

    public function all(int $cid): array { return DB::table(self::S)->where('context_id', $cid)->orderBy('survey_id')->get()->all(); }
    public function get(int $id, int $cid): ?object { return DB::table(self::S)->where('survey_id', $id)->where('context_id', $cid)->first(); }
    public function byTrigger(int $cid, string $tr): array { return DB::table(self::S)->where('context_id', $cid)->where('trigger_on', $tr)->where('active', 1)->get()->all(); }

    public function save(int $cid, ?int $id, array $row): int
    {
        if ($id) { DB::table(self::S)->where('survey_id', $id)->where('context_id', $cid)->update($row); return $id; }
        return (int) DB::table(self::S)->insertGetId($row + ['context_id' => $cid, 'created_at' => date('Y-m-d H:i:s')], 'survey_id');
    }

    public function stats(int $sid): array
    {
        $inv = DB::table(self::I)->where('survey_id', $sid);
        $n = (clone $inv)->count(); $done = (clone $inv)->whereNotNull('completed_at')->count();
        $ans = DB::table(self::A)->where('survey_id', $sid)->count();
        return ['invited' => $n, 'completed' => $done, 'answers' => $ans, 'rate' => $n ? (int) round(100 * $done / $n) : null];
    }

    /** دعوة واحدة لكل (استبانة، بريد، بحث، مهمة) حتى لا تتكرر الدعوات التلقائية */
    public function invite(object $s, ?int $uid, string $email, string $name, ?int $subId, ?int $revId): ?object
    {
        $q = DB::table(self::I)->where('survey_id', $s->survey_id)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
        $subId ? $q->where('submission_id', $subId) : $q->whereNull('submission_id');
        $revId ? $q->where('review_id', $revId) : $q->whereNull('review_id');
        if ($q->exists()) return null;
        $id = DB::table(self::I)->insertGetId(['survey_id' => $s->survey_id, 'context_id' => $s->context_id, 'user_id' => $uid, 'email' => $email, 'name' => mb_substr($name, 0, 250),
            'submission_id' => $subId, 'review_id' => $revId, 'token' => bin2hex(random_bytes(16))], 'invite_id');
        return DB::table(self::I)->where('invite_id', $id)->first();
    }

    public function byToken(string $t): ?object { return preg_match('/^[a-f0-9]{32}$/', $t) ? DB::table(self::I)->where('token', $t)->first() : null; }
    public function answers(int $sid): array { return DB::table(self::A)->where('survey_id', $sid)->orderBy('answer_id')->get()->all(); }
    public function addAnswer(int $sid, ?int $inviteId, array $a): void
    {
        DB::table(self::A)->insert(['survey_id' => $sid, 'invite_id' => $inviteId, 'answers' => json_encode($a, JSON_UNESCAPED_UNICODE), 'created_at' => date('Y-m-d H:i:s')]);
        if ($inviteId) DB::table(self::I)->where('invite_id', $inviteId)->update(['completed_at' => date('Y-m-d H:i:s')]);
    }
}
