<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialCommand/pages/CommandHandler.php
 * /editorialCommand، /csv، /remind?submissionId=&to=، و POST: sendRemind، saveSettings، runDigest
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialCommand\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\editorialCommand\classes\Board;
use APP\plugins\generic\tadabburSuite\m\editorialCommand\classes\Digest;
use APP\plugins\generic\tadabburSuite\m\editorialCommand\EditorialCommandPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\log\SubmissionEmailLogEventType;
use PKP\mail\Mailable;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class CommandHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;white-space:nowrap;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const PARTY = ['editor' => '#b02a2a', 'reviewer' => '#6b4fa0', 'author' => '#a86a00', 'production' => '#33658a'];

    public function __construct(private EditorialCommandPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = [], ?string $l = null): string { return __('plugins.generic.editorialCommand.' . $k, $p, $l); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, EditorialCommandPlugin::PAGE, $op, null, $params);
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
    /** وضع القراءة فقط: لا بريد ولا كتابة في سجلات التحرير */
    private function noWrite(): void
    {
        if ($this->plugin->readOnly()) { http_response_code(403); echo self::e($this->t('readOnlyMode')); exit; }
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
    private function wf($request, int $id): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $id]);
    }

    private function filtered($request, array $rows): array
    {
        $f = ['stage' => (string) $request->getUserVar('stage'), 'party' => (string) $request->getUserVar('party'), 'editor' => (int) $request->getUserVar('editor'), 'late' => (bool) $request->getUserVar('late')];
        if ($request->getUserVar('mine')) $f['editor'] = (int) $request->getUser()->getId();
        return [array_values(array_filter($rows, fn ($r) => ($f['stage'] === '' || $r['stageKey'] === $f['stage']) && ($f['party'] === '' || $r['party'] === $f['party'])
            && (!$f['editor'] || in_array($f['editor'], $r['editorIds'], true)) && (!$f['late'] || $r['overdue']))), $f];
    }

    // ================================================================ اللوحة
    public function index($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request); $cfg = $this->plugin->cfg($cid);
        $all = $this->plugin->visible($request, $this->plugin->board($cid, Locale::getLocale()));
        $sum = Board::summary($all);
        [$rows, $f] = $this->filtered($request, $all);
        $h = '';
        foreach (['sent' => 'sentMsg', 'saved' => 'saved', 'digested' => 'digestDone'] as $q => $k) if ($request->getUserVar($q) !== null) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t($k, ['n' => (int) $request->getUserVar($q)]) . '</b></div>';

        // ---- البطاقات
        $card = fn ($v, $l, $col, $link = null) => '<a ' . ($link ? 'href="' . self::e($link) . '"' : '') . ' style="flex:1;min-width:150px;text-decoration:none;color:inherit;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:26px;font-weight:700;color:' . $col . '">' . $v . '</div><div style="font-size:13px">' . $l . '</div></a>';
        $h .= '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">'
            . $card($sum['total'], $this->t('k.active'), '#0b4f4a', $this->url($request, 'index'))
            . $card($sum['overdue'], $this->t('k.overdue'), $sum['overdue'] ? '#b02a2a' : '#1e7b4a', $this->url($request, 'index', ['late' => 1]))
            . $card($sum['median'] === null ? '—' : $this->t('daysN', ['n' => $sum['median']]), $this->t('k.median'), '#33658a')
            . $card(count(array_filter($all, fn ($r) => in_array((int) $request->getUser()->getId(), $r['editorIds'], true) && $r['party'] === 'editor')), $this->t('k.mine'), '#8a6d3b', $this->url($request, 'index', ['mine' => 1, 'party' => 'editor']))
            . '</div>';

        // ---- من ينتظر؟ وحسب المرحلة
        $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:2px">';
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:15px">' . $this->t('whoWaits') . '</h2>';
        foreach (['editor', 'reviewer', 'author', 'production'] as $p) {
            $d = $sum['byParty'][$p] ?? ['n' => 0, 'late' => 0];
            $w = $sum['total'] ? (int) round(100 * $d['n'] / $sum['total']) : 0;
            $h .= '<a href="' . self::e($this->url($request, 'index', ['party' => $p])) . '" style="display:grid;grid-template-columns:110px 1fr 70px;gap:8px;align-items:center;margin:6px 0;text-decoration:none;color:inherit">'
                . '<span>' . $this->t('party.' . $p) . '</span><span style="height:10px;background:#eef0f2;border-radius:5px;overflow:hidden"><span style="display:block;height:100%;width:' . $w . '%;background:' . self::PARTY[$p] . '"></span></span>'
                . '<span style="font-size:12px"><b>' . $d['n'] . '</b>' . ($d['late'] ? ' · <span style="color:#b02a2a">' . $d['late'] . ' ' . $this->t('late') . '</span>' : '') . '</span></a>';
        }
        $h .= '</div><div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:15px">' . $this->t('byStage') . '</h2>';
        foreach (Board::STAGES as $k) {
            $n = $sum['byStage'][$k] ?? 0; $w = $sum['total'] ? (int) round(100 * $n / $sum['total']) : 0;
            $h .= '<a href="' . self::e($this->url($request, 'index', ['stage' => $k])) . '" style="display:grid;grid-template-columns:110px 1fr 40px;gap:8px;align-items:center;margin:6px 0;text-decoration:none;color:inherit"><span>' . $this->t('stage.' . $k) . '</span>'
                . '<span style="height:10px;background:#eef0f2;border-radius:5px;overflow:hidden"><span style="display:block;height:100%;width:' . $w . '%;background:#0b4f4a"></span></span><b>' . $n . '</b></a>';
        }
        $h .= '</div></div>';

        // ---- أعباء المحررين
        if ($sum['byEditor']) {
            $h .= '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700;font-size:15px">' . $this->t('editorsLoad') . '</summary><div style="overflow-x:auto;margin-top:8px"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">' . $this->t('col.editor') . '</th><th style="' . self::S_TH . '">' . $this->t('col.assigned') . '</th><th style="' . self::S_TH . '">' . $this->t('col.awaitingThem') . '</th><th style="' . self::S_TH . '">' . $this->t('col.lateThem') . '</th></tr></thead><tbody>';
            foreach ($sum['byEditor'] as $eid => $e) {
                $h .= '<tr><td style="' . self::S_TD . '"><a href="' . self::e($this->url($request, 'index', ['editor' => $eid])) . '">' . self::e($e['name']) . '</a></td><td style="' . self::S_TD . '">' . $e['n'] . '</td><td style="' . self::S_TD . '">' . $e['waiting'] . '</td><td style="' . self::S_TD . ($e['late'] ? 'color:#b02a2a;font-weight:700' : '') . '">' . $e['late'] . '</td></tr>';
            }
            $h .= '</tbody></table></div></details>';
        }

        // ---- المرشحات
        $sel = function ($name, $opts, $cur) { $o = '<select name="' . $name . '" style="' . self::S_INP . '"><option value="">' . self::e($this->t('all')) . '</option>'; foreach ($opts as $v => $l) $o .= '<option value="' . self::e($v) . '"' . ((string) $v === (string) $cur ? ' selected' : '') . '>' . self::e($l) . '</option>'; return $o . '</select>'; };
        $stOpts = []; foreach (Board::STAGES as $k) $stOpts[$k] = $this->t('stage.' . $k);
        $pOpts = []; foreach (array_keys(self::PARTY) as $p) $pOpts[$p] = $this->t('party.' . $p);
        $eOpts = []; foreach ($sum['byEditor'] as $eid => $e) $eOpts[$eid] = $e['name'];
        $h .= '<div style="' . self::S_BOX . '"><form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px">'
            . $sel('stage', $stOpts, $f['stage']) . $sel('party', $pOpts, $f['party']) . $sel('editor', $eOpts, $f['editor'] ?: '')
            . '<label style="font-size:13px"><input type="checkbox" name="late" value="1"' . ($f['late'] ? ' checked' : '') . '> ' . $this->t('lateOnly') . '</label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('filter') . '</button><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">' . $this->t('reset') . '</a>'
            . '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'csv')) . '">CSV</a><span style="font-size:12px;color:#666">' . $this->t('showing', ['n' => count($rows), 't' => count($all)]) . '</span></form>';

        // ---- الجدول
        if (!$rows) $h .= '<p>' . $this->t('none') . '</p>';
        else {
            $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th><th style="' . self::S_TH . '">' . $this->t('col.stage') . '</th>'
                . '<th style="' . self::S_TH . '">' . $this->t('col.waiting') . '</th><th style="' . self::S_TH . '">' . $this->t('col.wait') . '</th><th style="' . self::S_TH . '">' . $this->t('col.total') . '</th><th style="' . self::S_TH . '">' . $this->t('col.editors') . '</th><th style="' . self::S_TH . '">' . $this->t('col.reviews') . '</th><th style="' . self::S_TH . '"></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $col = self::PARTY[$r['party']] ?? '#555';
                $rev = $r['stage'] === 3 ? $this->t('revSummary', $r['rev']) : '—';
                $act = '<a style="' . self::S_BTN . '" href="' . self::e($this->wf($request, $r['id'])) . '">' . $this->t('open') . '</a>';
                if (!$this->plugin->readOnly() && $r['party'] === 'author') $act .= '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'remind', ['submissionId' => $r['id'], 'to' => 'author'])) . '">✉ ' . $this->t('remindAuthor') . '</a>';
                if (!$this->plugin->readOnly() && $r['party'] === 'editor' && $r['editorIds']) $act .= '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'remind', ['submissionId' => $r['id'], 'to' => 'editor'])) . '">✉ ' . $this->t('remindEditor') . '</a>';
                $h .= '<tr style="' . ($r['overdue'] ? 'background:#fff6f6' : '') . '"><td style="' . self::S_TD . '">' . $r['id'] . '</td><td style="' . self::S_TD . 'min-width:220px"><b>' . self::e(mb_strimwidth($r['title'], 0, 110, '…')) . '</b><div style="font-size:11px;color:#777">' . self::e($r['section']) . '</div></td>'
                    . '<td style="' . self::S_TD . 'white-space:nowrap">' . $this->t('stage.' . $r['stageKey']) . '<div style="font-size:11px;color:#777">' . $this->t('since', ['d' => $r['stageSince']]) . '</div></td>'
                    . '<td style="' . self::S_TD . '"><span style="display:inline-block;padding:1px 8px;border-radius:10px;background:' . $col . ';color:#fff;font-size:12px;font-weight:700;white-space:nowrap">' . $this->t('party.' . $r['party']) . '</span><div style="font-size:12px;margin-top:3px">' . $this->t('wait.' . $r['waiting']) . ($r['detail'] !== '' ? ' (' . self::e($r['detail']) . ')' : '') . '</div></td>'
                    . '<td style="' . self::S_TD . 'white-space:nowrap;font-weight:700;color:' . ($r['overdue'] ? '#b02a2a' : '#1f2933') . '">' . ($r['overdue'] ? '⚠ ' : '') . $this->t('daysN', ['n' => $r['waitDays']]) . '</td>'
                    . '<td style="' . self::S_TD . 'white-space:nowrap">' . $this->t('daysN', ['n' => $r['daysTotal']]) . '</td><td style="' . self::S_TD . '">' . self::e(implode('، ', $r['editors']) ?: '—') . '</td>'
                    . '<td style="' . self::S_TD . 'font-size:12px">' . $rev . '</td><td style="' . self::S_TD . 'white-space:nowrap">' . $act . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('note') . '</div></div>';
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request, $cfg);
        $this->show($request, $this->t('menu'), $h);
    }

    private function settingsForm($request, array $cfg): string
    {
        $num = fn ($n, $k) => '<label style="display:inline-block;margin:4px 12px 4px 0;font-size:13px">' . $this->t('set.' . $n) . '<br><input type="number" min="1" max="365" name="' . $n . '" value="' . (int) $cfg['sla'][$k] . '" style="' . self::S_INP . 'width:90px"></label>';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary><form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:8px">' . $this->csrf($request)
            . '<div style="font-weight:700;margin-bottom:4px">' . $this->t('set.slaTitle') . '</div>'
            . $num('slaAssign', 'assign') . $num('slaDesk', 'desk') . $num('slaInvite', 'invite') . $num('slaDecision', 'decision') . $num('slaRevision', 'revision') . $num('slaCopyedit', 'copyedit') . $num('slaProduction', 'production') . $num('minReviewers', 'minReviewers')
            . ($this->plugin->readOnly() ? '<p style="font-size:12px;color:#8a5a00;margin:8px 0">' . $this->t('readOnlyMode') . '</p>' : ''
            . '<label style="display:block;margin:8px 0 4px"><input type="checkbox" name="digest" value="1"' . ($cfg['digest'] ? ' checked' : '') . '> ' . $this->t('set.digest') . '</label>'
            . '<label style="display:block;margin:4px 0"><input type="checkbox" name="digestManagers" value="1"' . ($cfg['digestManagers'] ? ' checked' : '') . '> ' . $this->t('set.digestManagers') . '</label>')
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>'
            . ($this->plugin->readOnly() ? '' : '<form method="post" action="' . self::e($this->url($request, 'runDigest')) . '" style="margin-top:8px">' . $this->csrf($request) . '<button style="' . self::S_BTN . '">' . $this->t('runDigest') . '</button> <span style="font-size:12px;color:#666">' . $this->t('lastDigest', ['d' => $cfg['lastDigest'] ?: '—']) . '</span></form>') . '</details>';
    }

    public function saveSettings($args, $request)
    {
        $this->post($request, true);
        $cid = $this->cid($request);
        foreach (['slaAssign', 'slaDesk', 'slaInvite', 'slaDecision', 'slaRevision', 'slaCopyedit', 'slaProduction', 'minReviewers'] as $k) $this->plugin->updateSetting($cid, $k, max(1, min(365, (int) $request->getUserVar($k))), 'int');
        $this->plugin->updateSetting($cid, 'digest', (bool) $request->getUserVar('digest'), 'bool');
        $this->plugin->updateSetting($cid, 'digestManagers', (bool) $request->getUserVar('digestManagers'), 'bool');
        $request->redirectUrl($this->url($request, 'index', ['saved' => 1]));
    }

    public function runDigest($args, $request)
    {
        $this->noWrite();
        $this->post($request, true);
        $this->plugin->updateSetting($this->cid($request), 'lastDigest', date('Y-m-d'), 'string');
        $n = (new Digest($this->plugin))->send($request->getContext());
        $request->redirectUrl($this->url($request, 'index', ['digested' => $n]));
    }

    public function csv($args, $request)
    {
        $this->gate($request);
        $rows = $this->plugin->visible($request, $this->plugin->board($this->cid($request), Locale::getLocale()));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="editorial-board-' . date('Ymd') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['ID', 'Title', 'Stage', 'Stage since', 'Waiting on', 'Waiting for', 'Days waiting', 'Overdue', 'Days since submission', 'Editors', 'Reviews done/active']);
        foreach ($rows as $r) fputcsv($o, [$r['id'], $r['title'], $r['stageKey'], $r['stageSince'], $r['party'], $this->t('wait.' . $r['waiting']), $r['waitDays'], $r['overdue'] ? 'yes' : 'no', $r['daysTotal'], implode('; ', $r['editors']), $r['stage'] === 3 ? $r['rev']['completed'] . '/' . ($r['rev']['invited'] - $r['rev']['declined']) : '']);
        fclose($o); exit;
    }

    // ================================================================ التذكير
    private function recipients($request, $sub, string $to): array
    {
        $out = [];
        if ($to === 'author') {
            $pub = $sub->getCurrentPublication();
            foreach ($pub->getData('authors') ?? [] as $a) if ((int) $a->getId() === (int) $pub->getData('primaryContactId') && $a->getData('email')) $out[] = [$a->getData('email'), $a->getFullName(), $a->getFullName(true, false, 'en')];
        } else {
            $row = null;
            foreach ($this->plugin->board($this->cid($request)) as $r) if ($r['id'] === (int) $sub->getId()) $row = $r;
            foreach ($row['editorIds'] ?? [] as $uid) { $u = Repo::user()->get($uid); if ($u && $u->getEmail()) $out[] = [$u->getEmail(), $u->getFullName(), $u->getFullName(true, false, 'en')]; }
        }
        return $out;
    }

    public function remind($args, $request)
    {
        $this->noWrite();
        $this->gate($request);
        $to = $request->getUserVar('to') === 'editor' ? 'editor' : 'author';
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $this->cid($request)) { http_response_code(404); exit; }
        $rcpt = $this->recipients($request, $sub, $to);
        $pub = $sub->getCurrentPublication();
        $row = null; foreach ($this->plugin->board($this->cid($request)) as $r) if ($r['id'] === (int) $sub->getId()) $row = $r;
        $p = ['name' => $rcpt[0][1] ?? '', 'title' => strip_tags((string) $pub->getLocalizedFullTitle('ar')), 'id' => $sub->getId(), 'days' => $row['waitDays'] ?? 0, 'journal' => $request->getContext()->getLocalizedName()];
        $pEn = ['name' => $rcpt[0][2] ?? '', 'title' => strip_tags((string) $pub->getLocalizedFullTitle('en'))] + $p;
        $body = $this->t('mail.' . $to . '.body', $p, 'ar') . "\n\n— — —\n\n" . $this->t('mail.' . $to . '.body', $pEn, 'en');
        $subject = $this->t('mail.' . $to . '.subject', $p, 'ar') . ' | ' . $this->t('mail.' . $to . '.subject', $pEn, 'en');
        $list = implode('، ', array_map(fn ($r) => self::e($r[1]) . ' &lt;' . self::e($r[0]) . '&gt;', $rcpt));
        $h = '<div style="margin-bottom:12px"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a></div><div style="' . self::S_BOX . '">'
            . '<p style="margin-top:0"><b>#' . (int) $sub->getId() . '</b> ' . self::e(strip_tags((string) $pub->getLocalizedFullTitle())) . '</p>'
            . ($rcpt ? '<form method="post" action="' . self::e($this->url($request, 'sendRemind')) . '">' . $this->csrf($request) . '<input type="hidden" name="submissionId" value="' . (int) $sub->getId() . '"><input type="hidden" name="to" value="' . $to . '">'
                . '<div style="margin-bottom:6px">' . $this->t('to') . ': ' . $list . '</div><input name="subject" value="' . self::e($subject) . '" style="' . self::S_INP . 'width:100%;margin-bottom:6px">'
                . '<textarea name="body" rows="14" style="' . self::S_INP . 'width:100%;font-size:14px;line-height:1.7">' . self::e($body) . '</textarea>'
                . '<div style="margin-top:8px"><button style="' . self::S_BTNP . '">✉ ' . $this->t('send') . '</button> <span style="font-size:12px;color:#555">' . $this->t('mailNote') . '</span></div></form>'
                : '<p style="color:#b02a2a">' . $this->t('noRecipient') . '</p>') . '</div>';
        $this->show($request, $this->t('remindTitle'), $h);
    }

    public function sendRemind($args, $request)
    {
        $this->noWrite();
        $this->post($request);
        $to = $request->getUserVar('to') === 'editor' ? 'editor' : 'author';
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $this->cid($request)) { http_response_code(404); exit; }
        $context = $request->getContext(); $n = 0;
        $html = '<div dir="auto" style="font-family:Tahoma,Arial,sans-serif;line-height:1.7">' . nl2br(self::e(trim((string) $request->getUserVar('body')))) . '</div>';
        foreach ($this->recipients($request, $sub, $to) as [$email, $name]) {
            $m = new Mailable([$context, $sub]);
            $m->from((string) $context->getData('contactEmail'), (string) $context->getData('contactName'))->to($email, $name)
                ->subject(mb_substr(trim((string) $request->getUserVar('subject')), 0, 250))->body($html);
            Mail::send($m);
            Repo::emailLogEntry()->logMailable($to === 'author' ? SubmissionEmailLogEventType::EDITOR_NOTIFY_AUTHOR : SubmissionEmailLogEventType::NEEDS_EDITOR, $m, $sub, $request->getUser());
            $n++;
        }
        $request->redirectUrl($this->url($request, 'index', ['sent' => $n]));
    }
}
