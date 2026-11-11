<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerHonor/classes/Honor.php
 * مؤشرات المحكّمين السنوية، وقرارات الظهور العام والجوائز.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerHonor\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Honor extends Migration
{
    public const T = 'reviewer_honors';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('context_id');
            $t->integer('year');
            $t->bigInteger('user_id');
            $t->smallInteger('public')->default(0);
            $t->string('award', 40)->default('');
            $t->unique(['context_id', 'year', 'user_id'], 'reviewer_honors_pk');
        });
    }

    public function down(): void {}

    /** السنوات التي أنجز فيها المستخدم تحكيمًا */
    public static function years(int $cid, int $uid): array
    {
        return DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')->where('s.context_id', $cid)->where('ra.reviewer_id', $uid)
            ->whereNotNull('ra.date_completed')->pluck('ra.date_completed')->map(fn ($d) => (int) substr((string) $d, 0, 4))->unique()->sortDesc()->values()->all();
    }

    /** مؤشرات كل محكّم في السنة، مرتبة بالنقاط */
    public static function stats(int $cid, int $year): array
    {
        $from = $year . '-01-01 00:00:00'; $to = $year . '-12-31 23:59:59';
        $rows = DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')->where('s.context_id', $cid)
            ->where(fn ($q) => $q->whereBetween('ra.date_completed', [$from, $to])->orWhere(fn ($x) => $x->where('ra.declined', 1)->whereBetween('ra.date_assigned', [$from, $to])))
            ->get(['ra.reviewer_id', 'ra.date_assigned', 'ra.date_completed', 'ra.date_due', 'ra.declined', 'ra.quality']);
        $by = [];
        foreach ($rows as $r) {
            $u = (int) $r->reviewer_id; $b = &$by[$u];
            $b ??= ['uid' => $u, 'done' => 0, 'declined' => 0, 'days' => [], 'onTime' => 0, 'q' => []];
            if ($r->declined) { $b['declined']++; unset($b); continue; }
            $b['done']++;
            if ($r->date_assigned) $b['days'][] = max(0, (strtotime($r->date_completed) - strtotime($r->date_assigned)) / 86400);
            if (!$r->date_due || $r->date_completed <= $r->date_due) $b['onTime']++;
            if ($r->quality) $b['q'][] = (int) $r->quality;
            unset($b);
        }
        $out = [];
        foreach ($by as $b) {
            if (!$b['done']) continue;
            $avg = $b['days'] ? round(array_sum($b['days']) / count($b['days'])) : null; $q = $b['q'] ? round(array_sum($b['q']) / count($b['q']), 1) : null;
            $on = (int) round(100 * $b['onTime'] / $b['done']);
            // نقاط مركّبة: الإنجاز أولًا، ثم الالتزام بالموعد، ثم الجودة، مع خصم يسير للبطء والاعتذار
            $score = round($b['done'] * 10 + $on * 0.2 + ($q ?? 3) * 4 - ($avg ?? 30) * 0.1 - $b['declined'] * 2, 1);
            $out[] = ['uid' => $b['uid'], 'done' => $b['done'], 'declined' => $b['declined'], 'avgDays' => $avg, 'onTime' => $on, 'quality' => $q, 'score' => $score];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public static function marks(int $cid, int $year): array { return DB::table(self::T)->where('context_id', $cid)->where('year', $year)->get()->keyBy('user_id')->all(); }

    public static function set(int $cid, int $year, int $uid, array $d): void { DB::table(self::T)->updateOrInsert(['context_id' => $cid, 'year' => $year, 'user_id' => $uid], $d); }
}
