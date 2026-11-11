<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/authorTracker/classes/Timeline.php
 * بناء الخط الزمني للبحث وحالته الحالية ومتوسطات المجلة.
 */
namespace APP\plugins\generic\tadabburSuite\m\authorTracker\classes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;

class Timeline
{
    public static function authorSubs(int $cid, int $uid): array
    {
        return DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
            ->where('s.context_id', $cid)->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->whereNotNull('s.date_submitted')
            ->groupBy('s.submission_id', 's.date_submitted')->orderByDesc('s.date_submitted')->pluck('s.submission_id')->map(fn ($x) => (int) $x)->all();
    }

    private static function d(?string $s): string { return $s ? substr($s, 0, 10) : ''; }

    /** أحداث مرتبة: [date, key, params] */
    public static function events(int $sid): array
    {
        $s = DB::table('submissions')->where('submission_id', $sid)->first(); if (!$s) return [];
        $ev = [[self::d($s->date_submitted), 'submitted', []]];
        foreach (DB::table('edit_decisions')->where('submission_id', $sid)->orderBy('date_decided')->get() as $d)
            $ev[] = [self::d($d->date_decided), 'dec.' . (int) $d->decision, []];
        foreach (DB::table('review_rounds')->where('submission_id', $sid)->where('stage_id', 3)->orderBy('round')->get() as $rr) {
            $ras = DB::table('review_assignments')->where('review_round_id', $rr->review_round_id)->where('declined', 0)->where('cancelled', 0)->orderBy('date_assigned')->get();
            if (!$ras->count()) continue;
            $ev[] = [self::d($ras->min('date_assigned')), 'reviewersAssigned', ['n' => $ras->count(), 'r' => $rr->round]];
            $i = 0;
            foreach ($ras->whereNotNull('date_completed')->sortBy('date_completed') as $ra) $ev[] = [self::d($ra->date_completed), 'reviewDone', ['i' => ++$i, 'n' => $ras->count(), 'r' => $rr->round]];
        }
        foreach (DB::table('submission_files')->where('submission_id', $sid)->where('file_stage', 15)->orderBy('created_at')->get() as $f)
            $ev[] = [self::d($f->created_at), 'revisionUploaded', []];
        $pub = DB::table('publications')->where('publication_id', $s->current_publication_id)->first();
        if ($pub && (int) $pub->status === 3 && $pub->date_published) $ev[] = [self::d($pub->date_published), 'published', []];
        $ev = array_values(array_filter($ev, fn ($e) => $e[0] !== ''));
        usort($ev, fn ($a, $b) => strcmp($a[0], $b[0]));
        // دمج رفع ملفات التعديل المتتالية في اليوم نفسه
        $out = [];
        foreach ($ev as $e) { $last = end($out); if ($last && $last[0] === $e[0] && $last[1] === $e[1] && $e[1] === 'revisionUploaded') continue; $out[] = $e; }
        return $out;
    }

    /** الحالة الحالية: [key, waitingOn(author|journal|none), params, stepIndex 0..5] */
    public static function status(int $sid): array
    {
        $s = DB::table('submissions')->where('submission_id', $sid)->first();
        $pub = DB::table('publications')->where('publication_id', $s->current_publication_id)->first();
        if ((int) $s->status === 3) return ['published', 'none', [], 5];
        if ((int) $s->status === 4) return ['declined', 'none', [], -1];
        if ((int) $s->status === 5 || ($pub && (int) $pub->status === 5)) return ['scheduled', 'journal', [], 4];
        $stage = (int) $s->stage_id;
        if ($stage === 1) return ['desk', 'journal', [], 0];
        if ($stage === 3) {
            $rr = DB::table('review_rounds')->where('submission_id', $sid)->where('stage_id', 3)->orderByDesc('round')->first();
            if ($rr) {
                $dec = DB::table('edit_decisions')->where('review_round_id', $rr->review_round_id)->orderByDesc('date_decided')->first();
                if ($dec && in_array((int) $dec->decision, [4, 5], true)) {
                    $rev = DB::table('submission_files')->where('submission_id', $sid)->where('file_stage', 15)->where('created_at', '>', $dec->date_decided)->exists();
                    return $rev ? ['revisionReceived', 'journal', [], 2] : ['awaitingRevision', 'author', ['d' => substr((string) $dec->date_decided, 0, 10)], 2];
                }
                $ras = DB::table('review_assignments')->where('review_round_id', $rr->review_round_id)->where('declined', 0)->where('cancelled', 0);
                $n = (clone $ras)->count(); $done = (clone $ras)->whereNotNull('date_completed')->count();
                if ($n === 0) return ['findingReviewers', 'journal', [], 1];
                if ($done < $n) return ['inReview', 'journal', ['d' => $done, 'n' => $n, 'r' => $rr->round], 1];
                return ['reviewsComplete', 'journal', ['r' => $rr->round], 2];
            }
            return ['findingReviewers', 'journal', [], 1];
        }
        if ($stage === 4) return ['copyediting', 'journal', [], 3];
        if ($stage === 5) return ['production', 'journal', [], 4];
        return ['desk', 'journal', [], 0];
    }

    private static function median(array $v): ?int { if (!$v) return null; sort($v); $n = count($v); return (int) round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2); }

    /** وسيط المدد في المجلة خلال سنتين (يُخزَّن 12 ساعة) */
    public static function benchmarks(int $cid): array
    {
        return Cache::remember('authorTracker.bench.' . $cid, 43200, function () use ($cid) {
            $since = date('Y-m-d', strtotime('-2 years'));
            $days = fn ($a, $b) => max(0, (int) round((strtotime($b) - strtotime($a)) / 86400));
            $first = []; $review = []; $toPub = []; $total = [];
            $subs = DB::table('submissions')->where('context_id', $cid)->where('date_submitted', '>=', $since)->get(['submission_id', 'date_submitted', 'current_publication_id']);
            foreach ($subs as $s) {
                $d1 = DB::table('edit_decisions')->where('submission_id', $s->submission_id)->min('date_decided');
                if ($d1) $first[] = $days($s->date_submitted, $d1);
                $ra = DB::table('review_assignments')->where('submission_id', $s->submission_id)->whereNotNull('date_completed')->get(['date_assigned', 'date_completed']);
                foreach ($ra as $r) if ($r->date_assigned) $review[] = $days($r->date_assigned, $r->date_completed);
                $acc = DB::table('edit_decisions')->where('submission_id', $s->submission_id)->whereIn('decision', [2, 17])->max('date_decided');
                $pub = DB::table('publications')->where('publication_id', $s->current_publication_id)->where('status', 3)->value('date_published');
                if ($acc && $pub) $toPub[] = $days($acc, $pub);
                if ($pub) $total[] = $days($s->date_submitted, $pub);
            }
            return ['firstDecision' => self::median($first), 'review' => self::median($review), 'acceptToPub' => self::median($toPub), 'total' => self::median($total)];
        });
    }
}
