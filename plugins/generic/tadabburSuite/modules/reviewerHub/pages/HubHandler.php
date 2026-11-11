<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerHub/pages/HubHandler.php
 * /reviewerHub (الدليل)، /suggest?submissionId=، /profile?userId=، /csv، و POST: requestProfiles
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerHub\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\reviewerHub\classes\Hub;
use APP\plugins\generic\tadabburSuite\m\reviewerHub\ReviewerHubPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\mail\Mailable;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class HubHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;white-space:nowrap;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const TAG = 'display:inline-block;margin:1px;padding:0 7px;border-radius:9px;background:#eef2f6;color:#334;font-size:11px;';

    public function __construct(private ReviewerHubPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = [], ?string $l = null): string { return __('plugins.generic.reviewerHub.' . $k, $p, $l); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, ReviewerHubPlugin::PAGE, $op, null, $params);
    }
    private function gate($request, bool $manager = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!($manager ? $this->plugin->isManager($request) : $this->plugin->canView($request))) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }
    private function cid($request): int { return (int) $request->getContext()->getId(); }
    private function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }
    private function nav($request, string $cur): string
    {
        $h = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px">';
        foreach (['index' => 'nav.directory', 'suggest' => 'nav.suggest'] as $op => $k) $h .= '<a href="' . self::e($this->url($request, $op)) . '" style="' . ($op === $cur ? self::S_BTNP : self::S_BTN) . '">' . $this->t($k) . '</a>';
        return $h . '</div>';
    }
    private function country(string $c): string { return $c === '' ? '' : (Locale::getCountries()->getByAlpha2($c)?->getLocalName() ?? $c); }
    private function stars(?float $q): string { return $q === null ? '—' : '<span title="' . $q . '/5" style="color:#c08a1e;white-space:nowrap">' . str_repeat('★', (int) round($q)) . '<span style="color:#ccc">' . str_repeat('★', 5 - (int) round($q)) . '</span></span>'; }

    // ================================================================ الدليل
    public function index($args, $request)
    {
        $this->gate($request);
        $revs = (new Hub($this->cid($request)))->reviewers(Locale::getLocale());
        $q = trim((string) $request->getUserVar('q')); $cty = (string) $request->getUserVar('country'); $avail = (bool) $request->getUserVar('available');
        $sort = in_array($request->getUserVar('sort'), ['completed', 'quality', 'speed', 'load', 'name'], true) ? $request->getUserVar('sort') : 'completed';
        $nq = Hub::norm($q);
        $list = array_values(array_filter($revs, fn ($r) => !$r['disabled'] && ($q === '' || str_contains(Hub::norm($r['name'] . ' ' . $r['affiliation'] . ' ' . implode(' ', $r['interests'])), $nq))
            && ($cty === '' || $r['country'] === $cty) && (!$avail || ($r['active'] + $r['pending']) === 0)));
        usort($list, fn ($a, $b) => match ($sort) {
            'quality' => ($b['quality'] ?? -1) <=> ($a['quality'] ?? -1), 'speed' => ($a['avgDays'] ?? 999) <=> ($b['avgDays'] ?? 999),
            'load' => ($a['active'] + $a['pending']) <=> ($b['active'] + $b['pending']), 'name' => strcmp($a['name'], $b['name']), default => $b['completed'] <=> $a['completed'] });
        $all = array_values($revs);
        $noInt = count(array_filter($all, fn ($r) => !$r['interests']));
        $days = array_filter(array_column($all, 'avgDays'), fn ($x) => $x !== null);
        $countries = array_unique(array_filter(array_column($all, 'country')));
        $card = fn ($v, $l, $col) => '<div style="flex:1;min-width:140px;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:24px;font-weight:700;color:' . $col . '">' . $v . '</div><div style="font-size:13px">' . $l . '</div></div>';
        $h = $this->nav($request, 'index');
        if ($request->getUserVar('sent') !== null) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t('requestSent', ['n' => (int) $request->getUserVar('sent')]) . '</b></div>';
        $h .= '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' . $card(count($all), $this->t('k.reviewers'), '#0b4f4a') . $card(count($countries), $this->t('k.countries'), '#33658a')
            . $card(array_sum(array_column($all, 'active')) + array_sum(array_column($all, 'pending')), $this->t('k.active'), '#6b4fa0')
            . $card($days ? $this->t('daysN', ['n' => (int) round(array_sum($days) / count($days))]) : '—', $this->t('k.turnaround'), '#8a6d3b')
            . $card($noInt, $this->t('k.noInterests'), $noInt ? '#a86a00' : '#1e7b4a') . '</div>';
        if ($noInt && $this->plugin->isManager($request)) {
            $h .= '<div style="' . self::S_BOX . 'border-color:#a86a00;background:#fffaf0;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><span>' . $this->t('incompleteNote', ['n' => $noInt]) . '</span>'
                . '<form method="post" action="' . self::e($this->url($request, 'requestProfiles')) . '" style="margin:0"><input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '"><button style="' . self::S_BTNP . '">✉ ' . $this->t('requestBtn') . '</button></form></div>';
        }
        $copts = '<option value="">' . $this->t('allCountries') . '</option>';
        foreach ($countries as $c) $copts .= '<option value="' . self::e($c) . '"' . ($c === $cty ? ' selected' : '') . '>' . self::e($this->country($c)) . '</option>';
        $sopts = '';
        foreach (['completed', 'quality', 'speed', 'load', 'name'] as $s) $sopts .= '<option value="' . $s . '"' . ($s === $sort ? ' selected' : '') . '>' . $this->t('sort.' . $s) . '</option>';
        $h .= '<div style="' . self::S_BOX . '"><form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px">'
            . '<input name="q" value="' . self::e($q) . '" placeholder="' . self::e($this->t('search')) . '" style="' . self::S_INP . 'width:240px"><select name="country" style="' . self::S_INP . '">' . $copts . '</select>'
            . '<select name="sort" style="' . self::S_INP . '">' . $sopts . '</select><label style="font-size:13px"><input type="checkbox" name="available" value="1"' . ($avail ? ' checked' : '') . '> ' . $this->t('availableOnly') . '</label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('filter') . '</button><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'csv')) . '">CSV</a></form>';
        $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">' . $this->t('col.reviewer') . '</th><th style="' . self::S_TH . '">' . $this->t('col.interests') . '</th>'
            . '<th style="' . self::S_TH . '">' . $this->t('col.completed') . '</th><th style="' . self::S_TH . '">' . $this->t('col.acceptRate') . '</th><th style="' . self::S_TH . '">' . $this->t('col.avgDays') . '</th><th style="' . self::S_TH . '">' . $this->t('col.onTime') . '</th>'
            . '<th style="' . self::S_TH . '">' . $this->t('col.quality') . '</th><th style="' . self::S_TH . '">' . $this->t('col.load') . '</th><th style="' . self::S_TH . '">' . $this->t('col.last') . '</th></tr></thead><tbody>';
        foreach ($list as $r) {
            $ints = $r['interests'] ? implode('', array_map(fn ($i) => '<span style="' . self::TAG . '">' . self::e($i) . '</span>', array_slice($r['interests'], 0, 6))) : '<span style="color:#a86a00;font-size:12px">' . $this->t('noInterests') . '</span>';
            $load = $r['active'] + $r['pending'];
            $h .= '<tr><td style="' . self::S_TD . 'min-width:190px"><a href="' . self::e($this->url($request, 'profile', ['userId' => $r['id']])) . '"><b>' . self::e($r['name']) . '</b></a>'
                . '<div style="font-size:11px;color:#666">' . self::e($r['affiliation']) . ($r['country'] ? ' · ' . self::e($this->country($r['country'])) : '') . '</div>'
                . ($r['orcid'] ? '<div style="font-size:11px" dir="ltr"><a href="https://orcid.org/' . self::e($r['orcid']) . '" target="_blank" rel="noopener" style="color:#a6ce39">iD</a> ' . self::e($r['orcid']) . '</div>' : '') . '</td>'
                . '<td style="' . self::S_TD . '">' . $ints . '</td><td style="' . self::S_TD . '">' . $r['completed'] . '<span style="color:#888;font-size:11px"> / ' . $r['invited'] . '</span></td>'
                . '<td style="' . self::S_TD . '">' . ($r['acceptRate'] === null ? '—' : $r['acceptRate'] . '%') . '</td><td style="' . self::S_TD . '">' . ($r['avgDays'] === null ? '—' : $this->t('daysN', ['n' => $r['avgDays']])) . '</td>'
                . '<td style="' . self::S_TD . '">' . ($r['onTime'] === null ? '—' : $r['onTime'] . '%') . '</td><td style="' . self::S_TD . '">' . $this->stars($r['quality']) . '</td>'
                . '<td style="' . self::S_TD . ($load >= 2 ? 'color:#b02a2a;font-weight:700' : '') . '">' . $load . ($r['late'] ? ' <span style="color:#b02a2a;font-size:11px">(' . $this->t('lateN', ['n' => $r['late']]) . ')</span>' : '') . '</td>'
                . '<td style="' . self::S_TD . 'white-space:nowrap">' . self::e($r['last'] ?? '—') . '</td></tr>';
        }
        $h .= '</tbody></table></div><div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('dirNote') . '</div></div>';
        $this->show($request, $this->t('menu'), $h);
    }

    // ================================================================ الاقتراح
    public function suggest($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request);
        $sid = (int) $request->getUserVar('submissionId');
        $subs = DB::table('submissions as s')->join('publication_settings as ps', fn ($j) => $j->on('ps.publication_id', '=', 's.current_publication_id')->where('ps.setting_name', 'title'))
            ->where('s.context_id', $cid)->where('s.status', 1)->whereIn('s.stage_id', [1, 3])->where(fn ($q) => $q->whereNull('s.submission_progress')->orWhere('s.submission_progress', ''))
            ->orderBy('s.submission_id', 'desc')->get(['s.submission_id', 'ps.locale', 'ps.setting_value'])->groupBy('submission_id');
        $opts = '<option value="">' . $this->t('pickSubmission') . '</option>';
        foreach ($subs as $id => $ts) { $t = $ts->firstWhere('locale', Locale::getLocale()) ?? $ts->first(); $opts .= '<option value="' . (int) $id . '"' . ((int) $id === $sid ? ' selected' : '') . '>#' . (int) $id . ' — ' . self::e(mb_strimwidth(strip_tags((string) $t->setting_value), 0, 90, '…')) . '</option>'; }
        $h = $this->nav($request, 'suggest') . '<div style="' . self::S_BOX . '"><p style="margin-top:0;color:#444">' . $this->t('suggestIntro') . '</p><form method="get" action="' . self::e($this->url($request, 'suggest')) . '" style="display:flex;gap:6px;flex-wrap:wrap">'
            . '<select name="submissionId" style="' . self::S_INP . 'min-width:420px">' . $opts . '</select><button style="' . self::S_BTNP . '">' . $this->t('suggestBtn') . '</button></form></div>';
        $sub = $sid ? Repo::submission()->get($sid) : null;
        if ($sub && (int) $sub->getData('contextId') === $cid) {
            $res = (new Hub($cid))->suggest($sid);
            $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $sid]);
            $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><div><b>' . self::e(strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle())) . '</b>'
                . '<div style="margin-top:4px">' . ($res['keywords'] ? implode('', array_map(fn ($k) => '<span style="' . self::TAG . 'background:#e8f4f2">' . self::e($k) . '</span>', $res['keywords'])) : '<span style="color:#a86a00;font-size:12px">' . $this->t('noKeywords') . '</span>') . '</div></div>'
                . '<a style="' . self::S_BTN . '" href="' . self::e($wf) . '">' . $this->t('openWorkflow') . '</a></div></div>';
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('suggested', ['n' => count($res['ok']), 'p' => $res['pool']]) . '</h2>';
            if (!$res['ok']) $h .= '<p>' . $this->t('noMatch') . '</p>';
            else {
                $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.reviewer') . '</th><th style="' . self::S_TH . '">' . $this->t('col.why') . '</th><th style="' . self::S_TH . '">' . $this->t('col.score') . '</th><th style="' . self::S_TH . '">' . $this->t('col.record') . '</th><th style="' . self::S_TH . '">' . $this->t('col.load') . '</th></tr></thead><tbody>';
                foreach ($res['ok'] as $i => $r) {
                    $why = '';
                    if (!empty($r['why']['kw'])) $why .= '<div>' . $this->t('why.kw') . ': ' . implode('', array_map(fn ($k) => '<span style="' . self::TAG . 'background:#e8f4f2">' . self::e($k) . '</span>', $r['why']['kw'])) . '</div>';
                    if (!empty($r['why']['title'])) $why .= '<div style="font-size:12px">' . $this->t('why.title') . ': ' . self::e(implode('، ', $r['why']['title'])) . '</div>';
                    if (!empty($r['why']['abs'])) $why .= '<div style="font-size:12px">' . $this->t('why.abs') . ': ' . self::e(implode('، ', $r['why']['abs'])) . '</div>';
                    if (!empty($r['why']['history'])) $why .= '<div style="font-size:12px;color:#1e7b4a">' . $this->t('why.history', ['n' => $r['why']['history']]) . '</div>';
                    $load = $r['load'];
                    $h .= '<tr><td style="' . self::S_TD . '">' . ($i + 1) . '</td><td style="' . self::S_TD . 'min-width:180px"><a href="' . self::e($this->url($request, 'profile', ['userId' => $r['id']])) . '"><b>' . self::e($r['name']) . '</b></a><div style="font-size:11px;color:#666">' . self::e($r['affiliation']) . ($r['country'] ? ' · ' . self::e($this->country($r['country'])) : '') . '</div></td>'
                        . '<td style="' . self::S_TD . '">' . $why . '</td><td style="' . self::S_TD . '"><b style="font-size:16px;color:#0b4f4a">' . $r['score'] . '</b><div style="font-size:11px;color:#777">' . $this->t('relevance', ['n' => $r['relevance']]) . '</div></td>'
                        . '<td style="' . self::S_TD . 'font-size:12px">' . $this->t('record', ['c' => $r['completed'], 'd' => $r['avgDays'] ?? '—', 'a' => $r['acceptRate'] ?? '—']) . '<div>' . $this->stars($r['quality']) . '</div></td>'
                        . '<td style="' . self::S_TD . ($load >= 2 ? 'color:#b02a2a;font-weight:700' : '') . '">' . $load . '</td></tr>';
                }
                $h .= '</tbody></table></div>';
            }
            $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('assignNote') . '</div></div>';
            if ($res['blocked']) {
                $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a"><h2 style="margin:0 0 8px;font-size:16px;color:#b02a2a">' . $this->t('excluded', ['n' => count($res['blocked'])]) . '</h2><ul style="margin:0">';
                foreach ($res['blocked'] as $r) $h .= '<li><b>' . self::e($r['name']) . '</b>' . ($r['affiliation'] !== '' ? ' <span style="font-size:12px;color:#666">(' . self::e($r['affiliation']) . ')</span>' : '') . ' — ' . implode('، ', array_map(fn ($c) => '<span style="color:#b02a2a">' . $this->t('coi.' . $c) . '</span>', $r['coi'])) . '</li>';
                $h .= '</ul></div>';
            }
        }
        $this->show($request, $this->t('suggestTitle'), $h);
    }

    // ================================================================ ملف المحكّم
    public function profile($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request); $uid = (int) $request->getUserVar('userId');
        $revs = (new Hub($cid))->reviewers(Locale::getLocale());
        $r = $revs[$uid] ?? null;
        if (!$r) { http_response_code(404); echo self::e($this->t('notFound')); exit; }
        $rows = DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->leftJoin('publication_settings as ps', fn ($j) => $j->on('ps.publication_id', '=', 's.current_publication_id')->where('ps.setting_name', 'title')->where('ps.locale', Locale::getLocale()))
            ->where('s.context_id', $cid)->where('ra.reviewer_id', $uid)->where('ra.cancelled', 0)->orderBy('ra.date_assigned', 'desc')
            ->get(['ra.submission_id', 'ra.round', 'ra.date_assigned', 'ra.date_notified', 'ra.date_confirmed', 'ra.date_completed', 'ra.date_due', 'ra.declined', 'ra.quality', 'ra.recommendation', 'ps.setting_value']);
        $h = $this->nav($request, '') . '<div style="' . self::S_BOX . '"><h2 style="margin:0;font-size:19px">' . self::e($r['name']) . '</h2><div style="color:#555">' . self::e($r['affiliation']) . ($r['country'] ? ' · ' . self::e($this->country($r['country'])) : '') . '</div>'
            . ($r['orcid'] ? '<div dir="ltr" style="text-align:start"><a href="https://orcid.org/' . self::e($r['orcid']) . '" target="_blank" rel="noopener">https://orcid.org/' . self::e($r['orcid']) . '</a></div>' : '')
            . '<div style="margin-top:6px">' . implode('', array_map(fn ($i) => '<span style="' . self::TAG . '">' . self::e($i) . '</span>', $r['interests'])) . '</div>'
            . '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;font-size:13px"><span>' . $this->t('col.completed') . ': <b>' . $r['completed'] . '/' . $r['invited'] . '</b></span><span>' . $this->t('col.acceptRate') . ': <b>' . ($r['acceptRate'] ?? '—') . '%</b></span>'
            . '<span>' . $this->t('col.avgDays') . ': <b>' . ($r['avgDays'] ?? '—') . '</b></span><span>' . $this->t('col.onTime') . ': <b>' . ($r['onTime'] ?? '—') . '%</b></span><span>' . $this->t('col.quality') . ': ' . $this->stars($r['quality']) . '</span></div></div>';
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('history') . '</h2><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.submission') . '</th><th style="' . self::S_TH . '">' . $this->t('col.invited') . '</th><th style="' . self::S_TH . '">' . $this->t('col.status') . '</th><th style="' . self::S_TH . '">' . $this->t('col.days') . '</th><th style="' . self::S_TH . '">' . $this->t('col.recommendation') . '</th><th style="' . self::S_TH . '">' . $this->t('col.quality') . '</th></tr></thead><tbody>';
        foreach ($rows as $x) {
            $st = (int) $x->declined ? 'declined' : ($x->date_completed ? 'completed' : ($x->date_confirmed ? 'accepted' : 'pending'));
            $days = $x->date_completed ? (int) round((strtotime($x->date_completed) - strtotime($x->date_notified ?: $x->date_assigned)) / 86400) : null;
            $h .= '<tr><td style="' . self::S_TD . '">' . (int) $x->submission_id . '</td><td style="' . self::S_TD . '">' . self::e(mb_strimwidth(strip_tags((string) $x->setting_value), 0, 90, '…')) . ' <span style="font-size:11px;color:#777">(' . $this->t('roundN', ['n' => (int) $x->round]) . ')</span></td>'
                . '<td style="' . self::S_TD . '">' . self::e(substr((string) $x->date_assigned, 0, 10)) . '</td><td style="' . self::S_TD . '">' . $this->t('st.' . $st) . '</td><td style="' . self::S_TD . '">' . ($days ?? '—') . '</td>'
                . '<td style="' . self::S_TD . '">' . ($x->recommendation ? self::e($this->t('rec.' . (int) $x->recommendation)) : '—') . '</td><td style="' . self::S_TD . '">' . $this->stars($x->quality ? (float) $x->quality : null) . '</td></tr>';
        }
        $h .= '</tbody></table></div></div>';
        $this->show($request, $this->t('profileTitle'), $h);
    }

    // ================================================================ طلب تحديث الملفات
    public function requestProfiles($args, $request)
    {
        $this->gate($request, true);
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); exit; }
        $context = $request->getContext(); $n = 0;
        $profileUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'user', 'profile', ['roles']);
        foreach ((new Hub($this->cid($request)))->reviewers() as $r) {
            if ($r['interests'] || $r['disabled'] || $r['email'] === '') continue;
            $p = ['name' => $r['name'], 'journal' => $context->getLocalizedName(), 'url' => $profileUrl];
            $m = new Mailable([$context]);
            $m->from((string) $context->getData('contactEmail'), (string) $context->getData('contactName'))->to($r['email'], $r['name'])
                ->subject($this->t('mail.subject', $p, 'ar') . ' | ' . $this->t('mail.subject', $p, 'en'))
                ->body('<div dir="auto" style="font-family:Tahoma,Arial,sans-serif;line-height:1.7">' . nl2br(self::e($this->t('mail.body', $p, 'ar') . "\n\n— — —\n\n" . $this->t('mail.body', $p, 'en'))) . '</div>');
            Mail::send($m); $n++;
        }
        $request->redirectUrl($this->url($request, 'index', ['sent' => $n]));
    }

    public function csv($args, $request)
    {
        $this->gate($request);
        $revs = (new Hub($this->cid($request)))->reviewers(Locale::getLocale());
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reviewers-' . date('Ymd') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['ID', 'Name', 'Affiliation', 'Country', 'ORCID', 'Interests', 'Invited', 'Declined', 'Completed', 'Accept rate %', 'Avg days', 'On time %', 'Quality', 'Active load', 'Late', 'Last invited']);
        foreach ($revs as $r) fputcsv($o, [$r['id'], $r['name'], $r['affiliation'], $r['country'], $r['orcid'], implode('; ', $r['interests']), $r['invited'], $r['declined'], $r['completed'], $r['acceptRate'], $r['avgDays'], $r['onTime'], $r['quality'], $r['active'] + $r['pending'], $r['late'], $r['last']]);
        fclose($o); exit;
    }
}
