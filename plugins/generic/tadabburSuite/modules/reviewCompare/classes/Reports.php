<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewCompare/classes/Reports.php
 * جمع تقارير جولة وتحليل توافقها.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewCompare\classes;

use Illuminate\Support\Facades\DB;

class Reports
{
    /** درجة التوصية على مقياس 0..4 (قبول=4 … رفض=0)، و«انظر الملاحظات» بلا درجة */
    public const SCORE = [1 => 4, 2 => 3, 3 => 2, 4 => 1, 5 => 0];

    public static function rounds(int $cid, int $limit = 60): array
    {
        return DB::table('review_rounds as rr')->join('submissions as s', 's.submission_id', '=', 'rr.submission_id')->where('s.context_id', $cid)->whereIn('s.status', [1, 3, 4, 5])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('review_assignments as ra')->whereColumn('ra.review_round_id', 'rr.review_round_id')->whereNotNull('ra.date_completed'))
            ->orderByDesc('rr.review_round_id')->limit($limit)->select('rr.review_round_id', 'rr.submission_id', 'rr.round', 's.status')->get()->all();
    }

    public static function get(int $roundId): array
    {
        $out = [];
        $ras = DB::table('review_assignments')->where('review_round_id', $roundId)->where('declined', 0)->where('cancelled', 0)->orderBy('review_id')->get();
        foreach ($ras as $i => $ra) {
            $c = DB::table('submission_comments')->where('comment_type', 1)->where('assoc_id', $ra->review_id)->orderBy('comment_id')->get(['comments', 'viewable']);
            $resp = [];
            foreach (DB::table('review_form_responses')->where('review_id', $ra->review_id)->get() as $r) $resp[(int) $r->review_form_element_id] = [$r->response_type, $r->response_value];
            $days = $ra->date_completed && $ra->date_assigned ? max(0, (int) round((strtotime($ra->date_completed) - strtotime($ra->date_assigned)) / 86400)) : null;
            $out[] = ['i' => $i, 'id' => (int) $ra->review_id, 'reviewer' => (int) $ra->reviewer_id, 'done' => (bool) $ra->date_completed, 'rec' => $ra->recommendation === null ? null : (int) $ra->recommendation,
                'days' => $days, 'late' => $ra->date_completed && $ra->date_due && $ra->date_completed > $ra->date_due, 'quality' => $ra->quality ? (int) $ra->quality : null,
                'formId' => $ra->review_form_id ? (int) $ra->review_form_id : null, 'resp' => $resp,
                'toAuthor' => $c->where('viewable', 1)->pluck('comments')->all(), 'toEditor' => $c->where('viewable', 0)->pluck('comments')->all()];
        }
        return $out;
    }

    /** عناصر نموذج التحكيم: id => [question, type, options[]] */
    public static function form(int $formId, string $locale): array
    {
        $out = [];
        foreach (DB::table('review_form_elements')->where('review_form_id', $formId)->orderBy('seq')->get() as $e) {
            $s = DB::table('review_form_element_settings')->where('review_form_element_id', $e->review_form_element_id)->get();
            $pick = function ($name) use ($s, $locale) { $x = $s->where('setting_name', $name); return ($x->firstWhere('locale', $locale) ?? $x->first())?->setting_value; };
            $opts = @unserialize((string) $pick('possibleResponses'), ['allowed_classes' => false]);
            if (!is_array($opts)) $opts = json_decode((string) $pick('possibleResponses'), true) ?: [];
            $out[(int) $e->review_form_element_id] = ['q' => strip_tags((string) $pick('question')), 'type' => (int) $e->element_type, 'opts' => array_values(array_map(fn ($o) => is_array($o) ? (string) ($o['content'] ?? reset($o)) : (string) $o, $opts))];
        }
        return $out;
    }

    public static function answer(array $el, array $r): string
    {
        [$type, $val] = $r;
        if (in_array($el['type'], [4, 5, 6], true)) { // خيارات: مربعات، أزرار، قائمة
            $v = $type === 'object' ? (@unserialize((string) $val, ['allowed_classes' => false]) ?: json_decode((string) $val, true) ?: []) : [$val];
            return implode('، ', array_map(fn ($i) => $el['opts'][(int) $i] ?? (string) $i, (array) $v));
        }
        return strip_tags((string) $val);
    }

    /** توافق التوصيات: [spread|null, median|null, level] */
    public static function agreement(array $reports): array
    {
        $s = array_values(array_filter(array_map(fn ($r) => $r['done'] && $r['rec'] !== null ? (self::SCORE[$r['rec']] ?? null) : null, $reports), fn ($x) => $x !== null));
        if (count($s) < 2) return [null, $s[0] ?? null, 'insufficient'];
        sort($s); $spread = end($s) - $s[0]; $n = count($s);
        $med = $n % 2 ? $s[intdiv($n, 2)] : ($s[$n / 2 - 1] + $s[$n / 2]) / 2;
        return [$spread, $med, $spread === 0 ? 'consensus' : ($spread === 1 ? 'close' : 'divergent')];
    }

    public static function suggestion(?float $med): ?int
    {
        if ($med === null) return null;
        return $med >= 3.5 ? 1 : ($med >= 2.5 ? 2 : ($med >= 1.5 ? 3 : 5));
    }
}
