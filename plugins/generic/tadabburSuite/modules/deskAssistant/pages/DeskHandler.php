<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/deskAssistant/pages/DeskHandler.php
 * /deskAssistant (البحوث الجديدة)، /view?submissionId=، و POST: analyze، saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\deskAssistant\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\deskAssistant\classes\Analyzer;
use APP\plugins\generic\tadabburSuite\m\deskAssistant\classes\Signals;
use APP\plugins\generic\tadabburSuite\m\deskAssistant\DeskAssistantPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class DeskHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;white-space:nowrap;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const LVL = ['high' => '#1e7b4a', 'medium' => '#a86a00', 'low' => '#b02a2a'];
    private const REC = ['review' => '#1e7b4a', 'revise_first' => '#a86a00', 'desk_reject' => '#b02a2a', 'check_duplicate' => '#6b4fa0', 'consider_reject' => '#b02a2a'];

    public function __construct(private DeskAssistantPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.deskAssistant.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, DeskAssistantPlugin::PAGE, $op, null, $params);
    }
    private function gate($request, bool $manager = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!($manager ? $this->plugin->isManager($request) : $this->plugin->canView($request))) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }
    private function post($request, bool $manager = false): void
    {
        $this->gate($request, $manager);
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; }
    }
    private function csrf($request): string { return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'; }
    private function cid($request): int { return (int) $request->getContext()->getId(); }
    private function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }
    private function badge(string $text, string $col): string
    {
        return '<span style="display:inline-block;padding:1px 9px;border-radius:10px;background:' . $col . ';color:#fff;font-weight:700;font-size:12px;white-space:nowrap">' . self::e($text) . '</span>';
    }

    /** توصية مبدئية بالقواعد */
    private function rule(array $sig): array
    {
        $why = [];
        if (array_filter($sig['duplicates'], fn ($d) => $d['sim'] >= 0.9)) return ['check_duplicate', [$this->t('r.dup')]];
        $rec = 'review';
        if (($sig['checks']['preReview']['req'] ?? 0) > 0) { $rec = 'revise_first'; $why[] = $this->t('r.technical', ['n' => $sig['checks']['preReview']['req']]); }
        if (($sig['checks']['quran']['problems'] ?? 0) >= 5) { $rec = 'revise_first'; $why[] = $this->t('r.quran', ['n' => $sig['checks']['quran']['problems']]); }
        if ($sig['fitLevel'] === 'low' && $sig['corpusSize'] >= 5) { $rec = $rec === 'review' ? 'consider_reject' : $rec; $why[] = $this->t('r.fit'); }
        if (!$why) $why[] = $this->t('r.ok');
        return [$rec, $why];
    }

    // ================================================================ القائمة
    public function index($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request); $locale = Locale::getLocale();
        $sids = DB::table('submissions')->where('context_id', $cid)->where('status', 1)->where('stage_id', 1)
            ->where(fn ($q) => $q->whereNull('submission_progress')->orWhere('submission_progress', ''))->orderBy('date_submitted', 'desc')->limit(40)->pluck('submission_id')->map(fn ($x) => (int) $x)->all();
        $ai = Analyzer::latestFor($cid, $sids);
        $sig = new Signals($cid); $cfg = $this->plugin->cfg($request->getContext());
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p><div style="' . self::S_BOX . '"><h2 style="margin:0 0 10px;font-size:17px">' . $this->t('queue', ['n' => count($sids)]) . '</h2>';
        if (!$sids) $h .= '<p>' . $this->t('none') . '</p>';
        else {
            $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th><th style="' . self::S_TH . '">' . $this->t('col.submitted') . '</th><th style="' . self::S_TH . '">' . $this->t('col.fit') . '</th><th style="' . self::S_TH . '">' . $this->t('col.rule') . '</th><th style="' . self::S_TH . '">' . $this->t('col.ai') . '</th><th style="' . self::S_TH . '"></th></tr></thead><tbody>';
            foreach ($sids as $sid) {
                $sub = Repo::submission()->get($sid); if (!$sub) continue;
                $s = $sig->compute($sub, $cfg['scope'], $locale);
                [$rec] = $this->rule($s);
                $a = $ai[$sid] ?? null;
                $h .= '<tr><td style="' . self::S_TD . '">' . $sid . '</td><td style="' . self::S_TD . '"><b>' . self::e(strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle())) . '</b><div style="font-size:11px;color:#777">' . self::e(strip_tags((string) $sub->getCurrentPublication()->getShortAuthorString())) . '</div></td>'
                    . '<td style="' . self::S_TD . 'white-space:nowrap">' . self::e(substr((string) $sub->getData('dateSubmitted'), 0, 10)) . '</td>'
                    . '<td style="' . self::S_TD . '">' . $this->badge($s['fit'] . '% · ' . $this->t('lvl.' . $s['fitLevel']), self::LVL[$s['fitLevel']]) . '</td>'
                    . '<td style="' . self::S_TD . '">' . $this->badge($this->t('rec.' . $rec), self::REC[$rec]) . '</td>'
                    . '<td style="' . self::S_TD . '">' . ($a ? $this->badge($this->t('rec.' . $a['decision']), self::REC[$a['decision']]) : '<span style="color:#888;font-size:12px">—</span>') . '</td>'
                    . '<td style="' . self::S_TD . '"><a style="' . self::S_BTNP . '" href="' . self::e($this->url($request, 'view', ['submissionId' => $sid])) . '">' . $this->t('openCard') . '</a></td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '</div>';
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request, $cfg);
        $this->show($request, $this->t('menu'), $h);
    }

    // ================================================================ بطاقة القرار
    public function view($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request); $context = $request->getContext(); $cfg = $this->plugin->cfg($context);
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $cid) { http_response_code(404); echo self::e($this->t('notFound')); exit; }
        $pub = $sub->getCurrentPublication(); $sid = (int) $sub->getId();
        $s = (new Signals($cid))->compute($sub, $cfg['scope'], Locale::getLocale());
        [$rec, $why] = $this->rule($s);
        $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $sid]);
        $h = '<div style="margin-bottom:12px"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a> <a style="' . self::S_BTNP . '" href="' . self::e($wf) . '">' . $this->t('openWorkflow') . '</a></div>';
        if ($request->getUserVar('err')) $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1"><b>' . $this->t('aiError') . '</b> ' . self::e((string) $request->getUserVar('err')) . '</div>';

        // ---- رأس البطاقة
        $kw = [];
        foreach ((array) ($pub->getData('keywords') ?? []) as $l) foreach ((array) $l as $k) $kw[] = is_array($k) ? (string) ($k['name'] ?? '') : (string) $k;
        $abs = trim(html_entity_decode(strip_tags((string) $pub->getLocalizedData('abstract')), ENT_QUOTES, 'UTF-8'));
        $h .= '<div style="' . self::S_BOX . '"><div style="font-size:12px;color:#666">#' . $sid . ' · ' . self::e(substr((string) $sub->getData('dateSubmitted'), 0, 10)) . ' · ' . self::e(strip_tags((string) $pub->getShortAuthorString())) . '</div>'
            . '<h2 style="margin:4px 0 8px;font-size:18px">' . self::e(strip_tags((string) $pub->getLocalizedFullTitle())) . '</h2>'
            . '<div style="color:#333;max-height:9.5em;overflow:auto;line-height:1.7">' . self::e($abs) . '</div>'
            . '<div style="margin-top:6px">' . implode('', array_map(fn ($k) => '<span style="display:inline-block;margin:1px;padding:0 7px;border-radius:9px;background:#e8f4f2;font-size:11px">' . self::e($k) . '</span>', array_unique(array_filter($kw)))) . '</div></div>';

        // ---- التوصية المبدئية والمؤشرات
        $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">';
        $h .= '<div style="' . self::S_BOX . 'border-top:4px solid ' . self::REC[$rec] . '"><h3 style="margin:0 0 6px;font-size:15px">' . $this->t('ruleTitle') . '</h3>' . $this->badge($this->t('rec.' . $rec), self::REC[$rec])
            . '<ul style="margin:8px 0 0;padding-inline-start:18px">' . implode('', array_map(fn ($w) => '<li>' . self::e($w) . '</li>', $why)) . '</ul></div>';
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 6px;font-size:15px">' . $this->t('fitTitle') . '</h3><div style="display:flex;align-items:center;gap:10px"><b style="font-size:26px;color:' . self::LVL[$s['fitLevel']] . '">' . $s['fit'] . '%</b>' . $this->badge($this->t('lvl.' . $s['fitLevel']), self::LVL[$s['fitLevel']]) . '</div>'
            . '<div style="font-size:12px;color:#555;margin-top:6px">' . $this->t('fitDetail', ['s' => $s['scopeSim'] ?? '—', 'c' => $s['corpusFit'] ?? '—', 'n' => $s['corpusSize']]) . '</div></div>';
        // نتائج الإضافات الأخرى
        $ck = '';
        if (isset($s['checks']['preReview'])) {
            $p = $s['checks']['preReview'];
            $ck .= '<li>' . $this->t('c.preReview') . ': ' . (isset($p['missing']) ? '<span style="color:#888">' . $this->t('c.notRun') . '</span>' : ('<a href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'preReviewCheck', 'report', null, ['checkId' => $p['id']])) . '">' . ($p['req'] ? '<span style="color:#b02a2a">' . $this->t('c.blocking', ['n' => $p['req']]) . '</span>' : '<span style="color:#1e7b4a">' . $this->t('c.ready') . '</span>') . '</a>')) . '</li>';
        }
        if (isset($s['checks']['quran'])) {
            $q = $s['checks']['quran'];
            $ck .= '<li>' . $this->t('c.quran') . ': ' . (isset($q['missing']) ? '<span style="color:#888">' . $this->t('c.notRun') . '</span>' : ('<a href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'quranVerifier', 'report', null, ['reportId' => $q['id']])) . '">' . $this->t('c.quranRes', ['t' => $q['total'], 'p' => $q['problems'], 'h' => $q['hadithIssues']]) . '</a>')) . '</li>';
        }
        $decl = array_filter(['declCoi', 'declFunding', 'declEthics', 'declAi'], fn ($f) => $pub->getData($f) !== null);
        if ($decl || \APP\plugins\generic\tadabburSuite\classes\Modules::isActive('declarations')) {
            $missing = count(array_filter(['declCoi', 'declFunding', 'declEthics', 'declAi'], fn ($f) => (string) $pub->getData($f) === ''));
            $ck .= '<li>' . $this->t('c.decl') . ': ' . ($missing ? '<span style="color:#a86a00">' . $this->t('c.declMissing', ['n' => $missing]) . '</span>' : '<span style="color:#1e7b4a">' . $this->t('c.complete') . '</span>') . '</li>';
        }
        if (\APP\plugins\generic\tadabburSuite\classes\Modules::isActive('reviewerHub')) {
            $ck .= '<li><a href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'reviewerHub', 'suggest', null, ['submissionId' => $sid])) . '">' . $this->t('c.reviewers') . '</a></li>';
        }
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 6px;font-size:15px">' . $this->t('checksTitle') . '</h3><ul style="margin:0;padding-inline-start:18px;line-height:1.9">' . ($ck ?: '<li style="color:#888">' . $this->t('c.none') . '</li>') . '</ul></div>';
        $h .= '</div>';

        // ---- التكرار وأقرب المنشور وسجل المؤلفين
        $wfu = fn ($id) => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $id]);
        if ($s['duplicates']) {
            $h .= '<div style="' . self::S_BOX . 'border-color:#6b4fa0;background:#f7f4fb"><h3 style="margin:0 0 6px;font-size:15px;color:#6b4fa0">' . $this->t('dupTitle') . '</h3><ul style="margin:0">';
            foreach ($s['duplicates'] as $d) $h .= '<li><a href="' . self::e($wfu($d['id'])) . '">#' . $d['id'] . ' ' . self::e($d['title']) . '</a> — ' . $this->t('simPct', ['n' => (int) round($d['sim'] * 100)]) . ($d['published'] ? ' · ' . $this->t('published') : '') . '</li>';
            $h .= '</ul></div>';
        }
        $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px"><div style="' . self::S_BOX . '"><h3 style="margin:0 0 6px;font-size:15px">' . $this->t('similarTitle') . '</h3>';
        if (!$s['similar']) $h .= '<p style="color:#888">' . $this->t('noCorpus') . '</p>';
        else { $h .= '<ol style="margin:0;padding-inline-start:20px">'; foreach ($s['similar'] as $x) $h .= '<li style="margin:4px 0">' . self::e($x['title']) . ' <span style="font-size:11px;color:#777">(' . $this->t('simPct', ['n' => (int) round($x['sim'] * 100)]) . ')</span></li>'; $h .= '</ol>'; }
        $h .= '</div><div style="' . self::S_BOX . '"><h3 style="margin:0 0 6px;font-size:15px">' . $this->t('authorsTitle') . '</h3>';
        if (!$s['authors']) $h .= '<p style="color:#888">' . $this->t('newAuthors') . '</p>';
        else { $h .= '<ul style="margin:0">'; foreach ($s['authors'] as $a) $h .= '<li><b>' . self::e($a['name']) . '</b>: ' . $this->t('authorHist', ['t' => $a['total'], 'p' => $a['published'] ?? 0, 'd' => $a['declined'] ?? 0, 'a' => $a['active'] ?? 0]) . '</li>'; $h .= '</ul>'; }
        $h .= '</div></div>';

        // ---- تحليل Claude
        $last = Analyzer::latest($cid, $sid);
        $h .= '<div style="' . self::S_BOX . 'border-top:4px solid #33658a"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="margin:0;font-size:16px">' . $this->t('aiTitle') . '</h3>';
        if ($cfg['apiKey'] !== '') {
            $h .= '<form method="post" action="' . self::e($this->url($request, 'analyze')) . '" style="margin:0">' . $this->csrf($request) . '<input type="hidden" name="submissionId" value="' . $sid . '"><button style="' . self::S_BTNP . '">✦ ' . $this->t($last ? 'reanalyze' : 'analyze') . '</button></form>';
        }
        $h .= '</div><div style="font-size:12px;color:#555;margin:4px 0 10px">' . ($cfg['apiKey'] === '' ? $this->t('noKey') : $this->t($cfg['sendText'] ? 'privacyText' : 'privacyMeta')) . '</div>';
        if ($last) {
            $a = json_decode((string) $last->result, true) ?: [];
            $li = fn ($arr) => $arr ? '<ul style="margin:4px 0;padding-inline-start:18px">' . implode('', array_map(fn ($x) => '<li>' . self::e($x) . '</li>', $arr)) . '</ul>' : '<span style="color:#888">—</span>';
            $h .= '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">' . $this->badge($this->t('rec.' . ($a['decision'] ?? 'review')), self::REC[$a['decision'] ?? 'review']) . $this->badge($this->t('fitAi') . ': ' . $this->t('lvl.' . ($a['scopeFit'] ?? 'medium')), self::LVL[$a['scopeFit'] ?? 'medium'])
                . '<span style="font-size:12px;color:#777">' . self::e($last->model . ' · ' . substr((string) $last->created_at, 0, 16) . ((int) $last->with_text ? ' · ' . $this->t('withText') : '')) . '</span></div>'
                . '<p style="margin:6px 0"><b>' . $this->t('a.summary') . ':</b> ' . self::e($a['summary'] ?? '') . '</p>'
                . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;font-size:13px">'
                . '<div><b>' . $this->t('a.question') . '</b><div>' . self::e($a['question'] ?? '') . '</div></div><div><b>' . $this->t('a.method') . '</b><div>' . self::e($a['method'] ?? '') . '</div></div>'
                . '<div><b>' . $this->t('a.contribution') . '</b><div>' . self::e($a['contribution'] ?? '') . '</div></div><div><b>' . $this->t('a.scope') . '</b><div>' . self::e($a['scopeReason'] ?? '') . '</div></div>'
                . '<div><b style="color:#1e7b4a">' . $this->t('a.strengths') . '</b>' . $li($a['strengths'] ?? []) . '</div><div><b style="color:#b02a2a">' . $this->t('a.concerns') . '</b>' . $li($a['concerns'] ?? []) . '</div>'
                . '<div><b>' . $this->t('a.decisionReason') . '</b><div>' . self::e($a['decisionReason'] ?? '') . '</div></div><div><b>' . $this->t('a.expertise') . '</b>' . $li($a['reviewerExpertise'] ?? []) . '</div>'
                . '<div style="grid-column:1/-1"><b>' . $this->t('a.questions') . '</b>' . $li($a['questionsForAuthor'] ?? []) . '</div></div>';
        }
        $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('aiNote') . '</div></div>';
        $this->show($request, $this->t('cardTitle'), $h);
    }

    public function analyze($args, $request)
    {
        $this->post($request);
        $context = $request->getContext(); $cid = $this->cid($request); $cfg = $this->plugin->cfg($context);
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $cid || $cfg['apiKey'] === '') { http_response_code(404); exit; }
        $pub = $sub->getCurrentPublication();
        $kw = [];
        foreach ((array) ($pub->getData('keywords') ?? []) as $l => $list) foreach ((array) $list as $k) $kw[$l][] = is_array($k) ? (string) ($k['name'] ?? '') : (string) $k;
        $meta = ['title' => array_map(fn ($x) => strip_tags((string) $x), (array) $pub->getData('title')), 'abstract' => array_map(fn ($x) => trim(html_entity_decode(strip_tags((string) $x), ENT_QUOTES, 'UTF-8')), (array) $pub->getData('abstract')),
            'keywords' => $kw, 'section' => (string) (Repo::section()->get((int) $pub->getData('sectionId'))?->getLocalizedTitle() ?? ''), 'authorsCount' => count(iterator_to_array($pub->getData('authors') ?? []))];
        $text = $cfg['sendText'] ? Signals::manuscriptText((int) $sub->getId(), $cfg['maxWords']) : '';
        @set_time_limit(200);
        try {
            $r = Analyzer::run($cfg, (string) $context->getLocalizedName(), $cfg['scope'], $meta, $text);
        } catch (\Throwable $e) {
            $request->redirectUrl($this->url($request, 'view', ['submissionId' => $sub->getId(), 'err' => $e->getMessage()])); return;
        }
        Analyzer::save(['context_id' => $cid, 'submission_id' => (int) $sub->getId(), 'user_id' => (int) $request->getUser()->getId(), 'model' => mb_substr($r['model'], 0, 80),
            'with_text' => $text !== '' ? 1 : 0, 'result' => json_encode($r['data'], JSON_UNESCAPED_UNICODE), 'tokens_in' => $r['in'], 'tokens_out' => $r['out']]);
        $request->redirectUrl($this->url($request, 'view', ['submissionId' => $sub->getId()]));
    }

    private function settingsForm($request, array $cfg): string
    {
        $keyNote = $cfg['keySource'] === 'shared' ? $this->t('set.keyShared') : '';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary><form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:10px;display:grid;gap:10px;max-width:760px">' . $this->csrf($request)
            . '<label>' . $this->t('set.apiKey') . '<br><input type="password" name="apiKey" value="' . ($cfg['keySource'] === 'own' ? '••••••••' : '') . '" autocomplete="new-password" dir="ltr" style="' . self::S_INP . 'width:100%"><div style="font-size:12px;color:#555">' . $this->t('set.apiKeyHelp') . ' ' . $keyNote . '</div></label>'
            . '<label>' . $this->t('set.model') . '<br><input name="model" value="' . self::e($cfg['model']) . '" dir="ltr" style="' . self::S_INP . 'width:260px"></label>'
            . '<label>' . $this->t('set.endpoint') . '<br><input name="endpoint" value="' . self::e($cfg['endpoint']) . '" dir="ltr" style="' . self::S_INP . 'width:100%"></label>'
            . '<label><input type="checkbox" name="sendText" value="1"' . ($cfg['sendText'] ? ' checked' : '') . '> ' . $this->t('set.sendText') . '</label>'
            . '<label>' . $this->t('set.maxWords') . ' <input type="number" min="500" max="20000" name="maxWords" value="' . (int) $cfg['maxWords'] . '" style="' . self::S_INP . 'width:100px"></label>'
            . '<label>' . $this->t('set.scope') . '<br><textarea name="scope" rows="5" style="' . self::S_INP . 'width:100%" placeholder="' . self::e(mb_strimwidth($cfg['scope'], 0, 300, '…')) . '">' . self::e($cfg['scopeOverride']) . '</textarea><div style="font-size:12px;color:#555">' . $this->t('set.scopeHelp') . '</div></label>'
            . '<div><button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></div></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->post($request, true);
        $cid = $this->cid($request);
        $key = trim((string) $request->getUserVar('apiKey'));
        if ($key !== '••••••••') $this->plugin->updateSetting($cid, 'apiKey', $key, 'string');
        $this->plugin->updateSetting($cid, 'model', preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $request->getUserVar('model')) ?: 'claude-sonnet-5-5', 'string');
        $ep = trim((string) $request->getUserVar('endpoint'));
        $this->plugin->updateSetting($cid, 'endpoint', preg_match('#^https?://#', $ep) ? $ep : 'https://api.anthropic.com/v1/messages', 'string');
        $this->plugin->updateSetting($cid, 'sendText', (bool) $request->getUserVar('sendText'), 'bool');
        $this->plugin->updateSetting($cid, 'maxWords', max(500, min(20000, (int) $request->getUserVar('maxWords'))), 'int');
        $this->plugin->updateSetting($cid, 'scope', mb_substr(trim((string) $request->getUserVar('scope')), 0, 8000), 'string');
        $request->redirectUrl($this->url($request, 'index'));
    }
}
