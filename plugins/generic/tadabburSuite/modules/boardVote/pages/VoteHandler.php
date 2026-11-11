<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardVote/pages/VoteHandler.php
 * index، view، minutes ؛ المحرر: new، POST create ؛ المصوّت: POST cast ؛ المنشئ/المدير: POST close
 */
namespace APP\plugins\generic\tadabburSuite\m\boardVote\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\boardVote\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use PKP\core\PKPApplication;

class VoteHandler extends ModuleHandler
{
    private const PRESETS = ['decision' => ['opt.accept', 'opt.minor', 'opt.major', 'opt.reject'], 'yesno' => ['opt.yes', 'opt.no', 'opt.abstain']];

    private ?Store $s = null;
    private function st(): Store { return $this->s ??= new Store(); }
    private function uid($request): int { return (int) $request->getUser()->getId(); }
    private function name(int $uid): string { $u = Repo::user()->get($uid, true); return $u ? $u->getFullName() : '#' . $uid; }

    private function load($request): object
    {
        $this->login($request);
        $v = $this->st()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$v) $this->notFound();
        if (!$this->plugin->isEditor($request) && !in_array($this->uid($request), $v->voters, true)) $this->deny();
        return $v;
    }

    private function canManage($request, object $v): bool { return $this->plugin->isManager($request) || (int) $v->created_by === $this->uid($request); }
    private function expired(object $v): bool { return $v->deadline && $v->deadline < date('Y-m-d'); }

    public function index($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $uid = $this->uid($request); $ed = $this->plugin->isEditor($request);
        $list = $ed ? $this->st()->all($cid) : $this->st()->forVoter($cid, $uid); $rows = [];
        foreach ($list as $v) {
            $b = $this->st()->ballots((int) $v->vote_id); $mine = isset($b[$uid]);
            $state = $v->status === 'closed' ? $this->badge(true, $this->t('closed')) : ($this->expired($v) ? $this->badge(false, $this->t('expired')) : '<span style="color:#0b5394;font-weight:700;font-size:12px">' . $this->t('open_') . '</span>');
            $rows[] = ['<b>' . self::e($v->title) . '</b>' . ($v->submission_id ? '<div style="font-size:11px;color:#777">#' . (int) $v->submission_id . '</div>' : ''), '<span dir="ltr">' . self::e($v->deadline ?? '—') . '</span>', $state,
                count($b) . '/' . count($v->voters), in_array($uid, $v->voters, true) ? ($mine ? '✔ ' . $this->t('voted') : '<b style="color:#b06000">' . $this->t('notVoted') . '</b>') : '—',
                $this->btn($this->url($request, 'view', ['id' => $v->vote_id]), $this->t('openBtn'), $v->status === 'open' && !$mine && in_array($uid, $v->voters, true))];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request) . ($ed ? $this->btn($this->url($request, 'new'), '+ ' . $this->t('new'), true) : '')
            . $this->box('', $this->table([$this->t('col.title'), $this->t('col.deadline'), $this->t('col.status'), $this->t('col.turnout'), $this->t('col.mine'), ''], $rows));
        $this->show($request, $this->t($ed ? 'menu' : 'myMenu'), $h);
    }

    public function new($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $sid = (int) $request->getUserVar('sid');
        $cands = '';
        foreach (Store::candidates($cid) as $u) $cands .= '<label style="display:inline-block;min-width:240px;margin:2px 0"><input type="checkbox" name="voters[]" value="' . $u . '" checked> ' . self::e($this->name($u)) . '</label>';
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->box($this->t('new'), '<form method="post" action="' . self::e($this->url($request, 'create')) . '">' . $this->csrf($request)
            . '<label style="display:block;font-size:12px">' . $this->t('f.title') . '<br><input name="title" required style="' . self::S_INP . 'width:100%"></label>'
            . '<label style="display:block;font-size:12px;margin-top:6px">' . $this->t('f.sid') . ' <input type="number" name="sid" value="' . ($sid ?: '') . '" style="' . self::S_INP . 'width:100px"></label>'
            . '<label style="display:block;font-size:12px;margin-top:6px">' . $this->t('f.body') . '<br><textarea name="body" rows="4" style="' . self::S_INP . 'width:100%"></textarea></label>'
            . '<div style="margin-top:8px;font-size:12px">' . $this->t('f.options') . ': <label><input type="radio" name="preset" value="decision" checked> ' . $this->t('preset.decision') . '</label> <label><input type="radio" name="preset" value="yesno"> ' . $this->t('preset.yesno') . '</label> <label><input type="radio" name="preset" value="custom"> ' . $this->t('preset.custom') . '</label>'
            . '<textarea name="custom" rows="3" placeholder="' . self::e($this->t('customHint')) . '" style="' . self::S_INP . 'width:100%;margin-top:4px"></textarea></div>'
            . '<div style="margin-top:8px;font-size:12px"><b>' . $this->t('f.voters') . '</b><div>' . ($cands ?: $this->t('noCands')) . '</div></div>'
            . '<div style="margin-top:8px;font-size:12px">' . $this->t('f.deadline') . ' <input type="date" name="deadline" value="' . date('Y-m-d', strtotime('+7 days')) . '" style="' . self::S_INP . '"> '
            . $this->t('f.quorum') . ' <input type="number" name="quorum" value="50" min="0" max="100" style="' . self::S_INP . 'width:70px">% '
            . '<label><input type="checkbox" name="secret" value="1"> ' . $this->t('f.secret') . '</label> <label><input type="checkbox" name="notify" value="1" checked> ' . $this->t('f.notify') . '</label></div>'
            . '<button style="' . self::S_BTNP . 'margin-top:10px">' . $this->t('create') . '</button></form>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function create($args, $request)
    {
        $this->editor($request); $this->postOk($request); $cid = $this->cid($request);
        $preset = (string) $request->getUserVar('preset');
        $opts = isset(self::PRESETS[$preset]) ? array_map(fn ($k) => $this->t($k), self::PRESETS[$preset]) : array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $request->getUserVar('custom')))));
        $allowed = Store::candidates($cid);
        $voters = array_values(array_intersect(array_map('intval', (array) $request->getUserVar('voters')), $allowed));
        if ($this->v($request, 'title') === '' || count($opts) < 2 || !$voters) { $this->back($request, 'new', [], 'errForm'); return; }
        $sid = (int) $request->getUserVar('sid');
        if ($sid && !Repo::submission()->get($sid)) $sid = 0;
        $dl = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->v($request, 'deadline')) ? $this->v($request, 'deadline') : null;
        $id = $this->st()->create($cid, ['submission_id' => $sid ?: null, 'title' => mb_substr($this->v($request, 'title'), 0, 255), 'body' => (string) $request->getUserVar('body'),
            'options' => json_encode(array_slice($opts, 0, 12), JSON_UNESCAPED_UNICODE), 'voters' => json_encode($voters), 'deadline' => $dl, 'secret' => $request->getUserVar('secret') ? 1 : 0,
            'quorum' => max(0, min(100, (int) $request->getUserVar('quorum'))), 'created_by' => $this->uid($request)]);
        if ($request->getUserVar('notify')) {
            $ctx = $request->getContext(); $link = $this->url($request, 'view', ['id' => $id]);
            foreach ($voters as $u) if ($usr = Repo::user()->get($u, true))
                Services::mail($ctx, (string) $usr->getEmail(), $usr->getFullName(), $this->t('mail.subject', ['t' => $this->v($request, 'title')]),
                    '<p>' . self::e($this->t('mail.body', ['d' => $dl ?? '—'])) . '</p><p><b>' . self::e($this->v($request, 'title')) . '</b></p><p><a href="' . self::e($link) . '">' . self::e($this->t('mail.link')) . '</a></p>');
        }
        $this->back($request, 'view', ['id' => $id], 'created');
    }

    private function results(object $v, array $b, bool $names): string
    {
        [$n, $cast, $quorum, $win] = Store::tally($v, $b);
        $h = '';
        foreach ($v->options as $i => $o) {
            $pct = $cast ? round(100 * $n[$i] / $cast) : 0;
            $h .= '<div style="margin:6px 0"><div style="display:flex;justify-content:space-between;font-size:13px"><b>' . self::e($o) . ($win === $i ? ' ✔' : '') . '</b><span>' . $n[$i] . ' (' . $pct . '%)</span></div>'
                . '<div style="background:#eef0f3;border-radius:4px;height:10px"><div style="width:' . $pct . '%;height:10px;border-radius:4px;background:' . ($win === $i ? '#1e7b4a' : '#7a99b8') . '"></div></div></div>';
        }
        $h .= '<p style="font-size:12px">' . $this->t('turnout', ['c' => $cast, 'n' => count($v->voters)]) . ' · ' . ($quorum ? '✔ ' . $this->t('quorumOk', ['q' => $v->quorum]) : '✖ ' . $this->t('quorumNo', ['q' => $v->quorum])) . '</p>';
        $list = '';
        foreach ($b as $uid => $x) $list .= '<li style="margin:4px 0">' . ($names ? '<b>' . self::e($this->name((int) $uid)) . '</b>: ' : '') . self::e($v->options[(int) $x->choice] ?? '?') . ($x->comment ? ' — <span style="color:#444">' . self::e($x->comment) . '</span>' : '') . '</li>';
        if ($list) $h .= '<details' . ($names ? ' open' : '') . '><summary style="cursor:pointer;font-size:13px">' . $this->t($names ? 'ballots' : 'reasons') . '</summary><ul>' . $list . '</ul></details>';
        if ($names) {
            $missing = array_filter($v->voters, fn ($u) => !isset($b[$u]));
            if ($missing) $h .= '<p style="font-size:12px;color:#8a5a00">' . $this->t('missing') . ': ' . self::e(implode('، ', array_map(fn ($u) => $this->name($u), $missing))) . '</p>';
        }
        return $h;
    }

    public function view($args, $request)
    {
        $v = $this->load($request); $uid = $this->uid($request); $b = $this->st()->ballots((int) $v->vote_id);
        $isVoter = in_array($uid, $v->voters, true); $mine = $b[$uid] ?? null; $open = $v->status === 'open' && !$this->expired($v);
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->flash($request);
        $meta = '<div style="font-size:12px;color:#666;margin-bottom:6px">' . $this->t('by', ['n' => $this->name((int) $v->created_by), 'd' => substr($v->created_at, 0, 10)]) . ($v->deadline ? ' · ' . $this->t('until', ['d' => $v->deadline]) : '') . ($v->secret ? ' · 🔒 ' . $this->t('f.secret') : '') . '</div>';
        if ($v->submission_id && ($s = Repo::submission()->get((int) $v->submission_id))) {
            $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $s->getId()]);
            $meta .= '<div style="background:#f8f9fb;padding:8px;border-radius:6px;margin-bottom:8px">📄 #' . (int) $s->getId() . ' <b>' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</b>' . ($this->plugin->isEditor($request) ? ' · <a href="' . self::e($wf) . '" target="_blank">' . $this->t('workflow') . '</a>' : '') . '</div>';
        }
        $h .= $this->box(self::e($v->title), $meta . ($v->body ? '<div style="white-space:pre-line">' . self::e($v->body) . '</div>' : ''));
        if ($isVoter && $open) {
            $f = '<form method="post" action="' . self::e($this->url($request, 'cast')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $v->vote_id . '">';
            foreach ($v->options as $i => $o) $f .= '<label style="display:block;margin:4px 0;font-size:14px"><input type="radio" name="choice" value="' . $i . '" required' . ($mine && (int) $mine->choice === $i ? ' checked' : '') . '> ' . self::e($o) . '</label>';
            $f .= '<textarea name="comment" rows="3" placeholder="' . self::e($this->t('reasonHint')) . '" style="' . self::S_INP . 'width:100%">' . self::e($mine->comment ?? '') . '</textarea><button style="' . self::S_BTNP . '">' . $this->t($mine ? 'change' : 'cast') . '</button></form>';
            $h .= $this->box($this->t('yourVote'), $f);
        }
        $canSee = $v->status === 'closed' || $this->canManage($request, $v) || ($mine && !$v->secret);
        if ($canSee) $h .= $this->box($this->t('results') . ($v->status === 'closed' ? ' — ' . self::e($v->result) : ''), $this->results($v, $b, !$v->secret && ($this->plugin->isEditor($request) || $v->status === 'closed')));
        else $h .= $this->box($this->t('results'), '<p>' . $this->t('hidden') . '</p>');
        if ($v->status === 'open' && $this->canManage($request, $v)) {
            [$n, , , $win] = Store::tally($v, $b);
            $h .= $this->box($this->t('closeTitle'), '<form method="post" action="' . self::e($this->url($request, 'close')) . '" onsubmit="return confirm(' . self::e(json_encode($this->t('confirmClose'), JSON_UNESCAPED_UNICODE)) . ')">' . $this->csrf($request)
                . '<input type="hidden" name="id" value="' . (int) $v->vote_id . '"><input name="result" value="' . self::e($win !== null ? $v->options[$win] : '') . '" placeholder="' . self::e($this->t('resultHint')) . '" style="' . self::S_INP . 'width:60%"> <button style="' . self::S_BTNP . '">' . $this->t('close') . '</button></form>');
        }
        if ($v->status === 'closed') $h .= $this->btn($this->url($request, 'minutes', ['id' => $v->vote_id]), '🖨 ' . $this->t('minutes'), false, ' target="_blank"');
        $this->show($request, $this->t($this->plugin->isEditor($request) ? 'menu' : 'myMenu'), $h);
    }

    public function cast($args, $request)
    {
        $this->postOk($request); $v = $this->load($request); $uid = $this->uid($request);
        if (!in_array($uid, $v->voters, true) || $v->status !== 'open' || $this->expired($v)) $this->deny();
        $c = (int) $request->getUserVar('choice'); if (!isset($v->options[$c])) $this->deny();
        $this->st()->cast((int) $v->vote_id, $uid, $c, mb_substr(trim((string) $request->getUserVar('comment')), 0, 2000));
        $this->back($request, 'view', ['id' => $v->vote_id], 'cast');
    }

    public function close($args, $request)
    {
        $this->postOk($request); $v = $this->load($request);
        if (!$this->canManage($request, $v) || $v->status !== 'open') $this->deny();
        $this->st()->close((int) $v->vote_id, $this->v($request, 'result'));
        $this->back($request, 'view', ['id' => $v->vote_id], 'closedOk');
    }

    public function minutes($args, $request)
    {
        $v = $this->load($request); if ($v->status !== 'closed') $this->deny();
        $b = $this->st()->ballots((int) $v->vote_id); $j = Meta::journal($request->getContext());
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>' . self::e($this->t('minutes')) . '</title><style>body{font-family:"IBM Plex Sans Arabic",Tahoma,sans-serif;max-width:820px;margin:24px auto;line-height:1.8}@media print{.np{display:none}}</style></head><body>'
            . '<div class="np"><button onclick="window.print()">🖨</button></div><div style="border-bottom:2px solid #333;padding-bottom:6px"><b>' . self::e(Meta::pick($j['names'])) . '</b> — ' . self::e($this->t('minutes')) . '</div>'
            . '<h2>' . self::e($v->title) . '</h2><p>' . self::e($this->t('by', ['n' => $this->name((int) $v->created_by), 'd' => substr($v->created_at, 0, 10)])) . ' · ' . self::e($this->t('closedOn', ['d' => substr((string) $v->closed_at, 0, 10)])) . '</p>'
            . ($v->body ? '<p style="white-space:pre-line">' . self::e($v->body) . '</p>' : '') . $this->results($v, $b, !$v->secret)
            . '<h3>' . self::e($this->t('finalResult')) . ': ' . self::e($v->result) . '</h3><p style="margin-top:40px">' . self::e($this->t('signLine')) . ' ____________________</p></body></html>';
        exit;
    }
}
