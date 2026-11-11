<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/proofApproval/classes/Store.php
 * طلبات اعتماد البروفة وردود المؤلفين.
 */
namespace APP\plugins\generic\tadabburSuite\m\proofApproval\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\security\Role;

class Store extends Migration
{
    public const T = 'proof_requests';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigIncrements('proof_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id');
            $t->integer('round')->default(1);
            $t->bigInteger('submission_file_id');
            $t->text('message')->nullable();
            $t->date('deadline')->nullable();
            $t->string('status', 20)->default('pending');
            $t->bigInteger('requested_by');
            $t->dateTime('requested_at');
            $t->bigInteger('responded_by')->nullable();
            $t->dateTime('responded_at')->nullable();
            $t->text('corrections')->nullable();
            $t->text('note')->nullable();
            $t->string('ip', 64)->default('');
            $t->index(['context_id', 'submission_id'], 'proof_requests_sub');
        });
    }

    public function down(): void {}

    public static function decode(?object $r): ?object { if ($r) $r->corrections = json_decode((string) $r->corrections, true) ?: []; return $r; }

    public function get(int $id, int $cid): ?object { return self::decode(DB::table(self::T)->where('proof_id', $id)->where('context_id', $cid)->first()); }
    public function forSub(int $sid): array { return array_map([self::class, 'decode'], DB::table(self::T)->where('submission_id', $sid)->orderByDesc('round')->get()->all()); }
    public function latest(int $sid): ?object { return self::decode(DB::table(self::T)->where('submission_id', $sid)->orderByDesc('round')->first()); }

    public static function authorIds(int $sid): array
    {
        return DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->where('sa.submission_id', $sid)
            ->where('ug.role_id', Role::ROLE_ID_AUTHOR)->pluck('sa.user_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
    }

    public function forAuthor(int $cid, int $uid): array
    {
        $sids = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->pluck('sa.submission_id')->all();
        return $sids ? array_map([self::class, 'decode'], DB::table(self::T)->where('context_id', $cid)->whereIn('submission_id', $sids)->orderByDesc('proof_id')->get()->all()) : [];
    }

    public function create(array $d): int
    {
        $round = (int) DB::table(self::T)->where('submission_id', $d['submission_id'])->max('round') + 1;
        DB::table(self::T)->where('submission_id', $d['submission_id'])->where('status', 'pending')->update(['status' => 'superseded']);
        return (int) DB::table(self::T)->insertGetId($d + ['round' => $round, 'requested_at' => date('Y-m-d H:i:s'), 'status' => 'pending'], 'proof_id');
    }

    public function respond(int $id, int $uid, string $status, array $corr, string $note, string $ip): void
    {
        DB::table(self::T)->where('proof_id', $id)->update(['status' => $status, 'responded_by' => $uid, 'responded_at' => date('Y-m-d H:i:s'),
            'corrections' => json_encode($corr, JSON_UNESCAPED_UNICODE), 'note' => $note, 'ip' => mb_substr($ip, 0, 64)]);
    }
}
