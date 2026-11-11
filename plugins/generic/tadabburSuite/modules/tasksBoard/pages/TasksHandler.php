<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tasksBoard/pages/TasksHandler.php
 * فريق التحرير: index (اللوحة)، task ؛ POST: create، move، comment، edit
 */
namespace APP\plugins\generic\tadabburSuite\m\tasksBoard\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\tasksBoard\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use PKP\core\PKPApplication;

class TasksHandler extends ModuleHandler
{
    private ?Store $s = null;
    private array $names = [];
    private function st(): Store { return $this->s ??= new Store(); }
    private function name(?int $u): string { if (!$u) return '—'; return $this->names[$u] ??= (($x = Repo::user()->get($u, true)) ? $x->getFullName() : '#' . $u); }
    private function uid($request): int { return (int) $request->getUser()->getId(); }

    private function load($request): object { $this->editor($request); $t = $this->st()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$t) $this->notFound(); return $t; }
    private function late(object $t): bool { return $t->status !== 'done' && $t->due_date && $t->due_date < date('Y-m-d'); }

    private function card($request, object $t): string
    {
        $pc = ['high' => '#b02a2a', 'normal' => '#0b5394', 'low' => '#777'][$t->priority] ?? '#777';
        $move = '';
        foreach (Store::STATUSES as $s) if ($s !== $t->status) $move .= $this->postBtn($request, 'move', ['id' => $t->task_id, 'to' => $s, 'r' => (string) $request->getUserVar('who')], '→ ' . $this->t('s.' . $s));
        return '<div style="background:#fff;border:1px solid #d5dbe1;border-inline-start:4px solid ' . $pc . ';border-radius:6px;padding:8px;margin-bottom:8px' . ($this->late($t) ? ';box-shadow:0 0 0 2px #f3b4b4' : '') . '">'
            . '<a href="' . self::e($this->url($request, 'task', ['id' => $t->task_id])) . '" style="font-weight:700;text-decoration:none;color:#111">' . self::e($t->title) . '</a>'
            . '<div style="font-size:12px;color:#555;margin-top:3px">👤 ' . self::e($this->name($t->assignee_id ? (int) $t->assignee_id : null)) . ($t->due_date ? ' · 📅 <span dir="ltr"' . ($this->late($t) ? ' style="color:#b02a2a;font-weight:700"' : '') . '>' . self::e($t->due_date) . '</span>' : '') . ($t->submission_id ? ' · 📄 #' . (int) $t->submission_id : '') . '</div>'
            . '<div style="margin-top:4px">' . $move . '</div></div>';
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $who = (string) $request->getUserVar('who');
        $assignee = $who === 'me' ? $this->uid($request) : (ctype_digit($who) ? (int) $who : null);
        $tasks = $this->st()->list($cid, $assignee);
        $cols = array_fill_keys(Store::STATUSES, '');
        $late = 0;
        foreach ($tasks as $t) { $cols[$t->status] .= $this->card($request, $t); if ($this->late($t)) $late++; }
        $team = Store::team($cid);
        $filter = '<form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:inline">' . '<select name="who" onchange="this.form.submit()" style="' . self::S_INP . '"><option value="">' . $this->t('all') . '</option><option value="me"' . ($who === 'me' ? ' selected' : '') . '>' . $this->t('mine') . '</option>'
            . implode('', array_map(fn ($u) => '<option value="' . $u . '"' . ($who === (string) $u ? ' selected' : '') . '>' . self::e($this->name($u)) . '</option>', $team)) . '</select></form>';
        $board = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">';
        foreach ($cols as $s => $html) $board .= '<div style="background:#f1f3f5;border-radius:8px;padding:8px"><div style="font-weight:700;margin-bottom:8px">' . $this->t('s.' . $s) . ' <span style="color:#777;font-weight:normal">(' . count(array_filter($tasks, fn ($t) => $t->status === $s)) . ')</span></div>' . ($html ?: '<div style="color:#999;font-size:12px">' . $this->t('empty') . '</div>') . '</div>';
        $board .= '</div>';
        $opts = fn ($sel = null) => implode('', array_map(fn ($u) => '<option value="' . $u . '"' . ($sel === $u ? ' selected' : '') . '>' . self::e($this->name($u)) . '</option>', $team));
        $new = '<details style="margin-bottom:12px"' . ($request->getUserVar('sid') ? ' open' : '') . '><summary style="cursor:pointer;font-weight:700">+ ' . $this->t('new') . '</summary><form method="post" action="' . self::e($this->url($request, 'create')) . '" style="margin-top:8px">' . $this->csrf($request)
            . '<input name="title" required placeholder="' . self::e($this->t('f.title')) . '" style="' . self::S_INP . 'width:100%"><textarea name="body" rows="2" placeholder="' . self::e($this->t('f.body')) . '" style="' . self::S_INP . 'width:100%;margin-top:4px"></textarea>'
            . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;font-size:12px"><label>' . $this->t('f.assignee') . ' <select name="assignee" style="' . self::S_INP . '">' . $opts($this->uid($request)) . '</select></label>'
            . '<label>' . $this->t('f.due') . ' <input type="date" name="due" style="' . self::S_INP . '"></label><label>' . $this->t('f.priority') . ' <select name="priority" style="' . self::S_INP . '">' . implode('', array_map(fn ($p) => '<option value="' . $p . '"' . ($p === 'normal' ? ' selected' : '') . '>' . $this->t('p.' . $p) . '</option>', Store::PRIORITIES)) . '</select></label>'
            . '<label>' . $this->t('f.sid') . ' <input type="number" name="sid" value="' . self::e((string) $request->getUserVar('sid')) . '" style="' . self::S_INP . 'width:90px"></label><label><input type="checkbox" name="notify" value="1" checked> ' . $this->t('f.notify') . '</label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('add') . '</button></div></form></details>';
        $h = $this->flash($request) . '<div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">' . $filter . ($late ? '<b style="color:#b02a2a">' . $this->t('lateN', ['n' => $late]) . '</b>' : '') . '</div>' . $new . $board;
        $this->show($request, $this->t('menu'), $h);
    }

    public function create($args, $request)
    {
        $this->editor($request); $this->postOk($request); $cid = $this->cid($request);
        $a = (int) $request->getUserVar('assignee'); if (!in_array($a, Store::team($cid), true)) $a = $this->uid($request);
        $sid = (int) $request->getUserVar('sid'); if ($sid && !($x = Repo::submission()->get($sid)) || ($sid && (int) $x->getData('contextId') !== $cid)) $sid = 0;
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->v($request, 'due')) ? $this->v($request, 'due') : null;
        $title = mb_substr($this->v($request, 'title'), 0, 255); if ($title === '') { $this->back($request, 'index'); return; }
        $id = $this->st()->create(['context_id' => $cid, 'title' => $title, 'body' => (string) $request->getUserVar('body'), 'submission_id' => $sid ?: null, 'assignee_id' => $a, 'created_by' => $this->uid($request),
            'due_date' => $due, 'priority' => in_array($this->v($request, 'priority'), Store::PRIORITIES, true) ? $this->v($request, 'priority') : 'normal']);
        if ($request->getUserVar('notify') && $a !== $this->uid($request) && ($u = Repo::user()->get($a, true)))
            Services::mail($request->getContext(), (string) $u->getEmail(), $u->getFullName(), $this->t('mail.subject', ['t' => $title]),
                '<p>' . self::e($this->t('mail.body', ['n' => $this->name($this->uid($request)), 'd' => $due ?? '—'])) . '</p><p><b>' . self::e($title) . '</b></p><p><a href="' . self::e($this->url($request, 'task', ['id' => $id])) . '">' . self::e($this->t('mail.link')) . '</a></p>');
        $this->back($request, 'index', [], 'created');
    }

    public function move($args, $request)
    {
        $this->postOk($request); $t = $this->load($request); $to = $this->v($request, 'to');
        if (!in_array($to, Store::STATUSES, true)) $this->deny();
        $this->st()->update((int) $t->task_id, ['status' => $to, 'done_at' => $to === 'done' ? date('Y-m-d H:i:s') : null]);
        $this->st()->comment((int) $t->task_id, $this->uid($request), '⟶ ' . $this->t('s.' . $to));
        $r = (string) $request->getUserVar('r');
        $this->back($request, $request->getUserVar('back') === 'task' ? 'task' : 'index', $request->getUserVar('back') === 'task' ? ['id' => $t->task_id] : ($r !== '' ? ['who' => $r] : []), 'moved');
    }

    public function task($args, $request)
    {
        $t = $this->load($request); $cid = $this->cid($request);
        $sub = '';
        if ($t->submission_id && ($s = Repo::submission()->get((int) $t->submission_id)))
            $sub = '<div style="background:#f8f9fb;padding:6px;border-radius:4px;margin:6px 0">📄 <a target="_blank" href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $s->getId()])) . '">#' . (int) $s->getId() . ' ' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</a></div>';
        $move = '';
        foreach (Store::STATUSES as $s) if ($s !== $t->status) $move .= $this->postBtn($request, 'move', ['id' => $t->task_id, 'to' => $s, 'back' => 'task'], '→ ' . $this->t('s.' . $s), $s === 'done');
        $cm = '';
        foreach ($this->st()->comments((int) $t->task_id) as $c) $cm .= '<div style="border-bottom:1px solid #eee;padding:6px 0"><b style="font-size:12px">' . self::e($this->name((int) $c->user_id)) . '</b> <span style="font-size:11px;color:#888" dir="ltr">' . self::e(substr($c->created_at, 0, 16)) . '</span><div style="white-space:pre-line">' . self::e($c->body) . '</div></div>';
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->flash($request)
            . $this->box(self::e($t->title), '<div style="font-size:13px;color:#555">' . $this->t('s.' . $t->status) . ' · ' . $this->t('p.' . $t->priority) . ' · 👤 ' . self::e($this->name($t->assignee_id ? (int) $t->assignee_id : null)) . ($t->due_date ? ' · 📅 ' . self::e($t->due_date) . ($this->late($t) ? ' <b style="color:#b02a2a">' . $this->t('late') . '</b>' : '') : '') . '</div>'
                . '<div style="font-size:12px;color:#888">' . $this->t('createdBy', ['n' => $this->name((int) $t->created_by), 'd' => substr($t->created_at, 0, 10)]) . '</div>' . $sub
                . ($t->body ? '<div style="white-space:pre-line;margin:8px 0">' . self::e($t->body) . '</div>' : '') . $move)
            . $this->box($this->t('comments'), $cm . '<form method="post" action="' . self::e($this->url($request, 'comment')) . '" style="margin-top:8px">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $t->task_id . '"><textarea name="body" rows="2" required style="' . self::S_INP . 'width:100%"></textarea><button style="' . self::S_BTN . '">' . $this->t('addComment') . '</button></form>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function comment($args, $request)
    {
        $this->postOk($request); $t = $this->load($request); $b = trim((string) $request->getUserVar('body'));
        if ($b !== '') {
            $this->st()->comment((int) $t->task_id, $this->uid($request), mb_substr($b, 0, 4000));
            $other = (int) ($this->uid($request) === (int) $t->assignee_id ? $t->created_by : $t->assignee_id);
            if ($other && $other !== $this->uid($request) && ($u = Repo::user()->get($other, true)))
                Services::mail($request->getContext(), (string) $u->getEmail(), $u->getFullName(), $this->t('mail.comment', ['t' => $t->title]), '<p>' . nl2br(self::e($b)) . '</p><p><a href="' . self::e($this->url($request, 'task', ['id' => $t->task_id])) . '">' . self::e($this->t('mail.link')) . '</a></p>');
        }
        $this->back($request, 'task', ['id' => $t->task_id]);
    }
}
