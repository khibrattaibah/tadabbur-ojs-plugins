<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tasksBoard/classes/Store.php
 * جدولا المهام وتعليقاتها.
 */
namespace APP\plugins\generic\tadabburSuite\m\tasksBoard\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const T = 'editorial_tasks';
    public const C = 'editorial_task_comments';
    public const STATUSES = ['todo', 'doing', 'done'];
    public const PRIORITIES = ['high', 'normal', 'low'];

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigIncrements('task_id');
            $t->bigInteger('context_id');
            $t->string('title', 255);
            $t->text('body')->nullable();
            $t->bigInteger('submission_id')->nullable();
            $t->bigInteger('assignee_id')->nullable();
            $t->bigInteger('created_by');
            $t->date('due_date')->nullable();
            $t->string('priority', 10)->default('normal');
            $t->string('status', 10)->default('todo');
            $t->dateTime('created_at');
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('done_at')->nullable();
            $t->index(['context_id', 'status'], 'editorial_tasks_ctx');
        });
        if (!Schema::hasTable(self::C)) Schema::create(self::C, function (Blueprint $t): void {
            $t->bigIncrements('comment_id');
            $t->bigInteger('task_id');
            $t->bigInteger('user_id');
            $t->text('body');
            $t->dateTime('created_at');
            $t->index(['task_id'], 'editorial_task_comments_t');
        });
    }

    public function down(): void {}

    public function list(int $cid, ?int $assignee = null, bool $showOldDone = false): array
    {
        $q = DB::table(self::T)->where('context_id', $cid);
        if ($assignee) $q->where('assignee_id', $assignee);
        if (!$showOldDone) $q->where(fn ($x) => $x->where('status', '!=', 'done')->orWhere('done_at', '>=', date('Y-m-d', strtotime('-30 days'))));
        return $q->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('task_id')->get()->all();
    }

    public function get(int $id, int $cid): ?object { return DB::table(self::T)->where('task_id', $id)->where('context_id', $cid)->first(); }
    public function create(array $d): int { return (int) DB::table(self::T)->insertGetId($d + ['created_at' => date('Y-m-d H:i:s'), 'status' => 'todo'], 'task_id'); }
    public function update(int $id, array $d): void { DB::table(self::T)->where('task_id', $id)->update($d + ['updated_at' => date('Y-m-d H:i:s')]); }
    public function comments(int $id): array { return DB::table(self::C)->where('task_id', $id)->orderBy('created_at')->get()->all(); }
    public function comment(int $id, int $uid, string $body): void { DB::table(self::C)->insert(['task_id' => $id, 'user_id' => $uid, 'body' => $body, 'created_at' => date('Y-m-d H:i:s')]); }

    public static function team(int $cid): array
    {
        return DB::table('user_user_groups as uug')->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')->where('ug.context_id', $cid)
            ->whereIn('ug.role_id', [16, 17, 4097])->where(fn ($q) => $q->whereNull('uug.date_end')->orWhere('uug.date_end', '>', date('Y-m-d H:i:s')))->distinct()->pluck('uug.user_id')->map(fn ($x) => (int) $x)->all();
    }
}
