<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardPortal/classes/Activity.php
 * نشاط عضو الهيئة في سنة: التحكيم، والقرارات التحريرية، والبحوث المسندة، والنشر في المجلة، وحضور الاجتماعات، والقرارات المكلّف بها.
 * يُحسب من بيانات OJS نفسها للأعضاء المرتبطين بحساب مستخدم.
 */
namespace APP\plugins\generic\tadabburSuite\m\boardPortal\classes;

use Illuminate\Support\Facades\DB;
use PKP\security\Role;

class Activity
{
    public const KEYS = ['reviews', 'declined', 'decisions', 'handled', 'authored', 'meetings', 'resDone', 'resOpen'];

    public static function forMember(object $m, int $cid, int $year): array
    {
        $from = $year . '-01-01 00:00:00'; $to = $year . '-12-31 23:59:59';
        $out = array_fill_keys(self::KEYS, 0); $out['meetingsTotal'] = 0; $out['linked'] = (bool) $m->user_id;
        $uid = (int) $m->user_id;
        if ($uid) {
            $ra = fn () => DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')->where('s.context_id', $cid)->where('ra.reviewer_id', $uid);
            $out['reviews'] = $ra()->whereBetween('ra.date_completed', [$from, $to])->count();
            $out['declined'] = $ra()->where('ra.declined', 1)->whereBetween('ra.date_assigned', [$from, $to])->count();
            $out['decisions'] = DB::table('edit_decisions as d')->join('submissions as s', 's.submission_id', '=', 'd.submission_id')->where('s.context_id', $cid)
                ->where('d.editor_id', $uid)->whereBetween('d.date_decided', [$from, $to])->distinct()->count('d.submission_id');
            $out['handled'] = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
                ->where('s.context_id', $cid)->where('sa.user_id', $uid)->whereIn('ug.role_id', [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
                ->whereBetween('sa.date_assigned', [$from, $to])->distinct()->count('sa.submission_id');
        }
        if ($m->email !== '') {
            $out['authored'] = DB::table('authors as a')->join('publications as p', 'p.publication_id', '=', 'a.publication_id')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
                ->where('s.context_id', $cid)->where('p.status', 3)->whereRaw('LOWER(a.email) = ?', [mb_strtolower($m->email)])
                ->whereBetween('p.date_published', [$year . '-01-01', $year . '-12-31'])->distinct()->count('s.submission_id');
        }
        $meetIds = DB::table(Store::MT)->where('context_id', $cid)->where('board', $m->board)->where('status', 'held')->whereBetween('meet_at', [$from, $to])->pluck('meeting_id')->all();
        $out['meetingsTotal'] = count($meetIds);
        if ($meetIds) $out['meetings'] = DB::table(Store::AT)->whereIn('meeting_id', $meetIds)->where('member_id', $m->member_id)->where('status', 'present')->count();
        $r = DB::table(Store::R)->where('context_id', $cid)->where('owner_member_id', $m->member_id);
        $out['resOpen'] = (clone $r)->where('status', 'open')->count();
        $out['resDone'] = (clone $r)->where('status', 'done')->whereBetween('updated_at', [$from, $to])->count();
        return $out;
    }

    /** لمحة عن المجلة في سنة (لبوابة العضو والتقرير السنوي) */
    public static function journal(int $cid, int $year): array
    {
        $from = $year . '-01-01 00:00:00'; $to = $year . '-12-31 23:59:59';
        $subs = DB::table('submissions')->where('context_id', $cid)->whereBetween('date_submitted', [$from, $to]);
        $received = (clone $subs)->count();
        $published = DB::table('publications as p')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')->where('s.context_id', $cid)->where('p.status', 3)
            ->whereBetween('p.date_published', [$year . '-01-01', $year . '-12-31'])->distinct()->count('s.submission_id');
        $declined = (clone $subs)->where('status', 4)->count();
        $accepted = DB::table('edit_decisions as d')->join('submissions as s', 's.submission_id', '=', 'd.submission_id')->where('s.context_id', $cid)
            ->whereIn('d.decision', [2, 17])->whereBetween('s.date_submitted', [$from, $to])->distinct()->count('d.submission_id');
        $first = DB::table('edit_decisions as d')->join('submissions as s', 's.submission_id', '=', 'd.submission_id')->where('s.context_id', $cid)
            ->whereBetween('s.date_submitted', [$from, $to])->groupBy('s.submission_id', 's.date_submitted')->selectRaw('s.date_submitted as ds, MIN(d.date_decided) as dd')->get();
        $days = [];
        foreach ($first as $f) if ($f->dd) $days[] = max(0, (int) round((strtotime($f->dd) - strtotime($f->ds)) / 86400));
        $reviews = DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')->where('s.context_id', $cid)->whereBetween('ra.date_completed', [$from, $to])->count();
        $decided = $accepted + $declined;
        return ['received' => $received, 'published' => $published, 'accepted' => $accepted, 'declined' => $declined,
            'acceptRate' => $decided ? (int) round(100 * $accepted / $decided) : null, 'firstDecisionDays' => $days ? (int) round(array_sum($days) / count($days)) : null, 'reviews' => $reviews];
    }
}
