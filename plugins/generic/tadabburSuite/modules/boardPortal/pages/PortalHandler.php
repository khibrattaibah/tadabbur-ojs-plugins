<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardPortal/pages/PortalHandler.php
 * للمحررين: index (الأعضاء + لمحة)، meetings، meeting، report ؛ POST للمدير: saveMember، saveMeeting، saveAttendance، addResolution، setResolution
 * للعضو: mine ؛ عامة: board
 */
namespace APP\plugins\generic\tadabburSuite\m\boardPortal\pages;

use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\boardPortal\BoardPortalPlugin;
use APP\plugins\generic\tadabburSuite\m\boardPortal\classes\Activity;
use APP\plugins\generic\tadabburSuite\m\boardPortal\classes\Store;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class PortalHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';

    private Store $store;

    public function __construct(private BoardPortalPlugin $plugin) { parent::__construct(); $this->store = new Store(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.boardPortal.' . $k, $p); }
    private function url($request, string $op, array $params = []): string { return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, BoardPortalPlugin::PAGE, $op, null, $params); }
    private function cid($request): int { return (int) $request->getContext()->getId(); }
    private function csrf($request): string { return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'; }
    private function login($request): void { if (!$request->getUser()) { Validation::redirectLogin(); exit; } }
    private function deny(): void { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    private function postOk($request): void { if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; } }
    private function editor($request): void { $this->login($request); if (!$this->plugin->isEditor($request)) $this->deny(); }
    private function manager($request): void { $this->login($request); if (!$this->plugin->isManager($request)) $this->deny(); $this->postOk($request); }
    private function year($request): int { $y = (int) $request->getUserVar('year'); return $y >= 2000 && $y <= 2100 ? $y : (int) date('Y'); }
    private function v($request, string $k): string { return trim((string) $request->getUserVar($k)); }
    private function date(?string $s): ?string { return $s !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null; }
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
        $h = '<div style="margin-bottom:14px">';
        foreach (['index' => 'tab.members', 'meetings' => 'tab.meetings', 'resolutions' => 'tab.resolutions', 'report' => 'tab.report'] as $op => $k)
            $h .= '<a style="' . ($op === $cur ? self::S_BTNP : self::S_BTN) . '" href="' . self::e($this->url($request, $op)) . '">' . $this->t($k) . '</a>';
        return $h . '<a style="' . self::S_BTN . '" target="_blank" href="' . self::e($this->url($request, 'board')) . '">' . $this->t('tab.public') . '</a></div>';
    }
    private function back($request, string $op, array $p = [], string $msg = 'saved'): void { $request->redirectUrl($this->url($request, $op, $p + ['m' => $msg])); }
    private function flash($request): string
    {
        $m = (string) $request->getUserVar('m');
        return $m !== '' ? '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2">✔ ' . self::e($this->t('msg.' . $m)) . '</div>' : '';
    }
    private function memberName(object $m): string { return $m->name_ar !== '' ? $m->name_ar : $m->name_en; }
    private function boardLabel(string $b): string { return $this->t('board.' . $b); }

    // ================================================================ الأعضاء
    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $mgr = $this->plugin->isManager($request);
        $edit = (int) $request->getUserVar('edit') ? $this->store->member((int) $request->getUserVar('edit'), $cid) : null;
        $h = $this->nav($request, 'index') . $this->flash($request);
        foreach (Store::BOARDS as $b) {
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->boardLabel($b) . '</h2><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr>';
            foreach (['name', 'role', 'affiliation', 'country', 'term', 'account'] as $c) $h .= '<th style="' . self::S_TH . '">' . $this->t('col.' . $c) . '</th>';
            $h .= '<th style="' . self::S_TH . '"></th></tr></thead><tbody>';
            $rows = $this->store->members($cid, $b);
            foreach ($rows as $m) {
                $h .= '<tr style="' . ($m->active ? '' : 'opacity:.5') . '"><td style="' . self::S_TD . '"><b>' . self::e($this->memberName($m)) . '</b><div style="font-size:11px;color:#777" dir="ltr">' . self::e($m->name_en) . '</div></td>'
                    . '<td style="' . self::S_TD . '">' . self::e($m->role_ar ?: $m->role_en) . '</td><td style="' . self::S_TD . '">' . self::e($m->affiliation_ar ?: $m->affiliation_en) . '</td><td style="' . self::S_TD . '">' . self::e($m->country) . '</td>'
                    . '<td style="' . self::S_TD . '" dir="ltr">' . self::e(($m->term_start ?? '') . ' → ' . ($m->term_end ?? '')) . '</td><td style="' . self::S_TD . '">' . ($m->user_id ? '✔' : '—') . '</td><td style="' . self::S_TD . 'white-space:nowrap">'
                    . '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index', ['edit' => $m->member_id])) . '#form">' . $this->t('edit') . '</a>'
                    . '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'member', ['id' => $m->member_id])) . '">' . $this->t('activity') . '</a></td></tr>';
            }
            if (!$rows) $h .= '<tr><td colspan="7" style="' . self::S_TD . '">' . $this->t('none') . '</td></tr>';
            $h .= '</tbody></table></div></div>';
        }
        if ($mgr) $h .= $this->memberForm($request, $edit);
        $this->show($request, $this->t('menu'), $h);
    }

    private function memberForm($request, ?object $m): string
    {
        $f = fn ($k) => self::e($m->$k ?? '');
        $in = fn ($k, $w = 200, $type = 'text') => '<label style="display:inline-block;margin:4px 8px 4px 0;font-size:12px">' . $this->t('f.' . $k) . '<br><input type="' . $type . '" name="' . $k . '" value="' . $f($k) . '" style="' . self::S_INP . 'width:' . $w . 'px"></label>';
        $h = '<div id="form" style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t($m ? 'editMember' : 'addMember') . '</h2><form method="post" action="' . self::e($this->url($request, 'saveMember')) . '">' . $this->csrf($request)
            . '<input type="hidden" name="id" value="' . (int) ($m->member_id ?? 0) . '">'
            . '<label style="font-size:12px">' . $this->t('f.board') . '<br><select name="board" style="' . self::S_INP . '">';
        foreach (Store::BOARDS as $b) $h .= '<option value="' . $b . '"' . (($m->board ?? 'editorial') === $b ? ' selected' : '') . '>' . $this->boardLabel($b) . '</option>';
        $h .= '</select></label> ' . $in('name_ar') . $in('name_en') . $in('role_ar') . $in('role_en') . $in('affiliation_ar', 240) . $in('affiliation_en', 240) . $in('country', 120) . $in('email', 220, 'email') . $in('orcid', 170)
            . $in('term_start', 140, 'date') . $in('term_end', 140, 'date') . $in('seq', 60, 'number')
            . '<label style="font-size:12px;margin:0 8px"><input type="checkbox" name="active" value="1"' . (!$m || $m->active ? ' checked' : '') . '> ' . $this->t('f.active') . '</label>'
            . '<p style="font-size:12px;color:#666;margin:6px 0">' . $this->t('linkHelp') . '</p><button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></div>';
        return $h;
    }

    public function saveMember($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request);
        $board = in_array($this->v($request, 'board'), Store::BOARDS, true) ? $this->v($request, 'board') : 'editorial';
        $d = ['board' => $board, 'active' => $request->getUserVar('active') ? 1 : 0, 'seq' => (int) $request->getUserVar('seq'),
            'term_start' => $this->date($this->v($request, 'term_start')), 'term_end' => $this->date($this->v($request, 'term_end'))];
        foreach (['name_ar', 'name_en', 'role_ar', 'role_en', 'affiliation_ar', 'affiliation_en', 'country', 'email', 'orcid'] as $k) $d[$k] = mb_substr($this->v($request, $k), 0, 255);
        if ($d['name_ar'] === '' && $d['name_en'] === '') { $this->back($request, 'index', [], 'needName'); return; }
        $d['user_id'] = null;
        $this->store->saveMember($cid, $d, (int) $request->getUserVar('id') ?: null);
        $this->back($request, 'index');
    }

    // ================================================================ نشاط العضو
    private function activityCard($request, object $m, int $year): string
    {
        $a = Activity::forMember($m, $this->cid($request), $year);
        $cells = '';
        foreach (Activity::KEYS as $k) {
            $val = $k === 'meetings' ? $a['meetings'] . ' / ' . $a['meetingsTotal'] : $a[$k];
            $cells .= '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px;min-width:120px"><div style="font-size:22px;font-weight:700">' . $val . '</div><div style="font-size:12px;color:#555">' . $this->t('act.' . $k) . '</div></div>';
        }
        return ($a['linked'] ? '' : '<p style="font-size:12px;color:#8a5a00">' . $this->t('notLinked') . '</p>') . '<div style="display:flex;gap:8px;flex-wrap:wrap">' . $cells . '</div>';
    }

    private function yearForm($request, string $op, array $extra, int $year): string
    {
        $h = '<form method="get" action="' . self::e($this->url($request, $op)) . '" style="margin:0 0 10px">';
        foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . $k . '" value="' . self::e($v) . '">';
        return $h . '<label style="font-size:12px">' . $this->t('year') . ' <input type="number" name="year" value="' . $year . '" style="' . self::S_INP . 'width:90px"></label> <button style="' . self::S_BTN . '">' . $this->t('show') . '</button></form>';
    }

    public function member($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $y = $this->year($request);
        $m = $this->store->member((int) $request->getUserVar('id'), $cid); if (!$m) { http_response_code(404); exit; }
        $h = $this->nav($request, 'index') . '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 4px;font-size:17px">' . self::e($this->memberName($m)) . '</h2><div style="color:#555;margin-bottom:10px">' . self::e($this->boardLabel($m->board) . ' — ' . ($m->role_ar ?: $m->role_en)) . '</div>'
            . $this->yearForm($request, 'member', ['id' => $m->member_id], $y) . $this->activityCard($request, $m, $y) . '</div>'
            . $this->resolutionTable($request, $this->store->resolutions($cid, ['owner' => [$m->member_id]]), false);
        $this->show($request, $this->t('menu'), $h);
    }

    // ================================================================ الاجتماعات
    public function meetings($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $mgr = $this->plugin->isManager($request);
        $h = $this->nav($request, 'meetings') . $this->flash($request);
        if ($mgr) {
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('newMeeting') . '</h2><form method="post" action="' . self::e($this->url($request, 'saveMeeting')) . '">' . $this->csrf($request)
                . '<select name="board" style="' . self::S_INP . '">' . implode('', array_map(fn ($b) => '<option value="' . $b . '">' . $this->boardLabel($b) . '</option>', Store::BOARDS)) . '</select> '
                . '<input name="title" placeholder="' . self::e($this->t('f.title')) . '" style="' . self::S_INP . 'width:240px"> <input type="datetime-local" name="meet_at" required style="' . self::S_INP . '"> '
                . '<input name="location" placeholder="' . self::e($this->t('f.location')) . '" style="' . self::S_INP . 'width:200px"> <button style="' . self::S_BTNP . '">' . $this->t('create') . '</button></form></div>';
        }
        $h .= '<div style="' . self::S_BOX . '"><table style="' . self::S_TBL . '"><thead><tr>';
        foreach (['number', 'board', 'title', 'date', 'status'] as $c) $h .= '<th style="' . self::S_TH . '">' . $this->t('col.' . $c) . '</th>';
        $h .= '<th style="' . self::S_TH . '"></th></tr></thead><tbody>';
        $rows = $this->store->meetings($cid);
        foreach ($rows as $r) $h .= '<tr><td style="' . self::S_TD . '">' . substr($r->meet_at, 0, 4) . '/' . (int) $r->number . '</td><td style="' . self::S_TD . '">' . $this->boardLabel($r->board) . '</td><td style="' . self::S_TD . '">' . self::e($r->title) . '</td><td style="' . self::S_TD . '" dir="ltr">' . self::e(substr($r->meet_at, 0, 16)) . '</td><td style="' . self::S_TD . '">' . $this->t('st.' . $r->status) . '</td><td style="' . self::S_TD . '"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'meeting', ['id' => $r->meeting_id])) . '">' . $this->t('open_') . '</a></td></tr>';
        if (!$rows) $h .= '<tr><td colspan="6" style="' . self::S_TD . '">' . $this->t('none') . '</td></tr>';
        $this->show($request, $this->t('menu'), $h . '</tbody></table></div>');
    }

    public function saveMeeting($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request); $id = (int) $request->getUserVar('id') ?: null;
        $at = str_replace('T', ' ', $this->v($request, 'meet_at')); if (strlen($at) === 16) $at .= ':00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)) { $this->back($request, 'meetings', [], 'badDate'); return; }
        $d = ['title' => mb_substr($this->v($request, 'title'), 0, 255), 'meet_at' => $at, 'location' => mb_substr($this->v($request, 'location'), 0, 255)];
        if ($id) {
            if (!$this->store->meeting($id, $cid)) { http_response_code(404); exit; }
            $d['agenda'] = (string) $request->getUserVar('agenda'); $d['minutes'] = (string) $request->getUserVar('minutes');
            $d['status'] = in_array($this->v($request, 'status'), ['planned', 'held', 'cancelled'], true) ? $this->v($request, 'status') : 'planned';
        } else {
            $d['board'] = in_array($this->v($request, 'board'), Store::BOARDS, true) ? $this->v($request, 'board') : 'editorial';
        }
        $id = $this->store->saveMeeting($cid, $d, $id);
        $this->back($request, 'meeting', ['id' => $id]);
    }

    public function meeting($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $mgr = $this->plugin->isManager($request);
        $mt = $this->store->meeting((int) $request->getUserVar('id'), $cid); if (!$mt) { http_response_code(404); exit; }
        $ro = $mgr ? '' : ' disabled';
        $h = $this->nav($request, 'meetings') . $this->flash($request) . '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->boardLabel($mt->board) . ' — ' . substr($mt->meet_at, 0, 4) . '/' . (int) $mt->number . '</h2>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveMeeting')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $mt->meeting_id . '">'
            . '<input name="title" value="' . self::e($mt->title) . '" placeholder="' . self::e($this->t('f.title')) . '" style="' . self::S_INP . 'width:260px"' . $ro . '> '
            . '<input type="datetime-local" name="meet_at" value="' . self::e(str_replace(' ', 'T', substr($mt->meet_at, 0, 16))) . '" style="' . self::S_INP . '"' . $ro . '> '
            . '<input name="location" value="' . self::e($mt->location) . '" placeholder="' . self::e($this->t('f.location')) . '" style="' . self::S_INP . 'width:200px"' . $ro . '> '
            . '<select name="status" style="' . self::S_INP . '"' . $ro . '>' . implode('', array_map(fn ($s) => '<option value="' . $s . '"' . ($mt->status === $s ? ' selected' : '') . '>' . $this->t('st.' . $s) . '</option>', ['planned', 'held', 'cancelled'])) . '</select>'
            . '<p style="margin:8px 0 2px;font-size:12px">' . $this->t('f.agenda') . '</p><textarea name="agenda" rows="4" style="' . self::S_INP . 'width:100%"' . $ro . '>' . self::e($mt->agenda) . '</textarea>'
            . '<p style="margin:8px 0 2px;font-size:12px">' . $this->t('f.minutes') . '</p><textarea name="minutes" rows="8" style="' . self::S_INP . 'width:100%"' . $ro . '>' . self::e($mt->minutes) . '</textarea>'
            . ($mgr ? '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button>' : '') . '</form></div>';
        // الحضور
        $att = $this->store->attendance((int) $mt->meeting_id); $members = $this->store->members($cid, $mt->board, true);
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('attendance') . '</h2><form method="post" action="' . self::e($this->url($request, 'saveAttendance')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $mt->meeting_id . '"><table style="' . self::S_TBL . '"><tbody>';
        foreach ($members as $m) {
            $h .= '<tr><td style="' . self::S_TD . '">' . self::e($this->memberName($m)) . '</td><td style="' . self::S_TD . '">';
            foreach (['present', 'absent', 'excused'] as $s) $h .= '<label style="margin-inline-end:12px"><input type="radio" name="att[' . (int) $m->member_id . ']" value="' . $s . '"' . (($att[$m->member_id] ?? '') === $s ? ' checked' : '') . $ro . '> ' . $this->t('att.' . $s) . '</label>';
            $h .= '</td></tr>';
        }
        $h .= '</tbody></table>' . ($mgr && $members ? '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button>' : '') . '</form></div>';
        // القرارات
        $h .= $this->resolutionTable($request, $this->store->resolutions($cid, ['meeting' => $mt->meeting_id]), $mgr);
        if ($mgr) {
            $all = $this->store->members($cid, null, true);
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('addRes') . '</h2><form method="post" action="' . self::e($this->url($request, 'addResolution')) . '">' . $this->csrf($request) . '<input type="hidden" name="meeting" value="' . (int) $mt->meeting_id . '">'
                . '<textarea name="text" rows="2" required placeholder="' . self::e($this->t('f.resText')) . '" style="' . self::S_INP . 'width:100%"></textarea> <select name="owner" style="' . self::S_INP . '"><option value="">' . $this->t('f.owner') . '</option>'
                . implode('', array_map(fn ($m) => '<option value="' . (int) $m->member_id . '">' . self::e($this->memberName($m)) . '</option>', $all)) . '</select> <input type="date" name="due" style="' . self::S_INP . '"> <button style="' . self::S_BTNP . '">' . $this->t('add') . '</button></form></div>';
        }
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveAttendance($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request); $id = (int) $request->getUserVar('id');
        $mt = $this->store->meeting($id, $cid); if (!$mt) { http_response_code(404); exit; }
        $valid = array_map(fn ($m) => (int) $m->member_id, $this->store->members($cid, $mt->board));
        $in = (array) $request->getUserVar('att'); $clean = [];
        foreach ($in as $mid => $s) if (in_array((int) $mid, $valid, true)) $clean[(int) $mid] = (string) $s;
        $this->store->saveAttendance($id, $clean);
        $this->back($request, 'meeting', ['id' => $id]);
    }

    // ================================================================ القرارات
    private function resolutionTable($request, array $rows, bool $canEdit): string
    {
        $cid = $this->cid($request); $names = [];
        foreach ($this->store->members($cid) as $m) $names[$m->member_id] = $this->memberName($m);
        $h = '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('tab.resolutions') . '</h2><table style="' . self::S_TBL . '"><thead><tr>';
        foreach (['number', 'text', 'owner', 'due', 'status'] as $c) $h .= '<th style="' . self::S_TH . '">' . $this->t('col.' . $c) . '</th>';
        $h .= '<th style="' . self::S_TH . '"></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $late = $r->status === 'open' && $r->due_date && $r->due_date < date('Y-m-d');
            $act = '';
            if ($canEdit) $act = '<form method="post" action="' . self::e($this->url($request, 'setResolution')) . '" style="margin:0">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $r->resolution_id . '"><input type="hidden" name="status" value="' . ($r->status === 'open' ? 'done' : 'open') . '">'
                . '<input name="note" value="' . self::e($r->done_note) . '" placeholder="' . self::e($this->t('f.note')) . '" style="' . self::S_INP . 'width:120px;min-height:24px"><button style="' . self::S_BTN . '">' . $this->t($r->status === 'open' ? 'markDone' : 'reopen') . '</button></form>';
            $h .= '<tr><td style="' . self::S_TD . '" dir="ltr">' . self::e($r->number) . '</td><td style="' . self::S_TD . '">' . nl2br(self::e($r->text)) . ($r->done_note ? '<div style="font-size:11px;color:#555">' . self::e($r->done_note) . '</div>' : '') . '</td>'
                . '<td style="' . self::S_TD . '">' . self::e($names[$r->owner_member_id] ?? '—') . '</td><td style="' . self::S_TD . ($late ? 'color:#b02a2a;font-weight:700' : '') . '" dir="ltr">' . self::e($r->due_date ?? '') . '</td>'
                . '<td style="' . self::S_TD . '">' . $this->t('rs.' . $r->status) . ($late ? ' ⚠' : '') . '</td><td style="' . self::S_TD . '">' . $act . '</td></tr>';
        }
        if (!$rows) $h .= '<tr><td colspan="6" style="' . self::S_TD . '">' . $this->t('none') . '</td></tr>';
        return $h . '</tbody></table></div>';
    }

    public function resolutions($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $st = $this->v($request, 'status');
        $f = in_array($st, ['open', 'done'], true) ? ['status' => $st] : [];
        $h = $this->nav($request, 'resolutions') . $this->flash($request) . '<div style="margin-bottom:10px">';
        foreach (['' => 'all', 'open' => 'rs.open', 'done' => 'rs.done'] as $k => $l) $h .= '<a style="' . ($k === $st ? self::S_BTNP : self::S_BTN) . '" href="' . self::e($this->url($request, 'resolutions', $k ? ['status' => $k] : [])) . '">' . $this->t($l) . '</a>';
        $this->show($request, $this->t('menu'), $h . '</div>' . $this->resolutionTable($request, $this->store->resolutions($cid, $f), $this->plugin->isManager($request)));
    }

    public function addResolution($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request); $mid = (int) $request->getUserVar('meeting') ?: null;
        if ($mid && !$this->store->meeting($mid, $cid)) { http_response_code(404); exit; }
        $own = (int) $request->getUserVar('owner') ?: null;
        if ($own && !$this->store->member($own, $cid)) $own = null;
        $text = $this->v($request, 'text'); if ($text === '') { $this->back($request, $mid ? 'meeting' : 'resolutions', $mid ? ['id' => $mid] : [], 'needText'); return; }
        $this->store->addResolution($cid, $mid, $text, $own, $this->date($this->v($request, 'due')));
        $this->back($request, $mid ? 'meeting' : 'resolutions', $mid ? ['id' => $mid] : []);
    }

    public function setResolution($args, $request)
    {
        $this->manager($request);
        $s = $this->v($request, 'status') === 'done' ? 'done' : 'open';
        $this->store->setResolution((int) $request->getUserVar('id'), $this->cid($request), $s, mb_substr($this->v($request, 'note'), 0, 500));
        $request->redirectUrl($_SERVER['HTTP_REFERER'] ?? $this->url($request, 'resolutions'));
    }

    // ================================================================ تقرير سنوي
    public function report($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $y = $this->year($request);
        $j = Activity::journal($cid, $y);
        $h = $this->nav($request, 'report') . '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('journalYear', ['y' => $y]) . '</h2>' . $this->yearForm($request, 'report', [], $y) . '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        foreach (['received', 'published', 'accepted', 'declined', 'acceptRate', 'firstDecisionDays', 'reviews'] as $k)
            $h .= '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px;min-width:130px"><div style="font-size:22px;font-weight:700">' . ($j[$k] === null ? '—' : $j[$k] . ($k === 'acceptRate' ? '%' : '')) . '</div><div style="font-size:12px;color:#555">' . $this->t('jr.' . $k) . '</div></div>';
        $h .= '</div></div><div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('membersYear') . '</h2><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">' . $this->t('col.name') . '</th>';
        foreach (Activity::KEYS as $k) $h .= '<th style="' . self::S_TH . '">' . $this->t('act.' . $k) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($this->store->members($cid, null, true) as $m) {
            $a = Activity::forMember($m, $cid, $y);
            $h .= '<tr><td style="' . self::S_TD . '"><b>' . self::e($this->memberName($m)) . '</b><div style="font-size:11px;color:#777">' . $this->boardLabel($m->board) . '</div></td>';
            foreach (Activity::KEYS as $k) $h .= '<td style="' . self::S_TD . '">' . ($k === 'meetings' ? $a['meetings'] . '/' . $a['meetingsTotal'] : $a[$k]) . '</td>';
            $h .= '</tr>';
        }
        $this->show($request, $this->t('menu'), $h . '</tbody></table></div></div>');
    }

    // ================================================================ بوابة العضو
    public function mine($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $u = $request->getUser();
        $ms = $this->store->memberByUser($cid, (int) $u->getId());
        if (!$ms) { if ($this->plugin->isEditor($request)) { $request->redirectUrl($this->url($request, 'index')); return; } $this->deny(); }
        $y = $this->year($request); $j = Activity::journal($cid, $y);
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p>';
        foreach ($ms as $m) {
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 4px;font-size:17px">' . self::e($this->memberName($m)) . '</h2><div style="color:#555;margin-bottom:10px">' . self::e($this->boardLabel($m->board) . ' — ' . ($m->role_ar ?: $m->role_en)) . '</div>'
                . $this->yearForm($request, 'mine', [], $y) . $this->activityCard($request, $m, $y) . '</div>'
                . $this->resolutionTable($request, $this->store->resolutions($cid, ['owner' => [$m->member_id]]), false);
            $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 8px;font-size:15px">' . $this->t('upcoming') . '</h3>';
            $up = array_filter($this->store->meetings($cid), fn ($r) => $r->board === $m->board && $r->status === 'planned' && $r->meet_at >= date('Y-m-d'));
            foreach ($up as $r) $h .= '<div style="margin-bottom:8px"><b dir="ltr">' . self::e(substr($r->meet_at, 0, 16)) . '</b> — ' . self::e($r->title) . ' <span style="color:#666">' . self::e($r->location) . '</span>' . ($r->agenda ? '<div style="font-size:12px;color:#444">' . nl2br(self::e($r->agenda)) . '</div>' : '') . '</div>';
            if (!$up) $h .= '<p>' . $this->t('none') . '</p>';
            $h .= '</div>';
        }
        $h .= '<div style="' . self::S_BOX . '"><h3 style="margin:0 0 8px;font-size:15px">' . $this->t('journalYear', ['y' => $y]) . '</h3>' . self::e($this->t('jr.received')) . ': <b>' . $j['received'] . '</b> · ' . self::e($this->t('jr.published')) . ': <b>' . $j['published'] . '</b> · ' . self::e($this->t('jr.acceptRate')) . ': <b>' . ($j['acceptRate'] === null ? '—' : $j['acceptRate'] . '%') . '</b></div>';
        $this->show($request, $this->t('myMenu'), $h);
    }

    // ================================================================ الصفحة العامة
    public function board($args, $request)
    {
        $cid = $this->cid($request);
        $h = '<div style="max-width:880px">';
        foreach (Store::BOARDS as $b) {
            $rows = $this->store->members($cid, $b, true);
            if (!$rows) continue;
            $h .= '<h2>' . $this->boardLabel($b) . '</h2><ul style="list-style:none;padding:0">';
            foreach ($rows as $m) {
                $h .= '<li style="margin:0 0 12px;padding:10px 12px;border:1px solid #e1d9cc;border-radius:8px"><b>' . self::e($this->memberName($m)) . '</b>'
                    . ($m->role_ar || $m->role_en ? ' — ' . self::e($m->role_ar ?: $m->role_en) : '')
                    . '<div style="font-size:.9rem;color:#555">' . self::e(trim(($m->affiliation_ar ?: $m->affiliation_en) . ($m->country !== '' ? '، ' . $m->country : ''), '، '))
                    . ($m->orcid !== '' ? ' · <a dir="ltr" href="https://orcid.org/' . self::e($m->orcid) . '">ORCID</a>' : '') . '</div></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</div>';
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['bpTitle' => $this->t('publicTitle'), 'bpHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('public.tpl'));
    }
}
