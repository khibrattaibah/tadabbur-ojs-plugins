<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialCommand/classes/Board.php
 *
 * يحسب لكل بحث نشط: مرحلته، ومنذ متى هو فيها، ومن ينتظره الآن (المحرر، المحكّم، المؤلف)،
 * وهل تجاوز المهلة المحددة، والخطوة التالية. يُقرأ كل شيء من جداول OJS مباشرة دفعة واحدة.
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialCommand\classes;

use Illuminate\Support\Facades\DB;
use PKP\decision\Decision;
use PKP\security\Role;

class Board
{
    public const STAGES = [1 => 'submission', 3 => 'review', 4 => 'copyediting', 5 => 'production'];

    public function __construct(private int $contextId, private array $sla) {}

    private static function days(?string $from, ?int $now = null): ?int
    {
        if (!$from) return null;
        return max(0, (int) floor((($now ?? time()) - strtotime($from)) / 86400));
    }

    /** @return array<int, array> */
    public function rows(?string $locale = null): array
    {
        $subs = DB::table('submissions')->where('context_id', $this->contextId)->where('status', 1)
            ->where(fn ($q) => $q->whereNull('submission_progress')->orWhere('submission_progress', ''))
            ->get(['submission_id', 'current_publication_id', 'date_submitted', 'date_last_activity', 'stage_id', 'locale'])->keyBy('submission_id');
        if ($subs->isEmpty()) return [];
        $ids = $subs->keys()->all();

        // العناوين والمؤلف الأول
        $pubIds = $subs->pluck('current_publication_id')->filter()->all();
        $titles = [];
        foreach (DB::table('publication_settings')->whereIn('publication_id', $pubIds)->where('setting_name', 'title')->get(['publication_id', 'locale', 'setting_value']) as $t) {
            $titles[(int) $t->publication_id][$t->locale] = trim(strip_tags((string) $t->setting_value));
        }
        $sections = DB::table('publications as p')->leftJoin('section_settings as ss', fn ($j) => $j->on('ss.section_id', '=', 'p.section_id')->where('ss.setting_name', 'title'))
            ->whereIn('p.publication_id', $pubIds)->get(['p.publication_id', 'ss.locale', 'ss.setting_value']);
        $sec = [];
        foreach ($sections as $s) $sec[(int) $s->publication_id][$s->locale ?? ''] = (string) $s->setting_value;

        // المحررون المسندون
        $editors = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->whereIn('sa.submission_id', $ids)->whereIn('ug.role_id', [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->get(['sa.submission_id', 'sa.user_id', 'sa.recommend_only'])->groupBy('submission_id');
        $userIds = $editors->flatten()->pluck('user_id')->unique()->all();
        $names = $this->names($userIds, $locale);

        // القرارات
        $decisions = DB::table('edit_decisions')->whereIn('submission_id', $ids)->orderBy('date_decided')->get(['submission_id', 'decision', 'date_decided', 'stage_id', 'round'])->groupBy('submission_id');

        // جولات التحكيم الأخيرة ومهام التحكيم
        $rounds = DB::table('review_rounds')->whereIn('submission_id', $ids)->orderBy('round')->get()->groupBy('submission_id');
        $reviews = DB::table('review_assignments')->whereIn('submission_id', $ids)->where('cancelled', 0)
            ->get(['submission_id', 'review_round_id', 'reviewer_id', 'date_assigned', 'date_confirmed', 'date_completed', 'date_due', 'date_response_due', 'declined'])->groupBy('submission_id');

        $now = time(); $out = [];
        foreach ($subs as $sid => $s) {
            $stage = (int) $s->stage_id;
            $dec = $decisions->get($sid, collect());
            $last = $dec->last();
            $stageSince = $s->date_submitted;
            foreach ($dec as $d) if (in_array((int) $d->decision, [Decision::EXTERNAL_REVIEW, Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW, Decision::SEND_TO_PRODUCTION, Decision::BACK_FROM_PRODUCTION, Decision::BACK_FROM_COPYEDITING], true)) $stageSince = $d->date_decided;
            if (strtotime((string) $stageSince) < strtotime((string) $s->date_submitted)) $stageSince = $s->date_submitted; // لا تسبق المرحلةُ تاريخ التقديم

            $eds = $editors->get($sid, collect());
            $row = [
                'id' => (int) $sid, 'stage' => $stage, 'stageKey' => self::STAGES[$stage] ?? 'other',
                'title' => $titles[(int) $s->current_publication_id][$locale ?? ''] ?? ($titles[(int) $s->current_publication_id][$s->locale] ?? ((($tmp = ($titles[(int) $s->current_publication_id] ?? [])) ? $tmp[array_key_first($tmp)] : '') ?: '#' . $sid)),
                'section' => $sec[(int) $s->current_publication_id][$locale ?? ''] ?? ((($tmp = ($sec[(int) $s->current_publication_id] ?? [])) ? (string) $tmp[array_key_first($tmp)] : '')),
                'submitted' => substr((string) $s->date_submitted, 0, 10), 'daysTotal' => self::days($s->date_submitted, $now),
                'stageSince' => substr((string) $stageSince, 0, 10), 'daysStage' => self::days($stageSince, $now),
                'lastActivity' => substr((string) $s->date_last_activity, 0, 10), 'daysIdle' => self::days($s->date_last_activity, $now),
                'editorIds' => $eds->pluck('user_id')->map(fn ($x) => (int) $x)->unique()->values()->all(),
                'editors' => $eds->pluck('user_id')->unique()->map(fn ($u) => $names[(int) $u] ?? '#' . $u)->values()->all(),
                'rev' => ['invited' => 0, 'pending' => 0, 'accepted' => 0, 'completed' => 0, 'declined' => 0, 'late' => 0, 'active' => 0],
                'waiting' => '', 'party' => '', 'overdue' => false, 'waitDays' => 0, 'detail' => '',
            ];

            if (!$row['editorIds']) {
                $row['waiting'] = 'assignEditor'; $row['party'] = 'editor'; $row['waitDays'] = $row['daysTotal'];
                $row['overdue'] = $row['daysTotal'] > $this->sla['assign'];
            } elseif ($stage === 1) {
                $row['waiting'] = 'deskDecision'; $row['party'] = 'editor'; $row['waitDays'] = $row['daysStage'];
                $row['overdue'] = $row['daysStage'] > $this->sla['desk'];
            } elseif ($stage === 3) {
                $round = $rounds->get($sid, collect())->where('stage_id', 3)->last();
                $ras = $reviews->get($sid, collect())->filter(fn ($r) => !$round || (int) $r->review_round_id === (int) $round->review_round_id);
                $active = $ras->filter(fn ($r) => !(int) $r->declined);
                $pending = $active->filter(fn ($r) => !$r->date_confirmed && !$r->date_completed);
                $accepted = $active->filter(fn ($r) => $r->date_confirmed && !$r->date_completed);
                $completed = $active->filter(fn ($r) => $r->date_completed);
                $lateResp = $pending->filter(fn ($r) => $r->date_response_due && strtotime($r->date_response_due) < $now);
                $lateRep = $accepted->filter(fn ($r) => $r->date_due && strtotime($r->date_due) < $now);
                $row['rev'] = ['invited' => $ras->count(), 'pending' => $pending->count(), 'accepted' => $accepted->count(), 'completed' => $completed->count(),
                    'declined' => $ras->count() - $active->count(), 'late' => $lateResp->count() + $lateRep->count(), 'active' => $active->count()];
                $revDecision = $last && in_array((int) $last->decision, [Decision::PENDING_REVISIONS, Decision::RESUBMIT], true) && (!$round || strtotime($last->date_decided) >= strtotime((string) ($completed->max('date_completed') ?? '1970-01-01')));
                $roundStatus = $round ? (int) $round->status : 0;
                if ($revDecision || in_array($roundStatus, [1, 2], true)) {
                    $since = $last ? $last->date_decided : $stageSince;
                    $row['waiting'] = 'authorRevision'; $row['party'] = 'author'; $row['waitDays'] = self::days($since, $now);
                    $row['overdue'] = $row['waitDays'] > $this->sla['revision'];
                } elseif (in_array($roundStatus, [11, 15], true)) {
                    $row['waiting'] = 'revisionReceived'; $row['party'] = 'editor'; $row['waitDays'] = self::days($s->date_last_activity, $now);
                    $row['overdue'] = $row['waitDays'] > $this->sla['decision'];
                } elseif ($active->count() < $this->sla['minReviewers'] && $completed->count() < $this->sla['minReviewers']) {
                    $row['waiting'] = 'needReviewers'; $row['party'] = 'editor'; $row['waitDays'] = $row['daysStage'];
                    $row['overdue'] = $row['daysStage'] > $this->sla['invite'];
                    $row['detail'] = $active->count() . '/' . $this->sla['minReviewers'];
                } elseif ($pending->count() || $accepted->count()) {
                    $row['waiting'] = $pending->count() ? 'reviewerResponse' : 'reviewerReport'; $row['party'] = 'reviewer';
                    $oldest = $active->filter(fn ($r) => !$r->date_completed)->min('date_assigned');
                    $row['waitDays'] = self::days($oldest, $now);
                    $row['overdue'] = ($lateResp->count() + $lateRep->count()) > 0;
                    $row['detail'] = $completed->count() . '/' . $active->count();
                } else {
                    $since = $completed->max('date_completed') ?? $stageSince;
                    $row['waiting'] = 'reviewDecision'; $row['party'] = 'editor'; $row['waitDays'] = self::days($since, $now);
                    $row['overdue'] = $row['waitDays'] > $this->sla['decision'];
                }
            } elseif ($stage === 4) {
                $row['waiting'] = 'copyediting'; $row['party'] = 'production'; $row['waitDays'] = $row['daysStage'];
                $row['overdue'] = $row['daysStage'] > $this->sla['copyedit'];
            } else {
                $row['waiting'] = 'production'; $row['party'] = 'production'; $row['waitDays'] = $row['daysStage'];
                $row['overdue'] = $row['daysStage'] > $this->sla['production'];
            }
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => [$b['overdue'], $b['waitDays']] <=> [$a['overdue'], $a['waitDays']]);
        return $out;
    }

    private function names(array $ids, ?string $locale): array
    {
        if (!$ids) return [];
        $rows = DB::table('user_settings')->whereIn('user_id', $ids)->whereIn('setting_name', ['givenName', 'familyName'])->get(['user_id', 'locale', 'setting_name', 'setting_value']);
        $by = [];
        foreach ($rows as $r) $by[(int) $r->user_id][$r->locale][$r->setting_name] = (string) $r->setting_value;
        $out = [];
        foreach ($ids as $id) {
            $l = $by[(int) $id][$locale ?? ''] ?? ($by[(int) $id]['ar'] ?? ((($tmp = ($by[(int) $id] ?? [])) ? $tmp[array_key_first($tmp)] : [])));
            $out[(int) $id] = trim(($l['givenName'] ?? '') . ' ' . ($l['familyName'] ?? '')) ?: ('#' . $id);
        }
        return $out;
    }

    /** ملخصات: حسب المرحلة، وحسب المحرر */
    public static function summary(array $rows): array
    {
        $s = ['total' => count($rows), 'overdue' => 0, 'byStage' => [], 'byParty' => [], 'byEditor' => [], 'median' => null];
        $waits = [];
        foreach ($rows as $r) {
            if ($r['overdue']) $s['overdue']++;
            $s['byStage'][$r['stageKey']] = ($s['byStage'][$r['stageKey']] ?? 0) + 1;
            $s['byParty'][$r['party']] = ($s['byParty'][$r['party']] ?? ['n' => 0, 'late' => 0]);
            $s['byParty'][$r['party']]['n']++; if ($r['overdue']) $s['byParty'][$r['party']]['late']++;
            foreach ($r['editorIds'] as $i => $eid) {
                $e = &$s['byEditor'][$eid];
                $e['name'] = $r['editors'][$i] ?? ('#' . $eid);
                $e['n'] = ($e['n'] ?? 0) + 1;
                $e['late'] = ($e['late'] ?? 0) + (($r['overdue'] && $r['party'] === 'editor') ? 1 : 0);
                $e['waiting'] = ($e['waiting'] ?? 0) + ($r['party'] === 'editor' ? 1 : 0);
                unset($e);
            }
            $waits[] = (int) $r['daysTotal'];
        }
        sort($waits); $n = count($waits);
        if ($n) $s['median'] = $n % 2 ? $waits[intdiv($n, 2)] : (int) round(($waits[$n / 2 - 1] + $waits[$n / 2]) / 2);
        uasort($s['byEditor'], fn ($a, $b) => [$b['late'], $b['n']] <=> [$a['late'], $a['n']]);
        return $s;
    }
}
