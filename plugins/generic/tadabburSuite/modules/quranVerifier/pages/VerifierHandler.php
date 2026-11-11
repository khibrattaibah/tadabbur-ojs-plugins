<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/quranVerifier/pages/VerifierHandler.php
 * المسارات:
 *   /quranVerifier            قائمة البحوث وتقاريرها (للمحررين)
 *   /quranVerifier/run        تشغيل التدقيق على ملف (POST)
 *   /quranVerifier/report     عرض تقرير محفوظ
 *   /quranVerifier/print      نسخة مستقلة قابلة للطباعة/الإرسال للمؤلف
 *   /quranVerifier/tool       أداة الباحث: لصق نص أو رفع ملف دون حفظ
 *   /quranVerifier/saveSettings، /quranVerifier/deleteReport (POST، لمدير المجلة)
 */
namespace APP\plugins\generic\tadabburSuite\m\quranVerifier\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\classes\QuranEngine;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\classes\Runner;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\classes\Store;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\classes\TextExtractor;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\QuranVerifierPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class VerifierHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const S_QURAN = "font-family:'KFGQPC Uthmanic Script HAFS','Amiri Quran','Amiri','Traditional Arabic','Scheherazade New',serif;font-size:19px;line-height:2.1;";
    private const COLORS = ['ok' => '#1e7b4a', 'diff' => '#b02a2a', 'refWrong' => '#b02a2a', 'refInvalid' => '#b02a2a', 'refPartial' => '#a86a00', 'refMissing' => '#a86a00', 'notFound' => '#6b4fa0', 'short' => '#6c757d'];

    private ?QuranEngine $engine = null;

    public function __construct(private QuranVerifierPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.quranVerifier.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, QuranVerifierPlugin::PAGE, $op, null, $params);
    }
    private function engine(): QuranEngine { return $this->engine ??= new QuranEngine(); }

    private function gate($request, string $level): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        $ok = match ($level) { 'manager' => $this->plugin->isManager($request), 'editor' => $this->plugin->isEditor($request), default => $this->plugin->canUseTool($request) };
        if (!$ok) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }

    private function csrf($request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; }
    }

    private function csrfField($request): string
    {
        return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">';
    }

    private function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    private function nav($request, string $current): string
    {
        $items = [];
        if ($this->plugin->isEditor($request)) $items['index'] = $this->t('nav.submissions');
        $items['tool'] = $this->t('nav.tool');
        $h = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px">';
        foreach ($items as $op => $label) $h .= '<a href="' . self::e($this->url($request, $op)) . '" style="' . ($op === $current ? self::S_BTNP : self::S_BTN) . '">' . self::e($label) . '</a>';
        return $h . '</div>';
    }

    // ================================================================ قائمة البحوث
    public function index($args, $request)
    {
        $this->gate($request, 'editor');
        $context = $request->getContext(); $cid = (int) $context->getId();
        $q = trim((string) $request->getUserVar('q'));
        $h = $this->nav($request, 'index');
        $notice = (string) $request->getUserVar('notice');
        if ($notice !== '') $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1"><b>' . self::e($this->t('err.' . preg_replace('/\W/', '', $notice))) . '</b></div>';
        $h .= '<p style="color:#444;margin-top:0">' . $this->t('intro') . '</p>';

        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request);

        $collector = Repo::submission()->getCollector()->filterByContextIds([$cid])
            ->limit(40)->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        if ($q !== '') $collector->searchPhrase($q);
        $subs = [];
        foreach ($collector->getMany() as $s) if ($s->getData('submissionProgress') === '' || $s->getData('submissionProgress') === null) $subs[] = $s;
        $latest = (new Store())->latestBySubmission($cid, array_map(fn ($s) => (int) $s->getId(), $subs));
        $runner = new Runner($this->plugin);

        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px">'
            . '<h2 style="margin:0;font-size:17px">' . $this->t('submissions') . '</h2>'
            . '<form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:flex;gap:6px"><input name="q" value="' . self::e($q) . '" placeholder="' . self::e($this->t('search')) . '" style="' . self::S_INP . 'width:220px"><button style="' . self::S_BTN . '">' . $this->t('searchBtn') . '</button></form></div>';
        if (!$subs) {
            $h .= '<p>' . $this->t('noSubmissions') . '</p></div>';
            $this->show($request, $this->t('menu'), $h); return;
        }
        $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:50px">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th><th style="' . self::S_TH . 'width:230px">' . $this->t('col.last') . '</th><th style="' . self::S_TH . 'width:330px">' . $this->t('col.run') . '</th></tr></thead><tbody>';
        foreach ($subs as $s) {
            $sid = (int) $s->getId(); $pub = $s->getCurrentPublication();
            $title = $pub ? strip_tags((string) $pub->getLocalizedFullTitle()) : '';
            $authors = $pub ? strip_tags((string) $pub->getShortAuthorString()) : '';
            $last = $latest[$sid] ?? null;
            $lastHtml = '<span style="color:#888">' . $this->t('notChecked') . '</span>';
            if ($last) {
                $st = json_decode((string) $last->stats, true) ?: [];
                $lastHtml = $this->miniStats($st) . '<div style="font-size:12px;color:#666;margin-top:4px">' . self::e(substr((string) $last->created_at, 0, 16)) . ((int) $last->auto ? ' · ' . $this->t('auto') : '') . '</div>'
                    . '<a style="font-size:12px" href="' . self::e($this->url($request, 'report', ['reportId' => $last->report_id])) . '">' . $this->t('openReport') . '</a>';
            }
            $files = $runner->candidateFiles($sid);
            if ($files) {
                $opts = '';
                foreach ($files as $f) $opts .= '<option value="' . $f['id'] . '">' . self::e(mb_strimwidth($f['name'], 0, 46, '…')) . ' (' . self::e($this->t('stage.' . $f['stage'])) . ')</option>';
                $run = '<form method="post" action="' . self::e($this->url($request, 'run')) . '" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin:0">' . $this->csrfField($request)
                    . '<input type="hidden" name="submissionId" value="' . $sid . '"><select name="fileId" style="' . self::S_INP . 'width:220px">' . $opts . '</select>'
                    . '<button style="' . self::S_BTNP . '">' . $this->t('runBtn') . '</button></form>';
            } else {
                $run = '<span style="color:#888">' . $this->t('noFiles') . '</span>';
            }
            $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $sid]);
            $h .= '<tr><td style="' . self::S_TD . '">' . $sid . '</td><td style="' . self::S_TD . '"><a href="' . self::e($wf) . '"><b>' . self::e($title) . '</b></a><div style="color:#666;font-size:12px">' . self::e($authors) . '</div></td>'
                . '<td style="' . self::S_TD . '">' . $lastHtml . '</td><td style="' . self::S_TD . '">' . $run . '</td></tr>';
        }
        $h .= '</tbody></table></div></div>';
        $this->show($request, $this->t('menu'), $h);
    }

    private function miniStats(array $st): string
    {
        $b = fn ($n, $col, $label) => '<span title="' . self::e($label) . '" style="display:inline-block;margin:1px;padding:1px 8px;border-radius:10px;background:' . $col . ';color:#fff;font-size:12px;font-weight:700">' . $n . ' ' . self::e($label) . '</span>';
        $h = $b((int) ($st['ok'] ?? 0), '#1e7b4a', $this->t('badge.ok'));
        $p = (int) ($st['problems'] ?? 0);
        $h .= $b($p, $p ? '#b02a2a' : '#8a949e', $this->t('badge.problems'));
        $hi = (int) ($st['hadithIssues'] ?? 0);
        if (($st['hadith'] ?? 0) > 0) $h .= $b($hi, $hi ? '#a86a00' : '#8a949e', $this->t('badge.hadith'));
        return $h;
    }

    private function settingsForm($request): string
    {
        $cfg = $this->plugin->cfg((int) $request->getContext()->getId());
        $cb = fn ($name, $on, $label) => '<label style="display:block;margin:6px 0"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:8px">' . $this->csrfField($request)
            . $cb('autoRun', $cfg['autoRun'], $this->t('set.autoRun')) . $cb('authorTool', $cfg['authorTool'], $this->t('set.authorTool'))
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->gate($request, 'manager'); $this->csrf($request);
        $cid = (int) $request->getContext()->getId();
        $this->plugin->updateSetting($cid, 'autoRun', (bool) $request->getUserVar('autoRun'), 'bool');
        $this->plugin->updateSetting($cid, 'authorTool', (bool) $request->getUserVar('authorTool'), 'bool');
        $request->redirectUrl($this->url($request, 'index'));
    }

    // ================================================================ التشغيل
    public function run($args, $request)
    {
        $this->gate($request, 'editor'); $this->csrf($request);
        $sid = (int) $request->getUserVar('submissionId'); $fid = (int) $request->getUserVar('fileId');
        $sub = Repo::submission()->get($sid);
        if (!$sub || (int) $sub->getData('contextId') !== (int) $request->getContext()->getId()) { $request->redirectUrl($this->url($request, 'index', ['notice' => 'nofile'])); return; }
        $r = (new Runner($this->plugin))->runForSubmission($sub, $fid ?: null, (int) $request->getUser()->getId());
        if ($r['error']) { $request->redirectUrl($this->url($request, 'index', ['notice' => $r['error']])); return; }
        $request->redirectUrl($this->url($request, 'report', ['reportId' => $r['id']]));
    }

    public function deleteReport($args, $request)
    {
        $this->gate($request, 'manager'); $this->csrf($request);
        (new Store())->delete((int) $request->getUserVar('reportId'), (int) $request->getContext()->getId());
        $request->redirectUrl($this->url($request, 'index'));
    }

    // ================================================================ التقرير
    private function loadReport($request): array
    {
        $cid = (int) $request->getContext()->getId();
        $row = (new Store())->get((int) $request->getUserVar('reportId'), $cid);
        if (!$row) { http_response_code(404); echo self::e($this->t('notFound')); exit; }
        $sub = $row->submission_id ? Repo::submission()->get((int) $row->submission_id) : null;
        $pub = $sub?->getCurrentPublication();
        return [$row, json_decode((string) $row->result, true) ?: ['quran' => [], 'hadith' => [], 'stats' => []], $sub, $pub];
    }

    public function report($args, $request)
    {
        $this->gate($request, 'editor');
        [$row, $res, $sub, $pub] = $this->loadReport($request);
        $onlyProblems = (bool) $request->getUserVar('problems');
        $h = $this->nav($request, '');
        $h .= '<div style="' . self::S_BOX . 'display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center">'
            . '<div><b>' . self::e($pub ? strip_tags((string) $pub->getLocalizedFullTitle()) : '') . '</b><div style="color:#666;font-size:12px">'
            . self::e($this->t('meta', ['file' => $row->file_name, 'date' => substr((string) $row->created_at, 0, 16), 'id' => $row->submission_id])) . '</div></div><div>'
            . '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'report', ['reportId' => $row->report_id, 'problems' => $onlyProblems ? 0 : 1])) . '">' . $this->t($onlyProblems ? 'showAll' : 'showProblems') . '</a>'
            . '<a style="' . self::S_BTN . '" target="_blank" href="' . self::e($this->url($request, 'print', ['reportId' => $row->report_id])) . '">' . $this->t('printBtn') . '</a>';
        if ($this->plugin->isManager($request)) {
            $h .= '<form method="post" action="' . self::e($this->url($request, 'deleteReport')) . '" style="display:inline">' . $this->csrfField($request)
                . '<input type="hidden" name="reportId" value="' . (int) $row->report_id . '"><button style="' . self::S_BTN . 'border-color:#b02a2a;color:#b02a2a">' . $this->t('deleteBtn') . '</button></form>';
        }
        $h .= '</div></div>' . $this->renderReport($res, $onlyProblems);
        // تقارير سابقة للبحث نفسه
        $hist = (new Store())->forSubmission((int) $request->getContext()->getId(), (int) $row->submission_id);
        if (count($hist) > 1) {
            $h .= '<div style="' . self::S_BOX . '"><b>' . $this->t('history') . '</b><ul style="margin:6px 0">';
            foreach ($hist as $r) $h .= '<li><a href="' . self::e($this->url($request, 'report', ['reportId' => $r->report_id])) . '">' . self::e(substr((string) $r->created_at, 0, 16) . ' — ' . $r->file_name) . '</a> ' . $this->miniStats(json_decode((string) $r->stats, true) ?: []) . '</li>';
            $h .= '</ul></div>';
        }
        $this->show($request, $this->t('reportTitle'), $h);
    }

    /** نسخة مستقلة للطباعة أو الإرسال للمؤلف */
    public function print($args, $request)
    {
        $this->gate($request, 'editor');
        [$row, $res, $sub, $pub] = $this->loadReport($request);
        $context = $request->getContext();
        $rtl = \PKP\facades\Locale::getMetadata(\PKP\facades\Locale::getLocale())?->isRightToLeft() ?? true;
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="' . self::e(substr(\PKP\facades\Locale::getLocale(), 0, 2)) . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . self::e($this->t('reportTitle')) . '</title><style>body{font-family:"IBM Plex Sans Arabic","Segoe UI",Tahoma,sans-serif;color:#1f2933;max-width:1100px;margin:24px auto;padding:0 16px;font-size:14px;line-height:1.7}'
            . 'table{page-break-inside:auto}tr{page-break-inside:avoid}@media print{.np{display:none}body{margin:0}}</style></head><body>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0b4f4a;padding-bottom:8px;margin-bottom:14px"><div><div style="font-size:20px;font-weight:700;color:#0b4f4a">' . self::e($context->getLocalizedName()) . '</div>'
            . '<div style="font-size:16px">' . self::e($this->t('reportTitle')) . '</div></div><button class="np" onclick="window.print()" style="' . self::S_BTNP . '">' . $this->t('printNow') . '</button></div>'
            . '<p><b>' . self::e($pub ? strip_tags((string) $pub->getLocalizedFullTitle()) : '') . '</b><br><span style="color:#555">' . self::e($this->t('meta', ['file' => $row->file_name, 'date' => substr((string) $row->created_at, 0, 16), 'id' => $row->submission_id])) . '</span></p>'
            . $this->renderReport($res, false) . '</body></html>';
        exit;
    }

    // ================================================================ أداة الباحث
    public function tool($args, $request)
    {
        $this->gate($request, 'tool');
        $text = ''; $res = null; $err = '';
        if ($request->isPost()) {
            $this->csrf($request);
            $text = (string) $request->getUserVar('text');
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                if ((int) $_FILES['file']['size'] > 20 * 1024 * 1024) $err = 'toobig';
                else {
                    $ex = TextExtractor::fromFile($_FILES['file']['tmp_name'], (string) $_FILES['file']['name'], (string) ($_FILES['file']['type'] ?? ''));
                    if ($ex['error']) $err = $ex['error']; else $text = $ex['text'];
                }
            }
            if (!$err && trim($text) === '') $err = 'empty';
            if (!$err) {
                if (mb_strlen($text) > 600000) $text = mb_substr($text, 0, 600000);
                $res = $this->engine()->analyze($text);
            }
        }
        $h = $this->nav($request, 'tool');
        $h .= '<div style="' . self::S_BOX . '"><p style="margin-top:0">' . $this->t('toolIntro') . '</p>'
            . '<form method="post" enctype="multipart/form-data" action="' . self::e($this->url($request, 'tool')) . '">' . $this->csrfField($request)
            . '<label style="display:block;font-weight:700;margin-bottom:4px">' . $this->t('uploadLabel') . '</label><input type="file" name="file" accept=".docx,.pdf,.odt,.txt" style="margin-bottom:10px">'
            . '<label style="display:block;font-weight:700;margin:6px 0 4px">' . $this->t('pasteLabel') . '</label>'
            . '<textarea name="text" dir="rtl" rows="7" style="' . self::S_INP . 'width:100%;font-size:15px;line-height:1.8">' . self::e($request->isPost() && empty($_FILES['file']['tmp_name']) ? $text : '') . '</textarea>'
            . '<div style="margin-top:8px"><button style="' . self::S_BTNP . '">' . $this->t('checkBtn') . '</button> <span style="color:#666;font-size:12px">' . $this->t('privacy') . '</span></div></form></div>';
        if ($err) $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1"><b>' . self::e($this->t('err.' . $err)) . '</b></div>';
        if ($res) $h .= $this->renderReport($res, false);
        $this->show($request, $this->t('toolMenu'), $h);
    }

    // ================================================================ عرض النتائج
    private function refText(?array $r): string
    {
        if (!$r || empty($r['s'])) return '';
        return $this->engine()->refLabel((int) $r['s'], (int) $r['a1'], (int) $r['a2']);
    }

    private function diffHtml(array $ops): string
    {
        $o = '';
        foreach ($ops as $op) {
            $q = self::e($op['q']); $t = self::e($op['t']);
            $o .= match ($op['op']) {
                '=' => $q . ' ',
                's' => '<del style="color:#b02a2a;background:#fde8e8;text-decoration:line-through">' . $q . '</del> <ins style="color:#1e7b4a;background:#e6f4ea;text-decoration:none;font-weight:700">' . $t . '</ins> ',
                'i' => '<del style="color:#b02a2a;background:#fde8e8;text-decoration:line-through" title="' . self::e($this->t('extra')) . '">' . $q . '</del> ',
                'd' => '<ins style="color:#1e7b4a;background:#e6f4ea;text-decoration:none;font-weight:700;border-bottom:2px dotted #1e7b4a" title="' . self::e($this->t('missing')) . '">[' . $t . ']</ins> ',
                default => '',
            };
        }
        return $o;
    }

    public function renderReport(array $res, bool $onlyProblems): string
    {
        $st = $res['stats'] ?? [];
        $card = fn ($v, $l, $col) => '<div style="flex:1;min-width:140px;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:26px;font-weight:700;color:' . $col . '">' . $v . '</div><div style="font-size:13px">' . $l . '</div></div>';
        $h = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">'
            . $card((int) ($st['total'] ?? 0), $this->t('stat.total'), '#0b4f4a')
            . $card((int) ($st['ok'] ?? 0), $this->t('stat.ok'), '#1e7b4a')
            . $card((int) ($st['problems'] ?? 0), $this->t('stat.problems'), ($st['problems'] ?? 0) ? '#b02a2a' : '#8a949e')
            . $card((int) ($st['hadith'] ?? 0), $this->t('stat.hadith'), '#0b4f4a')
            . $card((int) ($st['hadithIssues'] ?? 0), $this->t('stat.hadithIssues'), ($st['hadithIssues'] ?? 0) ? '#a86a00' : '#8a949e')
            . '</div>';

        // ---- الآيات
        $rows = '';
        $i = 0;
        foreach ($res['quran'] ?? [] as $it) {
            $i++;
            if ($onlyProblems && in_array($it['status'], ['ok', 'short'], true) && ($it['kind'] ?? 'ornate') === 'ornate') continue;
            $col = self::COLORS[$it['status']] ?? '#555';
            $cited = $it['ref'] ? (($it['ref']['valid'] ?? false) ? $this->refText($it['ref']) : ($it['ref']['raw'] ?? '')) : '—';
            $correct = $it['match'] ?? ($it['suggest'] ?? null);
            $explain = $this->t('st.' . $it['status'] . '.d', ['correct' => self::e($this->refText($correct)), 'cited' => self::e($cited), 'n' => (int) ($it['errors'] ?? 0)]);
            if (!empty($it['alts'])) $explain .= '<div style="font-size:12px;color:#555">' . $this->t('alts', ['list' => self::e(implode('، ', array_map(fn ($a) => $this->refText($a), array_slice($it['alts'], 0, 6))))]) . '</div>';
            if (in_array($it['kind'] ?? '', ['brace', 'quote', 'plain'], true)) $explain .= '<div style="font-size:12px;color:#6b4fa0">' . $this->t('styleNote') . '</div>';
            $quoteHtml = ($it['ops'] ?? []) && in_array($it['status'], ['diff', 'notFound'], true) ? $this->diffHtml($it['ops']) : self::e($it['quote']);
            $uth = $correct && !empty($correct['s']) ? $this->engine()->uthmani((int) $correct['s'], (int) $correct['a1'], (int) $correct['a2']) : '';
            $right = $uth !== '' ? '<div style="font-weight:700;color:#0b4f4a">' . self::e($this->refText($correct)) . '</div><details' . (in_array($it['status'], ['diff', 'refWrong', 'refInvalid'], true) ? ' open' : '') . '><summary style="cursor:pointer;font-size:12px;color:#006798">' . $this->t('showText') . '</summary><div dir="rtl" style="' . self::S_QURAN . '">﴿' . self::e($uth) . '﴾</div></details>' : '—';
            $rows .= '<tr style="border-inline-start:4px solid ' . $col . '"><td style="' . self::S_TD . 'text-align:center">' . $i . '</td>'
                . '<td style="' . self::S_TD . '"><div dir="rtl" style="' . self::S_QURAN . 'font-size:17px">' . $quoteHtml . '</div><div style="font-size:11px;color:#777;margin-top:4px">… ' . self::e(mb_strimwidth((string) ($it['context'] ?? ''), 0, 200, '…')) . '</div></td>'
                . '<td style="' . self::S_TD . 'white-space:nowrap">' . self::e($cited) . '</td>'
                . '<td style="' . self::S_TD . '"><span style="display:inline-block;padding:1px 8px;border-radius:10px;background:' . $col . ';color:#fff;font-weight:700;font-size:12px">' . self::e($this->t('st.' . $it['status'])) . '</span><div style="margin-top:4px;font-size:13px">' . $explain . '</div></td>'
                . '<td style="' . self::S_TD . 'min-width:220px">' . $right . '</td></tr>';
        }
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:17px">' . $this->t('quranTitle') . '</h2>';
        $h .= $rows === '' ? '<p>' . $this->t($onlyProblems ? 'noProblems' : 'noQuotes') . '</p>'
            : '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:36px">#</th><th style="' . self::S_TH . '">' . $this->t('col.quote') . '</th><th style="' . self::S_TH . 'width:110px">' . $this->t('col.cited') . '</th><th style="' . self::S_TH . 'width:230px">' . $this->t('col.result') . '</th><th style="' . self::S_TH . '">' . $this->t('col.correct') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('legend') . '</div></div>';

        // ---- التخريج
        $hr = ''; $j = 0;
        foreach ($res['hadith'] ?? [] as $hd) {
            $j++;
            if ($onlyProblems && !$hd['issues']) continue;
            $issues = $hd['issues'] ? implode('<br>', array_map(fn ($x) => '▲ ' . self::e($this->t('h.' . $x)), $hd['issues'])) : '<span style="color:#1e7b4a">✔ ' . $this->t('h.ok') . '</span>';
            $hr .= '<tr><td style="' . self::S_TD . 'text-align:center">' . $j . '</td><td style="' . self::S_TD . '">' . ($hd['type'] === 'hadith' ? '«' . self::e($hd['text']) . '»' : self::e($hd['text'])) . '</td>'
                . '<td style="' . self::S_TD . '">' . self::e(implode('، ', $hd['sources'])) . '</td><td style="' . self::S_TD . ($hd['issues'] ? 'color:#a86a00' : '') . '">' . $issues . '</td></tr>';
        }
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:17px">' . $this->t('hadithTitle') . '</h2>';
        $h .= $hr === '' ? '<p>' . $this->t('noHadith') . '</p>'
            : '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:36px">#</th><th style="' . self::S_TH . '">' . $this->t('col.takhrij') . '</th><th style="' . self::S_TH . 'width:160px">' . $this->t('col.sources') . '</th><th style="' . self::S_TH . 'width:260px">' . $this->t('col.note') . '</th></tr></thead><tbody>' . $hr . '</tbody></table></div>';
        $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('hadithNote') . '</div></div>';

        $h .= '<div style="font-size:12px;color:#555;border-top:1px solid #e1e4e8;padding-top:8px">' . $this->t('method') . ' ' . $this->t('tanzil') . '</div>';
        return $h;
    }
}
