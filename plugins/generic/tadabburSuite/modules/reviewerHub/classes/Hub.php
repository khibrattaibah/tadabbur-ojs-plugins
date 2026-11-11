<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerHub/classes/Hub.php
 *
 * - دليل المحكّمين: الاهتمامات، الانتماء، الدولة، ORCID، ومؤشرات الأداء من سجل التحكيم الفعلي
 *   (الدعوات، القبول، الاعتذار، الإنجاز، متوسط المدة، التأخر، تقييم المحرر للجودة، العبء الحالي).
 * - اقتراح المحكّمين لبحث: مطابقة الاهتمامات وخبرة التحكيم السابقة بكلمات البحث وعنوانه، مع موازنة الجودة والسرعة والعبء.
 * - كشف تضارب المصالح: المحكّم مؤلف، أو من جهة أحد المؤلفين، أو شارك أحدهم التأليف في المجلة، أو مسند للبحث سلفًا.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerHub\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;

class Hub
{
    private const STOP = ['في', 'من', 'على', 'الى', 'عن', 'مع', 'و', 'او', 'ثم', 'بين', 'دراسه', 'تحليليه', 'وصفيه', 'نموذجا', 'خلال', 'the', 'of', 'and', 'in', 'a', 'an', 'to', 'on', 'for', 'study', 'analytical', 'with', 'its'];

    public function __construct(private int $contextId) {}

    public static function norm(string $s): string
    {
        $s = mb_strtolower(preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s));
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', '’' => '', "'" => '']);
        return trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s));
    }

    /** @return string[] */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', self::norm($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) > 4 && str_starts_with($w, 'ال')) $w = mb_substr($w, 2);
            if (mb_strlen($w) > 5 && str_starts_with($w, 'وال')) $w = mb_substr($w, 3);
            if (mb_strlen($w) < 3 || in_array($w, self::STOP, true)) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }

    // ------------------------------------------------------------------ الدليل
    /** @return array<int, array> */
    public function reviewers(?string $locale = null): array
    {
        $ids = DB::table('user_user_groups as uug')->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('ug.context_id', $this->contextId)->where('ug.role_id', Role::ROLE_ID_REVIEWER)
            ->where(fn ($q) => $q->whereNull('uug.date_end')->orWhere('uug.date_end', '>', now()))->distinct()->pluck('uug.user_id')->map(fn ($x) => (int) $x)->all();
        if (!$ids) return [];
        $set = [];
        foreach (DB::table('user_settings')->whereIn('user_id', $ids)->whereIn('setting_name', ['givenName', 'familyName', 'affiliation', 'orcid', 'country'])->get() as $r) {
            $set[(int) $r->user_id][$r->setting_name][$r->locale ?? ''] = (string) $r->setting_value;
        }
        $country = DB::table('users')->whereIn('user_id', $ids)->pluck('country', 'user_id')->all();
        $email = DB::table('users')->whereIn('user_id', $ids)->pluck('email', 'user_id')->all();
        $disabled = DB::table('users')->whereIn('user_id', $ids)->pluck('disabled', 'user_id')->all();
        $ints = [];
        foreach (DB::table('user_interests as ui')->join('controlled_vocab_entry_settings as s', 's.controlled_vocab_entry_id', '=', 'ui.controlled_vocab_entry_id')
            ->whereIn('ui.user_id', $ids)->where('s.setting_name', 'name')->get(['ui.user_id', 's.setting_value']) as $r) $ints[(int) $r->user_id][] = (string) $r->setting_value;

        $ra = DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $this->contextId)->whereIn('ra.reviewer_id', $ids)->where('ra.cancelled', 0)
            ->get(['ra.reviewer_id', 'ra.submission_id', 'ra.date_assigned', 'ra.date_notified', 'ra.date_confirmed', 'ra.date_completed', 'ra.date_due', 'ra.date_response_due', 'ra.declined', 'ra.quality', 'ra.recommendation'])
            ->groupBy('reviewer_id');
        $now = time(); $out = [];
        foreach ($ids as $id) {
            $pick = fn ($k) => $set[$id][$k][$locale ?? ''] ?? ($set[$id][$k]['ar'] ?? ($set[$id][$k]['en'] ?? (($tmp = ($set[$id][$k] ?? [])) ? (string) $tmp[array_key_first($tmp)] : '')));
            $rows = $ra->get($id, collect());
            $done = $rows->filter(fn ($r) => $r->date_completed && !$r->declined);
            $active = $rows->filter(fn ($r) => !$r->date_completed && !$r->declined && $r->date_confirmed);
            $pending = $rows->filter(fn ($r) => !$r->date_completed && !$r->declined && !$r->date_confirmed);
            $late = $rows->filter(fn ($r) => !$r->declined && (($r->date_completed && $r->date_due && strtotime($r->date_completed) > strtotime($r->date_due) + 86400)
                || (!$r->date_completed && $r->date_confirmed && $r->date_due && strtotime($r->date_due) < $now)));
            $days = $done->map(fn ($r) => max(0, (strtotime($r->date_completed) - strtotime($r->date_notified ?: $r->date_assigned)) / 86400))->values()->all();
            $q = $done->pluck('quality')->filter()->map(fn ($x) => (int) $x)->values()->all();
            $out[$id] = [
                'id' => $id, 'name' => trim($pick('givenName') . ' ' . $pick('familyName')) ?: '#' . $id, 'email' => (string) ($email[$id] ?? ''),
                'affiliation' => $pick('affiliation'), 'country' => (string) ($country[$id] ?? ''), 'orcid' => preg_replace('#^https?://orcid.org/#', '', $pick('orcid')),
                'interests' => array_values(array_unique($ints[$id] ?? [])), 'disabled' => (bool) ($disabled[$id] ?? false),
                'invited' => $rows->count(), 'declined' => $rows->filter(fn ($r) => (int) $r->declined)->count(), 'completed' => $done->count(),
                'active' => $active->count(), 'pending' => $pending->count(), 'late' => $late->count(),
                'avgDays' => $days ? (int) round(array_sum($days) / count($days)) : null, 'quality' => $q ? round(array_sum($q) / count($q), 1) : null,
                'last' => $rows->max('date_assigned') ? substr((string) $rows->max('date_assigned'), 0, 10) : null,
                'subs' => $rows->pluck('submission_id')->map(fn ($x) => (int) $x)->unique()->values()->all(),
                'doneSubs' => $done->pluck('submission_id')->map(fn ($x) => (int) $x)->unique()->values()->all(),
            ];
            $o = &$out[$id];
            $o['acceptRate'] = $o['invited'] ? (int) round(100 * ($o['invited'] - $o['declined']) / $o['invited']) : null;
            $o['onTime'] = $o['completed'] ? (int) round(100 * max(0, $o['completed'] - $done->filter(fn ($r) => $r->date_due && strtotime($r->date_completed) > strtotime($r->date_due) + 86400)->count()) / $o['completed']) : null;
            unset($o);
        }
        return $out;
    }

    /** كلمات البحوث (للخبرة المستمدة من سجل التحكيم) */
    private function keywordsOf(array $submissionIds): array
    {
        if (!$submissionIds) return [];
        $pubs = DB::table('submissions')->whereIn('submission_id', $submissionIds)->pluck('current_publication_id', 'submission_id')->all();
        $out = [];
        $rows = DB::table('controlled_vocabs as cv')->join('controlled_vocab_entries as e', 'e.controlled_vocab_id', '=', 'cv.controlled_vocab_id')
            ->join('controlled_vocab_entry_settings as s', 's.controlled_vocab_entry_id', '=', 'e.controlled_vocab_entry_id')
            ->where('cv.symbolic', 'submissionKeyword')->whereIn('cv.assoc_id', array_values($pubs))->where('s.setting_name', 'name')->get(['cv.assoc_id', 's.setting_value']);
        $bySub = array_flip($pubs);
        foreach ($rows as $r) $out[(int) ($bySub[(int) $r->assoc_id] ?? 0)][] = (string) $r->setting_value;
        return $out;
    }

    // ------------------------------------------------------------------ الاقتراح
    public function suggest(int $submissionId, int $limit = 12): array
    {
        $sub = Repo::submission()->get($submissionId);
        $pub = $sub->getCurrentPublication();
        $kw = [];
        foreach ((array) ($pub->getData('keywords') ?? []) as $list) foreach ((array) $list as $k) $kw[] = is_array($k) ? (string) ($k['name'] ?? '') : (string) $k;
        $kw = array_values(array_filter(array_unique($kw)));
        $titleTokens = [];
        foreach ((array) $pub->getData('title') as $t) $titleTokens = array_merge($titleTokens, self::tokens(strip_tags((string) $t)));
        foreach ((array) $pub->getData('abstract') as $t) $absTokens = array_merge($absTokens ?? [], self::tokens(strip_tags((string) $t)));
        $absTokens = array_count_values($absTokens ?? []);
        $kwNorm = array_map(fn ($k) => ['raw' => $k, 'n' => self::norm($k), 't' => self::tokens($k)], $kw);

        // المؤلفون
        $authors = iterator_to_array($pub->getData('authors') ?? []);
        $authorEmails = array_filter(array_map(fn ($a) => mb_strtolower((string) $a->getData('email')), $authors));
        $authorAff = [];
        foreach ($authors as $a) {
            foreach ((array) $a->getAffiliations() as $af) foreach ((array) $af->getName() as $n) if (trim((string) $n) !== '') $authorAff[] = self::norm((string) $n);
        }
        $authorAff = array_values(array_unique(array_filter($authorAff)));
        $submitterIds = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $submissionId)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->pluck('sa.user_id')->map(fn ($x) => (int) $x)->all();
        // من شارك أحد المؤلفين التأليف في المجلة (عبر البريد)
        $coauthorEmails = [];
        if ($authorEmails) {
            $pubIds = DB::table('authors as a')->join('publications as p', 'p.publication_id', '=', 'a.publication_id')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
                ->where('s.context_id', $this->contextId)->where('s.submission_id', '<>', $submissionId)->whereIn(DB::raw('LOWER(a.email)'), $authorEmails)->pluck('a.publication_id')->all();
            if ($pubIds) $coauthorEmails = DB::table('authors')->whereIn('publication_id', $pubIds)->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->unique()->all();
        }
        $assigned = DB::table('review_assignments')->where('submission_id', $submissionId)->where('cancelled', 0)->pluck('reviewer_id')->map(fn ($x) => (int) $x)->all();

        $revs = $this->reviewers();
        $history = $this->keywordsOf(array_values(array_unique(array_merge(...array_map(fn ($r) => $r['doneSubs'], array_values($revs)) ?: [[]]))));
        $res = [];
        foreach ($revs as $r) {
            if ($r['disabled']) continue;
            $score = 0.0; $why = [];
            $intNorm = array_map(fn ($i) => ['raw' => $i, 'n' => self::norm($i), 't' => self::tokens($i)], $r['interests']);
            foreach ($intNorm as $i) {
                foreach ($kwNorm as $k) {
                    if ($i['n'] !== '' && $i['n'] === $k['n']) { $score += 3; $why['kw'][] = $k['raw']; continue; }
                    $common = array_intersect($i['t'], $k['t']);
                    if ($common && count($common) / max(1, min(count($i['t']), count($k['t']))) >= 0.5) { $score += 2; $why['kw'][] = $k['raw']; }
                }
                $tc = array_intersect($i['t'], $titleTokens);
                if ($tc) { $score += min(2, count($tc)); $why['title'][] = $i['raw']; }
                foreach ($i['t'] as $tk) if (isset($absTokens[$tk])) { $score += 0.5; $why['abs'][] = $i['raw']; break; }
            }
            // خبرة من سجل التحكيم
            $hist = 0;
            foreach ($r['doneSubs'] as $ds) foreach ($history[$ds] ?? [] as $hk) {
                $ht = self::tokens($hk);
                foreach ($kwNorm as $k) if (array_intersect($ht, $k['t'])) { $hist++; break; }
            }
            if ($hist) { $score += min(3, $hist); $why['history'] = $hist; }
            $relevance = $score;
            // الموازنة
            if ($r['quality'] !== null) $score += ($r['quality'] - 3) * 0.6;
            if ($r['avgDays'] !== null) $score += $r['avgDays'] <= 21 ? 0.5 : ($r['avgDays'] > 35 ? -0.7 : 0);
            $load = $r['active'] + $r['pending'];
            if ($load >= 2) $score -= ($load - 1) * 1.0;
            if ($r['invited'] >= 3 && $r['acceptRate'] !== null && $r['acceptRate'] < 50) $score -= 1;
            if ($r['late'] >= 2) $score -= 0.7;
            // التضارب
            $coi = [];
            if (in_array($r['id'], $submitterIds, true) || in_array(mb_strtolower($r['email']), $authorEmails, true)) $coi[] = 'author';
            $ra = self::norm($r['affiliation']);
            if ($ra !== '') foreach ($authorAff as $aa) if ($aa !== '' && (str_contains($aa, $ra) || str_contains($ra, $aa))) { $coi[] = 'affiliation'; break; }
            if (in_array(mb_strtolower($r['email']), $coauthorEmails, true) && !in_array('author', $coi, true)) $coi[] = 'coauthor';
            if (in_array($r['id'], $assigned, true)) $coi[] = 'assigned';
            if ($relevance <= 0 && !$coi) continue;
            $res[] = $r + ['score' => round($score, 1), 'relevance' => round($relevance, 1), 'why' => array_map(fn ($v) => is_array($v) ? array_values(array_unique($v)) : $v, $why), 'coi' => $coi, 'load' => $load];
        }
        usort($res, fn ($a, $b) => [count($a['coi']) > 0, -$a['score']] <=> [count($b['coi']) > 0, -$b['score']]);
        $ok = array_values(array_filter($res, fn ($x) => !$x['coi']));
        $blocked = array_values(array_filter($res, fn ($x) => (bool) $x['coi']));
        return ['ok' => array_slice($ok, 0, $limit), 'blocked' => $blocked, 'keywords' => $kw, 'pool' => count($revs), 'authorAff' => $authorAff];
    }
}
