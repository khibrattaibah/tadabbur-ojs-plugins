<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/indexingReadiness/pages/ReadinessHandler.php
 * المسارات: /indexingReadiness?db=overview|scopus|wos|doaj ، /dossier (للطباعة)، /refresh و/saveManual و/saveSettings (POST)
 */
namespace APP\plugins\generic\tadabburSuite\m\indexingReadiness\pages;

use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\indexingReadiness\classes\Criteria;
use APP\plugins\generic\tadabburSuite\m\indexingReadiness\classes\Metrics;
use APP\plugins\generic\tadabburSuite\m\indexingReadiness\classes\OpenAlex;
use APP\plugins\generic\tadabburSuite\m\indexingReadiness\IndexingReadinessPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class ReadinessHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const ST = ['met' => ['#1e7b4a', '✔'], 'partial' => ['#a86a00', '◐'], 'unmet' => ['#b02a2a', '✖'], 'info' => ['#33658a', 'ℹ'], 'manual' => ['#6c757d', '?']];
    private const DBS = ['scopus' => 'Scopus', 'wos' => 'Web of Science', 'doaj' => 'DOAJ'];

    public function __construct(private IndexingReadinessPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.indexingReadiness.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, IndexingReadinessPlugin::PAGE, $op, null, $params);
    }
    private function gate($request, bool $manager = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!($manager ? $this->plugin->isManager($request) : $this->plugin->canView($request))) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }
    private function csrf($request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; }
    }
    private function csrfField($request): string
    {
        return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">';
    }

    /** @return array{0:array,1:?array,2:array,3:array} metrics, openalex, evaluation, cfg */
    private function data($request): array
    {
        $context = $request->getContext(); $cfg = $this->plugin->cfg((int) $context->getId());
        $m = (new Metrics($context, $cfg['years'], $cfg['expectedPerYear']))->compute();
        $oa = is_array($cfg['openalex']) ? $cfg['openalex'] : null;
        return [$m, $oa, Criteria::evaluate($m, $oa, $cfg['manual']), $cfg];
    }

    private function badge(string $st, bool $small = false): string
    {
        [$col, $ic] = self::ST[$st] ?? ['#555', ''];
        return '<span style="display:inline-block;white-space:nowrap;padding:1px 8px;border-radius:10px;background:' . $col . ';color:#fff;font-weight:700;font-size:' . ($small ? '11' : '12') . 'px">' . $ic . ' ' . self::e($this->t('st.' . $st)) . '</span>';
    }

    private function bar(int $v): string
    {
        $col = $v >= 85 ? '#1e7b4a' : ($v >= 65 ? '#a86a00' : '#b02a2a');
        return '<div style="height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;margin:6px 0"><div style="width:' . $v . '%;height:100%;background:' . $col . '"></div></div>';
    }

    private function tabs($request, string $cur): string
    {
        $h = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px">';
        foreach (['overview' => $this->t('tab.overview')] + self::DBS as $k => $label) {
            $h .= '<a href="' . self::e($this->url($request, 'index', ['db' => $k])) . '" style="' . ($k === $cur ? self::S_BTNP : self::S_BTN) . '">' . self::e($label) . '</a>';
        }
        return $h . '<a target="_blank" href="' . self::e($this->url($request, 'dossier')) . '" style="' . self::S_BTN . 'border-color:#0b4f4a;color:#0b4f4a">⎙ ' . $this->t('dossier') . '</a></div>';
    }

    private function show($request, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $this->t('menu'), 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    // ================================================================ الصفحة
    public function index($args, $request)
    {
        $this->gate($request);
        $db = (string) $request->getUserVar('db');
        if (!isset(self::DBS[$db])) $db = 'overview';
        [$m, $oa, $ev, $cfg] = $this->data($request);
        $h = '';
        if ($request->getUserVar('saved')) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t('saved') . '</b></div>';
        if ($request->getUserVar('oaerr')) $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1"><b>' . $this->t('oaError') . '</b></div>';
        $h .= $this->tabs($request, $db);
        $h .= $db === 'overview' ? $this->overview($request, $m, $oa, $ev, $cfg) : $this->dbTab($request, $db, $ev[$db]);
        $this->show($request, $h);
    }

    private function overview($request, array $m, ?array $oa, array $ev, array $cfg): string
    {
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p><div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">';
        foreach (self::DBS as $db => $label) {
            $c = $ev[$db]['counts']; $s = $ev[$db]['score'];
            $h .= '<a href="' . self::e($this->url($request, 'index', ['db' => $db])) . '" style="flex:1;min-width:220px;text-decoration:none;color:inherit;' . self::S_BOX . 'margin:0">'
                . '<div style="display:flex;justify-content:space-between;align-items:baseline"><b style="font-size:16px">' . $label . '</b><span style="font-size:26px;font-weight:700">' . $s . '%</span></div>' . $this->bar($s)
                . '<div style="font-size:12px;color:#555">' . $this->t('counts', ['met' => $c['met'], 'partial' => $c['partial'], 'unmet' => $c['unmet'], 'manual' => $c['manual']]) . '</div></a>';
        }
        $h .= '</div>';

        // ---- المؤشرات الرئيسة
        $k = fn ($v, $label, $sub = '') => '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:10px"><div style="font-size:20px;font-weight:700;color:#0b4f4a">' . self::e($v) . '</div><div style="font-size:13px">' . self::e($label) . '</div>' . ($sub !== '' ? '<div style="font-size:11px;color:#666">' . self::e($sub) . '</div>' : '') . '</div>';
        $pct = fn ($v) => $v === null ? '—' : $v . '%';
        $countryName = fn ($code) => $code ? (Locale::getCountries()->getByAlpha2($code)?->getLocalName() ?? $code) : '—';
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 4px;font-size:17px">' . $this->t('keyMetrics') . '</h2><div style="font-size:12px;color:#666;margin-bottom:10px">' . $this->t('window', ['n' => $m['years']]) . '</div>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px">'
            . $k($m['yearsActive'] ?: '—', $this->t('m.yearsActive'), $m['firstYear'] ? $this->t('m.since', ['y' => $m['firstYear']]) : '')
            . $k($m['issuesTotal'], $this->t('m.issues'), $this->t('m.last', ['d' => $m['lastIssue'] ?? '—']))
            . $k($m['articles'], $this->t('m.articles'), implode(' · ', array_map(fn ($y, $n) => "$y: $n", array_keys($m['articlesByYear']), $m['articlesByYear'])))
            . $k($pct($m['enAbstractPct']), $this->t('m.enAbstract'), $this->t('m.enTitle', ['v' => $pct($m['enTitlePct'])]))
            . $k($pct($m['refsRomanPct']), $this->t('m.romanRefs'), $this->t('m.refsTotal', ['n' => $m['refsTotal']]))
            . $k($pct($m['selfCitePct']), $this->t('m.selfCite'), $m['selfCites'] . ' / ' . $m['refsTotal'])
            . $k($pct($m['doiPct']), $this->t('m.doi'))
            . $k($pct($m['orcidPct']), $this->t('m.orcid'), $this->t('m.aff', ['v' => $pct($m['affPct'])]))
            . $k($m['authorCountries'], $this->t('m.authorCountries'), $this->t('m.top', ['c' => $countryName($m['authorTopCountry']), 'v' => $pct($m['authorTopShare'])]))
            . $k($m['board']['n'], $this->t('m.board'), $this->t('m.boardSub', ['c' => $m['board']['countries'], 'v' => $pct($m['board']['foreignPct'])]))
            . '</div></div>';

        // ---- OpenAlex
        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center"><h2 style="margin:0;font-size:17px">' . $this->t('oaTitle') . '</h2>';
        if ($this->plugin->isManager($request)) {
            $h .= '<form method="post" action="' . self::e($this->url($request, 'refresh')) . '" style="margin:0">' . $this->csrfField($request) . '<button style="' . self::S_BTN . '">⟳ ' . $this->t('oaRefresh') . '</button></form>';
        }
        $h .= '</div>';
        if ($oa && !empty($oa['ok'])) {
            $s = $oa['source'];
            $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-top:10px">'
                . $k($s['works'], $this->t('oa.works')) . $k($s['cited'], $this->t('oa.cited')) . $k($s['h'], $this->t('oa.h')) . $k($s['meanCitedness2y'], $this->t('oa.mean2y'))
                . $k($s['isInDoaj'] ? $this->t('yes') : $this->t('no'), $this->t('oa.doaj')) . '</div>';
            if ($s['counts']) {
                $h .= '<table style="' . self::S_TBL . 'margin-top:10px;max-width:520px"><thead><tr><th style="' . self::S_TH . '">' . $this->t('year') . '</th><th style="' . self::S_TH . '">' . $this->t('oa.works') . '</th><th style="' . self::S_TH . '">' . $this->t('oa.citedYear') . '</th></tr></thead><tbody>';
                foreach ($s['counts'] as $r) $h .= '<tr><td style="' . self::S_TD . '">' . $r['year'] . '</td><td style="' . self::S_TD . '">' . $r['works'] . '</td><td style="' . self::S_TD . '">' . $r['cited'] . '</td></tr>';
                $h .= '</tbody></table>';
            }
            if (!empty($oa['board'])) {
                $h .= '<details style="margin-top:10px"><summary style="cursor:pointer;font-weight:700">' . $this->t('oa.board', ['n' => count($oa['board'])]) . '</summary><table style="' . self::S_TBL . 'margin-top:6px"><thead><tr><th style="' . self::S_TH . '">' . $this->t('name') . '</th><th style="' . self::S_TH . '">ORCID</th><th style="' . self::S_TH . '">' . $this->t('oa.works') . '</th><th style="' . self::S_TH . '">' . $this->t('oa.cited') . '</th><th style="' . self::S_TH . '">h</th></tr></thead><tbody>';
                foreach ($oa['board'] as $b) $h .= '<tr><td style="' . self::S_TD . '">' . self::e($b['name']) . '</td><td style="' . self::S_TD . '" dir="ltr">' . self::e($b['orcid']) . '</td><td style="' . self::S_TD . '">' . $b['works'] . '</td><td style="' . self::S_TD . '">' . $b['cited'] . '</td><td style="' . self::S_TD . '">' . $b['h'] . '</td></tr>';
                $h .= '</tbody></table></details>';
            }
            $h .= '<div style="font-size:12px;color:#666;margin-top:8px">' . $this->t('oaNote', ['d' => self::e($oa['fetched'] ?? '')]) . '</div>';
        } else {
            $h .= '<p style="color:#555">' . $this->t('oaEmpty') . '</p>';
        }
        $h .= '</div>';

        // ---- خطة العمل
        $plan = [];
        foreach ($ev as $db => $d) foreach ($d['groups'] as $g => $items) foreach ($items as $id => $it) {
            if ($it['level'] === 'impact' || in_array($it['status'], ['met', 'info'], true)) continue;
            $prio = ($it['status'] === 'unmet' ? 0 : ($it['status'] === 'partial' ? 1 : 2)) + ($it['level'] === 'req' ? 0 : 3);
            $key = $id;
            if (!isset($plan[$key]) || $plan[$key]['prio'] > $prio) $plan[$key] = ['prio' => $prio, 'status' => $it['status'], 'dbs' => $plan[$key]['dbs'] ?? [], 'value' => $it['value'], 'db' => $db];
            $plan[$key]['dbs'][$db] = true;
        }
        uasort($plan, fn ($a, $b) => $a['prio'] <=> $b['prio']);
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:17px">' . $this->t('plan') . '</h2>';
        if (!$plan) $h .= '<p>' . $this->t('planEmpty') . '</p>';
        else {
            $h .= '<p style="margin-top:0;color:#555;font-size:13px">' . $this->t('planIntro') . '</p><ol style="margin:0;padding-inline-start:22px">';
            foreach ($plan as $id => $p) {
                $dbs = implode(' ', array_map(fn ($d) => '<span style="display:inline-block;padding:0 6px;border-radius:9px;background:#eef2f6;font-size:11px;font-weight:700" dir="ltr">' . self::DBS[$d] . '</span>', array_keys($p['dbs'])));
                $fix = $this->t('crit.' . $id . '.h');
                $h .= '<li style="margin:0 0 10px">' . $this->badge($p['status'], true) . ' <b>' . self::e($this->t('crit.' . $id)) . '</b> ' . $dbs
                    . ($p['value'] !== '' ? ' <span style="color:#666;font-size:12px" dir="auto">(' . self::e($p['value']) . ')</span>' : '')
                    . '<div style="font-size:13px;color:#333">' . self::e($fix) . '</div></li>';
            }
            $h .= '</ol>';
        }
        $h .= '</div>';
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request, $cfg);
        $h .= '<div style="font-size:12px;color:#555">' . $this->t('disclaimer') . '</div>';
        return $h;
    }

    private function settingsForm($request, array $cfg): string
    {
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:14px;align-items:end">' . $this->csrfField($request)
            . '<label>' . $this->t('set.expected') . '<br><input type="number" min="1" max="24" name="expectedPerYear" value="' . $cfg['expectedPerYear'] . '" style="' . self::S_INP . 'width:90px"></label>'
            . '<label>' . $this->t('set.years') . '<br><input type="number" min="1" max="10" name="years" value="' . $cfg['years'] . '" style="' . self::S_INP . 'width:90px"></label>'
            . '<label>' . $this->t('set.openalexKey') . '<br><input name="openalexKey" value="' . self::e((string) $this->plugin->getSetting((int) $request->getContext()->getId(), 'openalexKey')) . '" dir="ltr" autocomplete="off" style="' . self::S_INP . 'width:300px"></label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form><div style="font-size:12px;color:#555;margin-top:6px">' . $this->t('set.openalexKeyHelp') . '</div></details>';
    }

    private function dbTab($request, string $db, array $d): string
    {
        $mgr = $this->plugin->isManager($request);
        $h = '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;align-items:baseline"><b style="font-size:18px">' . self::DBS[$db] . '</b><span style="font-size:26px;font-weight:700">' . $d['score'] . '%</span></div>'
            . $this->bar($d['score']) . '<p style="margin:6px 0 0;color:#444;font-size:13px">' . $this->t('about.' . $db) . '</p></div>';
        if ($mgr) $h .= '<form method="post" action="' . self::e($this->url($request, 'saveManual')) . '">' . $this->csrfField($request) . '<input type="hidden" name="db" value="' . $db . '">';
        foreach ($d['groups'] as $g => $items) {
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . self::e($this->t('grp.' . $db . '.' . $g)) . '</h2><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr>'
                . '<th style="' . self::S_TH . '">' . $this->t('col.criterion') . '</th><th style="' . self::S_TH . 'width:140px">' . $this->t('col.status') . '</th><th style="' . self::S_TH . 'width:170px">' . $this->t('col.value') . '</th>'
                . '<th style="' . self::S_TH . 'width:300px">' . $this->t('col.evidence') . '</th></tr></thead><tbody>';
            foreach ($items as $id => $it) {
                $key = $db . '.' . $id;
                $lvl = $it['level'] === 'req' ? '' : ' <span style="font-size:11px;color:#666">(' . $this->t('lvl.' . $it['level']) . ')</span>';
                $src = $it['source'] === 'auto' ? $this->t('src.auto') : ($it['source'] === 'override' ? $this->t('src.override') : $this->t('src.manual'));
                $ev = '';
                if ($mgr) {
                    $opt = fn ($v, $l) => '<option value="' . $v . '"' . ((in_array($it['source'], ['manual', 'override'], true) && $it['status'] === $v) || ($v === '' && !in_array($it['source'], ['manual', 'override'], true)) ? ' selected' : '') . '>' . $l . '</option>';
                    $ev = '<select name="m[' . self::e($id) . '][status]" style="' . self::S_INP . 'width:100%">' . $opt('', $it['autoStatus'] ? $this->t('opt.auto') : $this->t('opt.none'))
                        . $opt('met', $this->t('st.met')) . $opt('partial', $this->t('st.partial')) . $opt('unmet', $this->t('st.unmet')) . '</select>'
                        . '<input name="m[' . self::e($id) . '][url]" value="' . self::e($it['url']) . '" placeholder="' . self::e($this->t('ph.url')) . '" dir="ltr" style="' . self::S_INP . 'width:100%;margin-top:4px">'
                        . '<input name="m[' . self::e($id) . '][note]" value="' . self::e($it['note']) . '" placeholder="' . self::e($this->t('ph.note')) . '" style="' . self::S_INP . 'width:100%;margin-top:4px">';
                } else {
                    $ev = ($it['url'] !== '' ? '<a href="' . self::e($it['url']) . '" target="_blank" rel="noopener" dir="ltr">' . self::e(mb_strimwidth($it['url'], 0, 50, '…')) . '</a><br>' : '') . self::e($it['note']);
                }
                $h .= '<tr><td style="' . self::S_TD . '"><b>' . self::e($this->t('crit.' . $id)) . '</b>' . $lvl . '<div style="font-size:12px;color:#555">' . self::e($this->t('crit.' . $id . '.h')) . '</div></td>'
                    . '<td style="' . self::S_TD . '">' . $this->badge($it['status']) . '<div style="font-size:11px;color:#777;margin-top:3px">' . $src . '</div></td>'
                    . '<td style="' . self::S_TD . '" dir="auto">' . self::e($it['value']) . '</td><td style="' . self::S_TD . '">' . $ev . '</td></tr>';
            }
            $h .= '</tbody></table></div></div>';
        }
        if ($mgr) $h .= '<div style="position:sticky;bottom:0;background:#fff;padding:10px;border-top:1px solid #d5dbe1"><button style="' . self::S_BTNP . '">' . $this->t('saveEval') . '</button></div></form>';
        return $h;
    }

    // ================================================================ الحفظ
    public function saveManual($args, $request)
    {
        $this->gate($request, true); $this->csrf($request);
        $db = (string) $request->getUserVar('db');
        if (!isset(Criteria::MAP[$db])) { $request->redirectUrl($this->url($request, 'index')); return; }
        $cid = (int) $request->getContext()->getId();
        $manual = $this->plugin->cfg($cid)['manual'];
        $valid = [];
        foreach (Criteria::MAP[$db] as $items) $valid += $items;
        foreach ((array) $request->getUserVar('m') as $id => $v) {
            if (!isset($valid[$id]) || !is_array($v)) continue;
            $st = in_array($v['status'] ?? '', ['met', 'partial', 'unmet'], true) ? $v['status'] : '';
            $url = trim((string) ($v['url'] ?? '')); if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
            $note = mb_substr(trim((string) ($v['note'] ?? '')), 0, 500);
            if ($st === '' && $url === '' && $note === '') unset($manual[$db . '.' . $id]);
            else $manual[$db . '.' . $id] = ['status' => $st, 'url' => mb_substr($url, 0, 500), 'note' => $note, 'by' => (int) $request->getUser()->getId(), 'at' => date('Y-m-d')];
        }
        $this->plugin->updateSetting($cid, 'manual', $manual, 'object');
        $request->redirectUrl($this->url($request, 'index', ['db' => $db, 'saved' => 1]));
    }

    public function saveSettings($args, $request)
    {
        $this->gate($request, true); $this->csrf($request);
        $cid = (int) $request->getContext()->getId();
        $this->plugin->updateSetting($cid, 'expectedPerYear', max(1, min(24, (int) $request->getUserVar('expectedPerYear'))), 'int');
        $this->plugin->updateSetting($cid, 'years', max(1, min(10, (int) $request->getUserVar('years'))), 'int');
        $this->plugin->updateSetting($cid, 'openalexKey', preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $request->getUserVar('openalexKey')), 'string');
        $request->redirectUrl($this->url($request, 'index', ['saved' => 1]));
    }

    public function refresh($args, $request)
    {
        $this->gate($request, true); $this->csrf($request);
        $context = $request->getContext(); $cid = (int) $context->getId();
        $m = (new Metrics($context, 1, 1))->compute();
        $oa = (new OpenAlex((string) $context->getData('contactEmail'), \APP\plugins\generic\tadabburSuite\classes\kit\Services::openalexKey($cid, (string) $this->plugin->getSetting($cid, 'openalexKey'))))->fetch([$m['issnOnline'], $m['issnPrint']], array_values($m['boardOrcids']));
        if ($oa['ok']) { $this->plugin->updateSetting($cid, 'openalex', $oa, 'object'); $request->redirectUrl($this->url($request, 'index', ['saved' => 1])); return; }
        $request->redirectUrl($this->url($request, 'index', ['oaerr' => 1]));
    }

    // ================================================================ الملف المطبوع
    public function dossier($args, $request)
    {
        $this->gate($request);
        [$m, $oa, $ev, $cfg] = $this->data($request);
        $c = $request->getContext();
        $rtl = Locale::getMetadata(Locale::getLocale())?->isRightToLeft() ?? true;
        $row = fn ($l, $v) => '<tr><th style="' . self::S_TH . 'width:38%">' . self::e($l) . '</th><td style="' . self::S_TD . '" dir="auto">' . $v . '</td></tr>';
        $pct = fn ($v) => $v === null ? '—' : $v . '%';
        $countries = function (array $list) {
            $o = [];
            foreach ($list as $code => $n) $o[] = self::e(Locale::getCountries()->getByAlpha2((string) $code)?->getLocalName() ?? $code) . ' (' . $n . ')';
            return implode('، ', $o) ?: '—';
        };
        $journalUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $c->getPath());
        $h = '<!DOCTYPE html><html lang="' . self::e(substr(Locale::getLocale(), 0, 2)) . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($this->t('dossier') . ' — ' . $c->getLocalizedName()) . '</title>'
            . '<style>body{font-family:"IBM Plex Sans Arabic","Segoe UI",Tahoma,sans-serif;color:#1f2933;max-width:1000px;margin:24px auto;padding:0 16px;font-size:13.5px;line-height:1.7}h2{font-size:17px;color:#0b4f4a;border-bottom:2px solid #0b4f4a;padding-bottom:4px;margin-top:26px}table{margin-bottom:10px}tr{page-break-inside:avoid}@media print{.np{display:none}body{margin:0}h2{page-break-after:avoid}}</style></head><body>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0b4f4a;padding-bottom:10px"><div><div style="font-size:22px;font-weight:700;color:#0b4f4a">' . self::e($c->getLocalizedName()) . '</div>'
            . '<div style="font-size:16px">' . $this->t('dossierTitle') . '</div><div style="color:#666">' . self::e($this->t('generated', ['d' => date('Y-m-d')])) . '</div></div><button class="np" onclick="window.print()" style="' . self::S_BTNP . '">' . $this->t('print') . '</button></div>';

        $h .= '<h2>' . $this->t('d.profile') . '</h2><table style="' . self::S_TBL . '">'
            . $row($this->t('d.titleAr'), self::e($c->getName('ar') ?: $c->getLocalizedName())) . $row($this->t('d.titleEn'), self::e($c->getName('en') ?: '—'))
            . $row('ISSN', self::e(trim(($m['issnOnline'] ? 'e-ISSN ' . $m['issnOnline'] : '') . '  ' . ($m['issnPrint'] ? 'p-ISSN ' . $m['issnPrint'] : '')) ?: '—'))
            . $row($this->t('d.publisher'), self::e($m['publisher'] ?: '—')) . $row($this->t('d.country'), $countries($m['country'] ? [$m['country'] => 1] : []))
            . $row($this->t('d.url'), '<span dir="ltr">' . self::e($journalUrl) . '</span>') . $row($this->t('d.contact'), self::e($m['contactEmail']))
            . $row($this->t('d.languages'), self::e(implode(', ', $m['locales']))) . $row($this->t('d.license'), self::e($m['license'] ?: '—'))
            . $row($this->t('d.since'), self::e(($m['firstYear'] ?? '—') . ' · ' . $this->t('d.issuesTotal', ['n' => $m['issuesTotal']])))
            . $row($this->t('d.frequency'), self::e($this->t('d.perYear', ['n' => $cfg['expectedPerYear']]))) . '</table>';

        $h .= '<h2>' . $this->t('d.output') . '</h2><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">' . $this->t('year') . '</th><th style="' . self::S_TH . '">' . $this->t('m.issues') . '</th><th style="' . self::S_TH . '">' . $this->t('m.articles') . '</th></tr></thead><tbody>';
        foreach ($m['articlesByYear'] as $y => $n) $h .= '<tr><td style="' . self::S_TD . '">' . $y . '</td><td style="' . self::S_TD . '">' . ($m['issuesByYear'][$y] ?? 0) . '</td><td style="' . self::S_TD . '">' . $n . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<h2>' . $this->t('d.quality') . '</h2><table style="' . self::S_TBL . '">'
            . $row($this->t('m.enAbstract'), $pct($m['enAbstractPct'])) . $row($this->t('d.enTitle'), $pct($m['enTitlePct']))
            . $row($this->t('m.romanRefs'), $pct($m['refsRomanPct']) . ' (' . $this->t('m.refsTotal', ['n' => $m['refsTotal']]) . ')')
            . $row($this->t('m.selfCite'), $pct($m['selfCitePct'])) . $row($this->t('m.doi'), $pct($m['doiPct'])) . $row($this->t('m.orcid'), $pct($m['orcidPct']))
            . $row($this->t('d.affAuthors'), $pct($m['affPct'])) . '</table>';

        $h .= '<h2>' . $this->t('d.diversity') . '</h2><table style="' . self::S_TBL . '">'
            . $row($this->t('m.authorCountries'), $m['authorCountries'] . ' — ' . $countries($m['authorCountryList']))
            . $row($this->t('d.localShare'), $pct($m['authorLocalShare']))
            . $row($this->t('m.board'), $m['board']['n'] . ' — ' . $countries($m['board']['countryList']))
            . $row($this->t('d.boardIntl'), $pct($m['board']['foreignPct'])) . $row($this->t('d.boardAff'), $pct($m['board']['affPct'])) . $row($this->t('d.boardOrcid'), $pct($m['board']['orcidPct'])) . '</table>';

        if ($oa && !empty($oa['ok'])) {
            $s = $oa['source'];
            $h .= '<h2>' . $this->t('oaTitle') . '</h2><table style="' . self::S_TBL . '">' . $row($this->t('oa.works'), $s['works']) . $row($this->t('oa.cited'), $s['cited']) . $row($this->t('oa.h'), $s['h']) . $row($this->t('oa.mean2y'), $s['meanCitedness2y'])
                . $row($this->t('d.source'), '<span dir="ltr">' . self::e($s['id']) . ' — ' . self::e($oa['fetched']) . '</span>') . '</table>';
        }

        foreach (self::DBS as $db => $label) {
            $h .= '<h2>' . $this->t('d.compliance', ['db' => $label]) . ' — ' . $ev[$db]['score'] . '%</h2>';
            foreach ($ev[$db]['groups'] as $g => $items) {
                $h .= '<div style="font-weight:700;margin:8px 0 4px">' . self::e($this->t('grp.' . $db . '.' . $g)) . '</div><table style="' . self::S_TBL . '"><tbody>';
                foreach ($items as $id => $it) {
                    $evid = trim(($it['value'] !== '' ? self::e($it['value']) : '') . ($it['url'] !== '' ? ' <a href="' . self::e($it['url']) . '" dir="ltr">' . self::e($it['url']) . '</a>' : '') . ($it['note'] !== '' ? ' — ' . self::e($it['note']) : ''));
                    $h .= '<tr><td style="' . self::S_TD . 'width:38%"><b>' . self::e($this->t('crit.' . $id)) . '</b></td><td style="' . self::S_TD . 'width:120px">' . $this->badge($it['status'], true) . '</td><td style="' . self::S_TD . '" dir="auto">' . ($evid ?: '—') . '</td></tr>';
                }
                $h .= '</tbody></table>';
            }
        }
        $h .= '<p style="font-size:12px;color:#555;margin-top:20px">' . $this->t('disclaimer') . '</p></body></html>';
        header('Content-Type: text/html; charset=utf-8');
        echo $h;
        exit;
    }
}
