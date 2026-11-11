<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/integrityLog/pages/IntegrityHandler.php
 * المسارات (للمحررين): /integrity، /integrity/view?caseId=، و POST: createCase، updateCase، addEvent، saveSteps، addNotice، toggleNotice
 * المسار العام: /integrity/notices
 */
namespace APP\plugins\generic\tadabburSuite\m\integrityLog\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\integrityLog\classes\Store;
use APP\plugins\generic\tadabburSuite\m\integrityLog\IntegrityLogPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Role;
use PKP\security\Validation;

class IntegrityHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const ST_COL = ['open' => '#b02a2a', 'investigating' => '#a86a00', 'awaitingResponse' => '#33658a', 'referred' => '#6b4fa0', 'closed' => '#1e7b4a'];

    private Store $store;

    public function __construct(private IntegrityLogPlugin $plugin) { parent::__construct(); $this->store = new Store(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.integrityLog.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, IntegrityLogPlugin::PAGE, $op, null, $params);
    }
    private function gate($request): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!$this->plugin->canView($request)) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }
    private function post($request): void
    {
        $this->gate($request);
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

    private function select(string $name, array $values, string $current, string $prefix, string $style = ''): string
    {
        $o = '<select name="' . $name . '" style="' . self::S_INP . $style . '">';
        foreach ($values as $v) $o .= '<option value="' . self::e($v) . '"' . ($v === $current ? ' selected' : '') . '>' . self::e($v === '' ? '—' : $this->t($prefix . $v)) . '</option>';
        return $o . '</select>';
    }

    private function statusBadge(string $s): string
    {
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:' . (self::ST_COL[$s] ?? '#555') . ';color:#fff;font-weight:700;font-size:12px;white-space:nowrap">' . self::e($this->t('status.' . $s)) . '</span>';
    }

    private function subTitle(?int $sid): string
    {
        if (!$sid) return '—';
        $s = Repo::submission()->get($sid);
        return $s && $s->getCurrentPublication() ? strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()) : ('#' . $sid);
    }

    private function editors($request): array
    {
        $out = [];
        $users = Repo::user()->getCollector()->filterByContextIds([$this->cid($request)])->filterByRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])->getMany();
        foreach ($users as $u) $out[(int) $u->getId()] = $u->getFullName();
        return $out;
    }

    // ================================================================ القائمة
    public function index($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request);
        $filter = in_array($request->getUserVar('filter'), ['active', 'closed', 'all'], true) ? $request->getUserVar('filter') : 'active';
        $cases = $this->store->cases($cid, $filter === 'all' ? null : $filter);
        $st = $this->store->stats($cid);
        $card = fn ($v, $l, $col) => '<div style="flex:1;min-width:150px;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:26px;font-weight:700;color:' . $col . '">' . $v . '</div><div style="font-size:13px">' . $l . '</div></div>';
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>'
            . '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">' . $card($st['active'], $this->t('stat.active'), $st['active'] ? '#b02a2a' : '#8a949e')
            . $card($st['closed'], $this->t('stat.closed'), '#1e7b4a') . $card(array_sum($st['notices']), $this->t('stat.notices'), '#0b4f4a')
            . $card((int) ($st['notices']['retraction'] ?? 0), $this->t('stat.retractions'), '#7a1010') . '</div>';

        // ---- حالة جديدة
        $h .= '<details style="' . self::S_BOX . '"' . (!$cases && $filter === 'active' ? ' open' : '') . '><summary style="cursor:pointer;font-weight:700;font-size:15px">＋ ' . $this->t('newCase') . '</summary>'
            . '<form method="post" action="' . self::e($this->url($request, 'createCase')) . '" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end">' . $this->csrf($request)
            . '<label style="grid-column:1/-1">' . $this->t('f.title') . '<br><input name="title" required maxlength="250" style="' . self::S_INP . 'width:100%"></label>'
            . '<label>' . $this->t('f.type') . '<br>' . $this->select('caseType', Store::TYPES, 'plagiarism', 'type.', 'width:100%') . '</label>'
            . '<label>' . $this->t('f.source') . '<br>' . $this->select('source', Store::SOURCES, 'editor', 'source.', 'width:100%') . '</label>'
            . '<label>' . $this->t('f.submission') . '<br><input name="submissionId" type="number" min="1" placeholder="ID" style="' . self::S_INP . 'width:100%"></label>'
            . '<label style="grid-column:1/-1">' . $this->t('f.summary') . '<br><textarea name="summary" rows="3" style="' . self::S_INP . 'width:100%"></textarea></label>'
            . '<div><button style="' . self::S_BTNP . '">' . $this->t('create') . '</button></div></form>'
            . '<div style="font-size:12px;color:#555;margin-top:8px">🔒 ' . $this->t('confidential') . '</div></details>';

        // ---- الجدول
        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px"><h2 style="margin:0;font-size:17px">' . $this->t('cases') . '</h2><div>';
        foreach (['active', 'closed', 'all'] as $f) $h .= '<a style="' . ($f === $filter ? self::S_BTNP : self::S_BTN) . '" href="' . self::e($this->url($request, 'index', ['filter' => $f])) . '">' . $this->t('filter.' . $f) . '</a>';
        $h .= '<a style="' . self::S_BTN . '" target="_blank" href="' . self::e($this->url($request, 'notices')) . '">' . $this->t('publicPage') . ' ↗</a></div></div>';
        if (!$cases) $h .= '<p>' . $this->t('noCases') . '</p>';
        else {
            $ed = $this->editors($request);
            $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:60px">' . $this->t('col.id') . '</th><th style="' . self::S_TH . '">' . $this->t('col.case') . '</th><th style="' . self::S_TH . '">' . $this->t('col.submission') . '</th>'
                . '<th style="' . self::S_TH . 'width:120px">' . $this->t('col.status') . '</th><th style="' . self::S_TH . 'width:120px">' . $this->t('col.outcome') . '</th><th style="' . self::S_TH . 'width:130px">' . $this->t('col.assigned') . '</th><th style="' . self::S_TH . 'width:110px">' . $this->t('col.updated') . '</th></tr></thead><tbody>';
            foreach ($cases as $c) {
                $h .= '<tr><td style="' . self::S_TD . '">IC-' . (int) $c->case_id . '</td><td style="' . self::S_TD . '"><a href="' . self::e($this->url($request, 'view', ['caseId' => $c->case_id])) . '"><b>' . self::e($c->title) . '</b></a><div style="font-size:12px;color:#666">' . self::e($this->t('type.' . $c->case_type)) . ' · ' . self::e($this->t('source.' . $c->source)) . '</div></td>'
                    . '<td style="' . self::S_TD . '">' . self::e(mb_strimwidth($this->subTitle($c->submission_id ? (int) $c->submission_id : null), 0, 60, '…')) . '</td><td style="' . self::S_TD . '">' . $this->statusBadge($c->status) . '</td>'
                    . '<td style="' . self::S_TD . '">' . self::e($c->outcome !== '' ? $this->t('outcome.' . $c->outcome) : '—') . '</td><td style="' . self::S_TD . '">' . self::e($ed[(int) $c->assigned_to] ?? '—') . '</td>'
                    . '<td style="' . self::S_TD . '">' . self::e(substr((string) $c->date_updated, 0, 10)) . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '</div><div style="font-size:12px;color:#555">' . $this->t('copeNote') . '</div>';
        $this->show($request, $this->t('menu'), $h);
    }

    public function createCase($args, $request)
    {
        $this->post($request);
        $cid = $this->cid($request);
        $sid = (int) $request->getUserVar('submissionId');
        if ($sid) { $s = Repo::submission()->get($sid); if (!$s || (int) $s->getData('contextId') !== $cid) $sid = 0; }
        $type = in_array($request->getUserVar('caseType'), Store::TYPES, true) ? $request->getUserVar('caseType') : 'other';
        $source = in_array($request->getUserVar('source'), Store::SOURCES, true) ? $request->getUserVar('source') : 'editor';
        $title = mb_substr(trim((string) $request->getUserVar('title')), 0, 250) ?: $this->t('type.' . $type);
        $uid = (int) $request->getUser()->getId();
        $id = $this->store->addCase(['context_id' => $cid, 'submission_id' => $sid ?: null, 'title' => $title, 'case_type' => $type, 'source' => $source,
            'summary' => mb_substr(trim((string) $request->getUserVar('summary')), 0, 5000), 'created_by' => $uid, 'assigned_to' => $uid]);
        $this->store->addEvent($id, $uid, 'opened', $this->t('ev.opened', ['type' => $this->t('type.' . $type)]));
        $request->redirectUrl($this->url($request, 'view', ['caseId' => $id]));
    }

    // ================================================================ الحالة
    public function view($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request);
        $c = $this->store->getCase((int) $request->getUserVar('caseId'), $cid);
        if (!$c) { http_response_code(404); echo self::e($this->t('notFound')); exit; }
        $ed = $this->editors($request);
        $h = '<div style="margin-bottom:12px"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a></div>';
        $sub = $c->submission_id ? Repo::submission()->get((int) $c->submission_id) : null;
        $subLink = $sub ? '<a href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $sub->getId()])) . '">' . self::e($this->subTitle((int) $sub->getId())) . '</a> (#' . (int) $sub->getId() . ')' : '—';
        $h .= '<div style="' . self::S_BOX . 'border-top:4px solid ' . (self::ST_COL[$c->status] ?? '#555') . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px"><div><div style="font-size:12px;color:#666">IC-' . (int) $c->case_id . ' · ' . self::e($this->t('type.' . $c->case_type)) . ' · ' . self::e($this->t('source.' . $c->source)) . '</div>'
            . '<h2 style="margin:4px 0;font-size:19px">' . self::e($c->title) . '</h2><div>' . $this->t('col.submission') . ': ' . $subLink . '</div></div><div>' . $this->statusBadge($c->status) . '</div></div>'
            . ($c->summary ? '<p style="white-space:pre-wrap;background:#f8f9fa;padding:8px;border-radius:6px">' . self::e($c->summary) . '</p>' : '')
            . '<div style="font-size:12px;color:#666">' . $this->t('dates', ['o' => substr((string) $c->date_opened, 0, 10), 'u' => substr((string) $c->date_updated, 0, 16), 'c' => $c->date_closed ? substr((string) $c->date_closed, 0, 10) : '—']) . '</div></div>';

        // ---- تحديث الحالة
        $opts = '<option value="">—</option>';
        foreach ($ed as $id => $n) $opts .= '<option value="' . $id . '"' . ((int) $c->assigned_to === $id ? ' selected' : '') . '>' . self::e($n) . '</option>';
        $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">';
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 8px;font-size:15px">' . $this->t('manage') . '</h3><form method="post" action="' . self::e($this->url($request, 'updateCase')) . '">' . $this->csrf($request)
            . '<input type="hidden" name="caseId" value="' . (int) $c->case_id . '">'
            . '<label style="display:block;margin-bottom:6px">' . $this->t('col.status') . '<br>' . $this->select('status', Store::STATUSES, (string) $c->status, 'status.', 'width:100%') . '</label>'
            . '<label style="display:block;margin-bottom:6px">' . $this->t('col.outcome') . '<br>' . $this->select('outcome', Store::OUTCOMES, (string) $c->outcome, 'outcome.', 'width:100%') . '</label>'
            . '<label style="display:block;margin-bottom:6px">' . $this->t('col.assigned') . '<br><select name="assignedTo" style="' . self::S_INP . 'width:100%">' . $opts . '</select></label>'
            . '<label style="display:block;margin-bottom:6px">' . $this->t('f.summary') . '<br><textarea name="summary" rows="3" style="' . self::S_INP . 'width:100%">' . self::e($c->summary) . '</textarea></label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></div>';

        // ---- خطوات COPE
        $steps = array_values(array_filter(array_map('trim', explode('|', $this->t('steps.' . $c->case_type)))));
        $done = (array) (json_decode((string) $c->steps, true) ?: []);
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 4px;font-size:15px">' . $this->t('copeSteps') . '</h3><div style="font-size:12px;color:#666;margin-bottom:8px">' . $this->t('copeStepsNote') . '</div>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveSteps')) . '">' . $this->csrf($request) . '<input type="hidden" name="caseId" value="' . (int) $c->case_id . '">';
        foreach ($steps as $i => $s) {
            $h .= '<label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0"><input type="checkbox" name="steps[]" value="' . $i . '"' . (in_array($i, $done, true) ? ' checked' : '') . ' style="margin-top:4px"><span' . (in_array($i, $done, true) ? ' style="color:#1e7b4a"' : '') . '>' . ($i + 1) . '. ' . self::e($s) . '</span></label>';
        }
        $h .= '<button style="' . self::S_BTN . '">' . $this->t('saveSteps') . '</button> <a href="https://publicationethics.org/guidance/Flowcharts" target="_blank" rel="noopener" style="font-size:12px">COPE Flowcharts ↗</a></form></div></div>';

        // ---- الخط الزمني
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 8px;font-size:15px">' . $this->t('timeline') . '</h3>'
            . '<form method="post" action="' . self::e($this->url($request, 'addEvent')) . '" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px">' . $this->csrf($request) . '<input type="hidden" name="caseId" value="' . (int) $c->case_id . '">'
            . $this->select('kind', ['note', 'contactAuthor', 'response', 'contactInstitution', 'evidence', 'decision'], 'note', 'kind.', 'width:180px')
            . '<textarea name="body" required rows="2" style="' . self::S_INP . 'flex:1;min-width:240px"></textarea><button style="' . self::S_BTNP . '">' . $this->t('addEvent') . '</button></form><ol style="list-style:none;margin:0;padding:0">';
        foreach ($this->store->events((int) $c->case_id) as $ev) {
            $u = $ev->user_id ? Repo::user()->get((int) $ev->user_id, true) : null;
            $h .= '<li style="border-inline-start:3px solid #d5dbe1;padding:4px 10px;margin:0 0 8px"><div style="font-size:12px;color:#666">' . self::e(substr((string) $ev->date_created, 0, 16)) . ' · ' . self::e($u ? $u->getFullName() : '—') . ' · <b>' . self::e($this->t('kind.' . $ev->kind)) . '</b></div><div style="white-space:pre-wrap">' . self::e($ev->body) . '</div></li>';
        }
        $h .= '</ol></div>';

        // ---- الإشعارات العامة
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 8px;font-size:15px">' . $this->t('notices') . '</h3>';
        foreach ($this->store->noticesForCase((int) $c->case_id) as $n) {
            $b = json_decode((string) $n->body, true) ?: [];
            $h .= '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px;margin-bottom:8px"><b>' . self::e($this->t('notice.' . $n->notice_type)) . '</b> — ' . self::e($n->date_notice) . ' · ' . ((int) $n->is_public ? '<span style="color:#1e7b4a">' . $this->t('public') . '</span>' : '<span style="color:#888">' . $this->t('hidden') . '</span>')
                . '<form method="post" action="' . self::e($this->url($request, 'toggleNotice')) . '" style="display:inline;margin-inline-start:8px">' . $this->csrf($request) . '<input type="hidden" name="noticeId" value="' . (int) $n->notice_id . '"><input type="hidden" name="caseId" value="' . (int) $c->case_id . '"><button style="' . self::S_BTN . 'padding:1px 8px">' . $this->t((int) $n->is_public ? 'hide' : 'publish') . '</button></form>'
                . '<div style="white-space:pre-wrap;margin-top:4px">' . self::e($b['ar'] ?? '') . '</div><div dir="ltr" style="white-space:pre-wrap;color:#444">' . self::e($b['en'] ?? '') . '</div></div>';
        }
        if ($sub) {
            $h .= '<details><summary style="cursor:pointer;font-weight:700">＋ ' . $this->t('newNotice') . '</summary><form method="post" action="' . self::e($this->url($request, 'addNotice')) . '" style="margin-top:8px">' . $this->csrf($request)
                . '<input type="hidden" name="caseId" value="' . (int) $c->case_id . '"><div style="display:flex;gap:10px;flex-wrap:wrap">'
                . '<label>' . $this->t('f.noticeType') . '<br>' . $this->select('noticeType', Store::NOTICE_TYPES, 'correction', 'notice.') . '</label>'
                . '<label>' . $this->t('f.date') . '<br><input type="date" name="date" value="' . date('Y-m-d') . '" style="' . self::S_INP . '"></label>'
                . '<label>' . $this->t('f.doi') . '<br><input name="doi" dir="ltr" placeholder="10.xxxx/…" style="' . self::S_INP . '"></label></div>'
                . '<label style="display:block;margin-top:6px">' . $this->t('f.textAr') . '<textarea name="bodyAr" rows="3" style="' . self::S_INP . 'width:100%"></textarea></label>'
                . '<label style="display:block;margin-top:6px">' . $this->t('f.textEn') . '<textarea name="bodyEn" dir="ltr" rows="3" style="' . self::S_INP . 'width:100%"></textarea></label>'
                . '<div style="font-size:12px;color:#555;margin:6px 0">' . $this->t('noticeHelp') . '</div><button style="' . self::S_BTNP . '">' . $this->t('issueNotice') . '</button></form></details>';
        } else {
            $h .= '<p style="color:#666">' . $this->t('noticeNeedsSubmission') . '</p>';
        }
        $h .= '</div>';
        $this->show($request, $this->t('caseTitle'), $h);
    }

    private function caseOr404($request): object
    {
        $c = $this->store->getCase((int) $request->getUserVar('caseId'), $this->cid($request));
        if (!$c) { http_response_code(404); exit; }
        return $c;
    }

    public function updateCase($args, $request)
    {
        $this->post($request);
        $c = $this->caseOr404($request); $uid = (int) $request->getUser()->getId();
        $status = in_array($request->getUserVar('status'), Store::STATUSES, true) ? $request->getUserVar('status') : $c->status;
        $outcome = in_array($request->getUserVar('outcome'), Store::OUTCOMES, true) ? $request->getUserVar('outcome') : $c->outcome;
        $assigned = (int) $request->getUserVar('assignedTo');
        if ($assigned && !isset($this->editors($request)[$assigned])) $assigned = (int) $c->assigned_to;
        $row = ['status' => $status, 'outcome' => $outcome, 'assigned_to' => $assigned ?: null, 'summary' => mb_substr(trim((string) $request->getUserVar('summary')), 0, 5000)];
        if ($status === 'closed' && $c->status !== 'closed') $row['date_closed'] = date('Y-m-d H:i:s');
        if ($status !== 'closed') $row['date_closed'] = null;
        $this->store->updateCase((int) $c->case_id, $this->cid($request), $row);
        if ($status !== $c->status) $this->store->addEvent((int) $c->case_id, $uid, 'status', $this->t('ev.status', ['from' => $this->t('status.' . $c->status), 'to' => $this->t('status.' . $status)]));
        if ($outcome !== $c->outcome && $outcome !== '') $this->store->addEvent((int) $c->case_id, $uid, 'decision', $this->t('ev.outcome', ['o' => $this->t('outcome.' . $outcome)]));
        $request->redirectUrl($this->url($request, 'view', ['caseId' => $c->case_id]));
    }

    public function addEvent($args, $request)
    {
        $this->post($request);
        $c = $this->caseOr404($request);
        $kind = in_array($request->getUserVar('kind'), ['note', 'contactAuthor', 'response', 'contactInstitution', 'evidence', 'decision'], true) ? $request->getUserVar('kind') : 'note';
        $body = mb_substr(trim((string) $request->getUserVar('body')), 0, 10000);
        if ($body !== '') {
            $this->store->addEvent((int) $c->case_id, (int) $request->getUser()->getId(), $kind, $body);
            $this->store->updateCase((int) $c->case_id, $this->cid($request), []);
        }
        $request->redirectUrl($this->url($request, 'view', ['caseId' => $c->case_id]));
    }

    public function saveSteps($args, $request)
    {
        $this->post($request);
        $c = $this->caseOr404($request);
        $steps = array_values(array_unique(array_map('intval', (array) $request->getUserVar('steps'))));
        sort($steps);
        $this->store->updateCase((int) $c->case_id, $this->cid($request), ['steps' => json_encode($steps)]);
        $request->redirectUrl($this->url($request, 'view', ['caseId' => $c->case_id]));
    }

    public function addNotice($args, $request)
    {
        $this->post($request);
        $c = $this->caseOr404($request);
        if (!$c->submission_id) { $request->redirectUrl($this->url($request, 'view', ['caseId' => $c->case_id])); return; }
        $type = in_array($request->getUserVar('noticeType'), Store::NOTICE_TYPES, true) ? $request->getUserVar('noticeType') : 'correction';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->getUserVar('date')) ? $request->getUserVar('date') : date('Y-m-d');
        $body = ['ar' => mb_substr(trim((string) $request->getUserVar('bodyAr')), 0, 8000), 'en' => mb_substr(trim((string) $request->getUserVar('bodyEn')), 0, 8000)];
        if ($body['ar'] === '' && $body['en'] === '') $body['ar'] = $this->t('notice.' . $type . '.default');
        $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', trim((string) $request->getUserVar('doi')));
        $this->store->addNotice(['context_id' => $this->cid($request), 'submission_id' => (int) $c->submission_id, 'case_id' => (int) $c->case_id, 'notice_type' => $type,
            'date_notice' => $date, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'notice_doi' => mb_substr((string) $doi, 0, 250), 'is_public' => 1]);
        $this->store->addEvent((int) $c->case_id, (int) $request->getUser()->getId(), 'decision', $this->t('ev.notice', ['t' => $this->t('notice.' . $type)]));
        $request->redirectUrl($this->url($request, 'view', ['caseId' => $c->case_id]));
    }

    public function toggleNotice($args, $request)
    {
        $this->post($request);
        $cid = $this->cid($request);
        $id = (int) $request->getUserVar('noticeId');
        $n = \Illuminate\Support\Facades\DB::table(Store::NOTICES)->where('notice_id', $id)->where('context_id', $cid)->first();
        if ($n) $this->store->updateNotice($id, $cid, ['is_public' => (int) $n->is_public ? 0 : 1]);
        $request->redirectUrl($this->url($request, 'view', ['caseId' => (int) $request->getUserVar('caseId')]));
    }

    // ================================================================ الصفحة العامة
    public function notices($args, $request)
    {
        $context = $request->getContext();
        $list = $this->store->notices((int) $context->getId(), null, true);
        $h = '<p>' . $this->t('publicIntro') . '</p>';
        if (!$list) $h .= '<p><em>' . $this->t('publicNone') . '</em></p>';
        foreach ($list as $n) {
            $sub = Repo::submission()->get((int) $n->submission_id);
            if (!$sub || (int) $sub->getData('contextId') !== (int) $context->getId()) continue;
            $pub = $sub->getCurrentPublication();
            $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$sub->getBestId()]);
            $h .= '<article style="border:1px solid #e1d9cc;border-inline-start:5px solid ' . (in_array($n->notice_type, ['retraction', 'removal'], true) ? '#7a1010' : ($n->notice_type === 'concern' ? '#7a4d00' : '#0b4f4a')) . ';border-radius:8px;padding:12px 16px;margin:0 0 14px;background:#fff">'
                . '<div style="font-size:.85rem;color:#6b625a">' . self::e($this->t('notice.' . $n->notice_type)) . ' · ' . self::e($n->date_notice) . '</div>'
                . '<h3 style="margin:.3rem 0;font-size:1.05rem"><a href="' . self::e($url) . '">' . self::e($pub ? strip_tags((string) $pub->getLocalizedFullTitle()) : '#' . $n->submission_id) . '</a></h3>'
                . '<div>' . nl2br(self::e(IntegrityLogPlugin::localized((string) $n->body, (string) $sub->getData('locale')))) . '</div>'
                . ($n->notice_doi ? '<div dir="ltr" style="text-align:start"><a href="https://doi.org/' . self::e($n->notice_doi) . '">https://doi.org/' . self::e($n->notice_doi) . '</a></div>' : '') . '</article>';
        }
        $h .= '<p style="font-size:.85rem;color:#6b625a">' . $this->t('publicCope') . '</p>';
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['ilTitle' => $this->t('publicTitle'), 'ilHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('public.tpl'));
    }
}
