<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialTransparency/classes/StatsBuilder.php
 *
 * يحسب مؤشرات الشفافية التحريرية من بيانات OJS نفسها:
 *  - مؤشرات القرار من خدمة الإحصاءات التحريرية في OJS (editorialStats) حتى تطابق أرقام لوحة OJS.
 *  - الوسيط والمئين 80 لأزمنة القرار، وزمن التحكيم، والقبول→النشر، والإنتاج السنوي، والتوزيع الجغرافي، والقراءات والتنزيلات.
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialTransparency\classes;

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use PKP\decision\Decision;
use PKP\facades\Locale;

class StatsBuilder
{
    public function __construct(private int $contextId) {}

    public static function median(array $v): ?float
    {
        $v = array_values(array_filter($v, fn ($x) => $x !== null && $x >= 0));
        if (!$v) return null;
        sort($v); $n = count($v); $m = intdiv($n, 2);
        return $n % 2 ? (float) $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }

    public static function pct(array $v, float $p): ?float
    {
        $v = array_values(array_filter($v, fn ($x) => $x !== null && $x >= 0));
        if (!$v) return null;
        sort($v);
        return (float) $v[(int) max(0, ceil(count($v) * $p) - 1)];
    }

    private static function days(?string $a, ?string $b): ?float
    {
        if (!$a || !$b) return null;
        return round((strtotime($b) - strtotime($a)) / 86400, 1);
    }

    /** @param array{start?:string,end?:string} $period */
    public function build(array $period, string $locale): array
    {
        $args = ['contextIds' => [$this->contextId]];
        if (!empty($period['start'])) $args['dateStart'] = $period['start'];
        if (!empty($period['end'])) $args['dateEnd'] = $period['end'];
        $svc = app()->get('editorialStats');
        $ov = [];
        foreach ($svc->getOverview($args) as $row) $ov[$row['key']] = $row['value'];

        $firstDays = array_map('floatval', $svc->getDaysToDecisions([], $args));
        $acceptDays = array_map('floatval', $svc->getDaysToDecisions([Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW, Decision::SEND_TO_PRODUCTION], $args));
        $declineDays = array_map('floatval', $svc->getDaysToDecisions([Decision::DECLINE, Decision::INITIAL_DECLINE], $args));

        $start = $period['start'] ?? '1900-01-01'; $end = ($period['end'] ?? '2999-12-31') . ' 23:59:59';

        // ---- منشورات الفترة: القبول→النشر والتقديم→النشر
        $pubs = DB::table('publications as p')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $this->contextId)->where('p.status', 3)->where('p.version', 1)
            ->whereBetween('p.date_published', [$start, $end])
            ->select('p.publication_id', 's.submission_id', 's.date_submitted', 'p.date_published')->get();
        $acceptDates = $pubs->count() ? DB::table('edit_decisions')->whereIn('submission_id', $pubs->pluck('submission_id')->all())
            ->whereIn('decision', [Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW])->groupBy('submission_id')
            ->selectRaw('submission_id, MIN(date_decided) as d')->pluck('d', 'submission_id')->all() : [];
        $accToPub = []; $subToPub = [];
        foreach ($pubs as $p) {
            if (isset($acceptDates[$p->submission_id])) $accToPub[] = self::days($acceptDates[$p->submission_id], $p->date_published);
            $subToPub[] = self::days($p->date_submitted, $p->date_published);
        }

        // ---- التحكيم
        $reviews = DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $this->contextId)->where('ra.cancelled', 0)
            ->whereBetween('ra.date_assigned', [$start, $end])
            ->select('ra.submission_id', 'ra.reviewer_id', 'ra.date_assigned', 'ra.date_notified', 'ra.date_confirmed', 'ra.date_completed', 'ra.declined')->get();
        $completed = $reviews->filter(fn ($r) => $r->date_completed && !$r->declined);
        $turn = $completed->map(fn ($r) => self::days($r->date_notified ?: $r->date_assigned, $r->date_completed))->all();
        $invited = $reviews->count(); $declinedInv = $reviews->filter(fn ($r) => (int) $r->declined)->count();
        $perSub = $completed->groupBy('submission_id')->map->count()->values()->all();
        $reviewerIds = $completed->pluck('reviewer_id')->unique()->values()->all();
        $revCountries = $reviewerIds ? DB::table('users')->whereIn('user_id', $reviewerIds)->whereNotNull('country')->where('country', '<>', '')->distinct()->count('country') : 0;

        // ---- الجغرافيا: دول مؤلفي بحوث الفترة المنشورة
        $countries = [];
        if ($pubs->count()) {
            $rows = DB::table('author_settings as st')->join('authors as a', 'a.author_id', '=', 'st.author_id')
                ->whereIn('a.publication_id', $pubs->pluck('publication_id')->all())->where('st.setting_name', 'country')
                ->where('st.setting_value', '<>', '')->pluck('st.setting_value')->all();
            $all = Locale::getCountries($locale);
            foreach (array_count_values($rows) as $code => $n) {
                $c = $all->getByAlpha2($code);
                $countries[] = ['code' => $code, 'name' => $c ? $c->getLocalName() : $code, 'count' => $n];
            }
            usort($countries, fn ($a, $b) => $b['count'] <=> $a['count']);
        }

        // ---- القراءات والتنزيلات
        $views = $downloads = null;
        try {
            $m = DB::table('metrics_submission')->where('context_id', $this->contextId)->whereBetween('date', [substr($start, 0, 10), substr($end, 0, 10)])
                ->selectRaw('assoc_type, SUM(metric) as total')->groupBy('assoc_type')->pluck('total', 'assoc_type')->all();
            $views = (int) ($m[Application::ASSOC_TYPE_SUBMISSION] ?? 0);
            $downloads = (int) ($m[Application::ASSOC_TYPE_SUBMISSION_FILE] ?? 0);
        } catch (\Throwable $e) {
        }

        $issues = DB::table('issues')->where('journal_id', $this->contextId)->where('published', 1)->whereBetween('date_published', [$start, $end])->count();

        return [
            'received' => (int) ($ov['submissionsReceived'] ?? 0),
            'accepted' => (int) ($ov['submissionsAccepted'] ?? 0),
            'declined' => (int) ($ov['submissionsDeclined'] ?? 0),
            'declinedDesk' => (int) ($ov['submissionsDeclinedDeskReject'] ?? 0),
            'declinedReview' => (int) ($ov['submissionsDeclinedPostReview'] ?? 0),
            'acceptanceRate' => (float) ($ov['acceptanceRate'] ?? 0),
            'declineRate' => (float) ($ov['declineRate'] ?? 0),
            'deskRate' => (float) ($ov['declinedDeskRate'] ?? 0),
            'reviewRejectRate' => (float) ($ov['declinedReviewRate'] ?? 0),
            'daysFirstMedian' => self::median($firstDays), 'daysFirstP80' => self::pct($firstDays, 0.8),
            'daysAcceptMedian' => self::median($acceptDays), 'daysAcceptP80' => self::pct($acceptDays, 0.8),
            'daysDeclineMedian' => self::median($declineDays),
            'daysAcceptToPubMedian' => self::median($accToPub), 'daysSubToPubMedian' => self::median($subToPub),
            'published' => $pubs->count(), 'issues' => $issues,
            'reviewsCompleted' => $completed->count(), 'reviewTurnMedian' => self::median($turn), 'reviewTurnP80' => self::pct($turn, 0.8),
            'reviewers' => count($reviewerIds), 'reviewerCountries' => $revCountries,
            'reviewsPerArticle' => $perSub ? round(array_sum($perSub) / count($perSub), 1) : null,
            'invitationAcceptRate' => $invited ? round(($invited - $declinedInv) / $invited, 3) : null,
            'countries' => $countries, 'countryCount' => count($countries),
            'views' => $views, 'downloads' => $downloads,
        ];
    }

    /** اتجاهات سنوية */
    public function trend(int $years, string $locale): array
    {
        $out = []; $y = (int) date('Y');
        for ($i = $years - 1; $i >= 0; $i--) {
            $yy = $y - $i;
            $s = $this->build(['start' => "$yy-01-01", 'end' => "$yy-12-31"], $locale);
            $out[] = ['year' => $yy, 'received' => $s['received'], 'accepted' => $s['accepted'], 'declined' => $s['declined'], 'published' => $s['published'],
                'acceptanceRate' => $s['acceptanceRate'], 'daysFirstMedian' => $s['daysFirstMedian']];
        }
        return $out;
    }
}
