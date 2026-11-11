<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardPortal/classes/Store.php
 * جداول: أعضاء الهيئتين، الاجتماعات، الحضور، القرارات.
 */
namespace APP\plugins\generic\tadabburSuite\m\boardPortal\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const M = 'board_members';
    public const MT = 'board_meetings';
    public const AT = 'board_attendance';
    public const R = 'board_resolutions';
    public const BOARDS = ['editorial', 'advisory'];

    public function up(): void
    {
        if (!Schema::hasTable(self::M)) Schema::create(self::M, function (Blueprint $t): void {
            $t->bigIncrements('member_id');
            $t->bigInteger('context_id');
            $t->string('board', 20);
            $t->string('name_ar', 255)->default('');
            $t->string('name_en', 255)->default('');
            $t->string('role_ar', 255)->default('');
            $t->string('role_en', 255)->default('');
            $t->string('affiliation_ar', 255)->default('');
            $t->string('affiliation_en', 255)->default('');
            $t->string('country', 100)->default('');
            $t->string('email', 255)->default('');
            $t->string('orcid', 40)->default('');
            $t->bigInteger('user_id')->nullable();
            $t->date('term_start')->nullable();
            $t->date('term_end')->nullable();
            $t->smallInteger('active')->default(1);
            $t->integer('seq')->default(0);
            $t->index(['context_id', 'board'], 'board_members_ctx');
        });
        if (!Schema::hasTable(self::MT)) Schema::create(self::MT, function (Blueprint $t): void {
            $t->bigIncrements('meeting_id');
            $t->bigInteger('context_id');
            $t->string('board', 20);
            $t->integer('number')->default(0);
            $t->string('title', 255)->default('');
            $t->dateTime('meet_at');
            $t->string('location', 255)->default('');
            $t->text('agenda')->nullable();
            $t->text('minutes')->nullable();
            $t->string('status', 20)->default('planned');
            $t->index(['context_id', 'meet_at'], 'board_meetings_ctx');
        });
        if (!Schema::hasTable(self::AT)) Schema::create(self::AT, function (Blueprint $t): void {
            $t->bigInteger('meeting_id');
            $t->bigInteger('member_id');
            $t->string('status', 20)->default('present');
            $t->unique(['meeting_id', 'member_id'], 'board_attendance_pk');
        });
        if (!Schema::hasTable(self::R)) Schema::create(self::R, function (Blueprint $t): void {
            $t->bigIncrements('resolution_id');
            $t->bigInteger('context_id');
            $t->bigInteger('meeting_id')->nullable();
            $t->string('number', 40)->default('');
            $t->text('text');
            $t->bigInteger('owner_member_id')->nullable();
            $t->date('due_date')->nullable();
            $t->string('status', 20)->default('open');
            $t->text('done_note')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at')->nullable();
            $t->index(['context_id', 'status'], 'board_resolutions_ctx');
        });
    }

    public function down(): void {} // سجلات رسمية لا تُحذف

    // ---------------------------------------------------------------- الأعضاء
    public function members(int $cid, ?string $board = null, bool $activeOnly = false): array
    {
        $q = DB::table(self::M)->where('context_id', $cid);
        if ($board) $q->where('board', $board);
        if ($activeOnly) $q->where('active', 1);
        return $q->orderBy('board')->orderBy('seq')->orderBy('member_id')->get()->all();
    }

    public function member(int $id, int $cid): ?object { return DB::table(self::M)->where('member_id', $id)->where('context_id', $cid)->first(); }

    public function memberByUser(int $cid, int $uid): array
    {
        return DB::table(self::M)->where('context_id', $cid)->where('user_id', $uid)->where('active', 1)->get()->all();
    }

    public function saveMember(int $cid, array $d, ?int $id): int
    {
        $d['context_id'] = $cid;
        // ربط تلقائي بحساب المستخدم عبر البريد
        if (empty($d['user_id']) && !empty($d['email'])) {
            $uid = DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower($d['email'])])->value('user_id');
            $d['user_id'] = $uid ? (int) $uid : null;
        }
        if ($id) { DB::table(self::M)->where('member_id', $id)->where('context_id', $cid)->update($d); return $id; }
        return (int) DB::table(self::M)->insertGetId($d, 'member_id');
    }

    // ---------------------------------------------------------------- الاجتماعات
    public function meetings(int $cid, ?int $year = null): array
    {
        $q = DB::table(self::MT)->where('context_id', $cid);
        if ($year) $q->whereBetween('meet_at', [$year . '-01-01 00:00:00', $year . '-12-31 23:59:59']);
        return $q->orderBy('meet_at', 'desc')->get()->all();
    }

    public function meeting(int $id, int $cid): ?object { return DB::table(self::MT)->where('meeting_id', $id)->where('context_id', $cid)->first(); }

    public function saveMeeting(int $cid, array $d, ?int $id): int
    {
        $d['context_id'] = $cid;
        if ($id) { DB::table(self::MT)->where('meeting_id', $id)->where('context_id', $cid)->update($d); return $id; }
        $year = substr($d['meet_at'], 0, 4);
        $d['number'] = (int) DB::table(self::MT)->where('context_id', $cid)->where('board', $d['board'])
            ->whereBetween('meet_at', [$year . '-01-01 00:00:00', $year . '-12-31 23:59:59'])->count() + 1;
        return (int) DB::table(self::MT)->insertGetId($d, 'meeting_id');
    }

    public function attendance(int $meetingId): array
    {
        return DB::table(self::AT)->where('meeting_id', $meetingId)->pluck('status', 'member_id')->all();
    }

    public function saveAttendance(int $meetingId, array $statusByMember): void
    {
        DB::table(self::AT)->where('meeting_id', $meetingId)->delete();
        $rows = [];
        foreach ($statusByMember as $mid => $s) if (in_array($s, ['present', 'absent', 'excused'], true)) $rows[] = ['meeting_id' => $meetingId, 'member_id' => (int) $mid, 'status' => $s];
        if ($rows) DB::table(self::AT)->insert($rows);
    }

    // ---------------------------------------------------------------- القرارات
    public function resolutions(int $cid, array $f = []): array
    {
        $q = DB::table(self::R)->where('context_id', $cid);
        if (!empty($f['meeting'])) $q->where('meeting_id', (int) $f['meeting']);
        if (!empty($f['status'])) $q->where('status', $f['status']);
        if (!empty($f['owner'])) $q->whereIn('owner_member_id', (array) $f['owner']);
        return $q->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('due_date')->orderBy('resolution_id', 'desc')->get()->all();
    }

    public function addResolution(int $cid, ?int $meetingId, string $text, ?int $owner, ?string $due): int
    {
        $num = '';
        if ($meetingId && ($m = $this->meeting($meetingId, $cid))) {
            $seq = (int) DB::table(self::R)->where('meeting_id', $meetingId)->count() + 1;
            $num = substr($m->meet_at, 0, 4) . '/' . $m->number . '/' . $seq;
        } else {
            $num = date('Y') . '/0/' . ((int) DB::table(self::R)->where('context_id', $cid)->whereNull('meeting_id')->count() + 1);
        }
        return (int) DB::table(self::R)->insertGetId(['context_id' => $cid, 'meeting_id' => $meetingId, 'number' => $num, 'text' => $text,
            'owner_member_id' => $owner, 'due_date' => $due, 'status' => 'open', 'created_at' => date('Y-m-d H:i:s')], 'resolution_id');
    }

    public function setResolution(int $id, int $cid, string $status, string $note): void
    {
        DB::table(self::R)->where('resolution_id', $id)->where('context_id', $cid)->update(['status' => $status, 'done_note' => $note, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
