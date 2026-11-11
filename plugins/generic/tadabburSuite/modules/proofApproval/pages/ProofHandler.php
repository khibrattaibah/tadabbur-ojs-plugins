<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/proofApproval/pages/ProofHandler.php
 * المحرر: index، sub، POST send ؛ المؤلف: mine، review، POST respond ؛ الطرفان: file، report (طباعة)
 */
namespace APP\plugins\generic\tadabburSuite\m\proofApproval\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\proofApproval\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Support\Facades\DB;

class ProofHandler extends ModuleHandler
{
    private ?Store $s = null;
    private function st(): Store { return $this->s ??= new Store(); }
    private function title(int $sid): string { $s = Repo::submission()->get($sid); return $s ? strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()) : '#' . $sid; }
    private function uname(?int $u): string { if (!$u) return ''; $x = Repo::user()->get($u, true); return $x ? $x->getFullName() : ''; }

    private function stBadge(string $s): string
    {
        $c = ['pending' => ['#fff4dc', '#8a5a00'], 'approved' => ['#e3f4ea', '#1e7b4a'], 'changes' => ['#fbe9e9', '#b02a2a'], 'superseded' => ['#f1f3f5', '#777']][$s] ?? ['#eee', '#555'];
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;background:' . $c[0] . ';color:' . $c[1] . '">' . $this->t('st.' . $s) . '</span>';
    }

    /** ملفات البروفة المرشحة: البروفات، والجاهز للإنتاج، وملفات النسخ المنشورة */
    private function files(int $sid): array
    {
        return DB::table('submission_files as sf')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('sf.submission_id', $sid)->whereIn('sf.file_stage', [10, 11])
            ->orderByDesc('sf.updated_at')->select('sf.submission_file_id', 'sf.file_stage', 'f.path', 'f.mimetype', 'sf.updated_at')->get()->all();
    }

    private function canSee($request, object $r): bool
    {
        return $this->plugin->isEditor($request) || in_array((int) $request->getUser()->getId(), Store::authorIds((int) $r->submission_id), true);
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request);
        $ids = DB::table('submissions')->where('context_id', $cid)->where(fn ($q) => $q->where('stage_id', 5)->orWhere('status', 5))->whereIn('status', [1, 5])->orderByDesc('submission_id')->pluck('submission_id')->all();
        $ids = array_unique(array_merge($ids, DB::table(Store::T)->where('context_id', $cid)->where('status', '!=', 'superseded')->orderByDesc('proof_id')->limit(40)->pluck('submission_id')->all()));
        $rows = [];
        foreach ($ids as $id) {
            $l = $this->st()->latest((int) $id);
            $rows[] = [(string) $id, '<b>' . self::e(mb_strimwidth($this->title((int) $id), 0, 90, '…')) . '</b>', $l ? $this->stBadge($l->status) . ' <span style="font-size:11px">' . $this->t('roundN', ['n' => $l->round]) . '</span>' : '—',
                $l ? '<span dir="ltr" style="font-size:12px">' . self::e($l->deadline ?? '') . '</span>' . ($l->status === 'pending' && $l->deadline && $l->deadline < date('Y-m-d') ? ' <b style="color:#b02a2a">' . $this->t('overdue') . '</b>' : '') : '',
                $this->btn($this->url($request, 'sub', ['sid' => $id]), $this->t('openBtn'), true)];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request) . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.status'), $this->t('col.deadline'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    private function history($request, array $list): string
    {
        $h = '';
        foreach ($list as $r) {
            $corr = '';
            if ($r->corrections) {
                $rows = array_map(fn ($c, $i) => [(string) ($i + 1), self::e($c['page'] ?? ''), self::e($c['line'] ?? ''), '<span style="background:#fbe9e9">' . self::e($c['wrong'] ?? '') . '</span>', '<span style="background:#e3f4ea">' . self::e($c['right'] ?? '') . '</span>', self::e($c['note'] ?? '')], $r->corrections, array_keys($r->corrections));
                $corr = $this->table(['#', $this->t('c.page'), $this->t('c.line'), $this->t('c.wrong'), $this->t('c.right'), $this->t('c.note')], $rows);
            }
            $h .= '<div style="border:1px solid #e1e4e8;border-radius:8px;padding:10px;margin-bottom:10px"><b>' . $this->t('roundN', ['n' => $r->round]) . '</b> ' . $this->stBadge($r->status)
                . '<div style="font-size:12px;color:#666">' . $this->t('sentOn', ['d' => substr($r->requested_at, 0, 16), 'n' => $this->uname((int) $r->requested_by)]) . ($r->deadline ? ' · ' . $this->t('until', ['d' => $r->deadline]) : '') . '</div>'
                . ($r->message ? '<div style="white-space:pre-line;margin:6px 0">' . self::e($r->message) . '</div>' : '')
                . $this->btn($this->url($request, 'file', ['id' => $r->proof_id]), '⬇ ' . $this->t('download'))
                . ($r->responded_at ? '<div style="margin-top:6px;font-size:13px">' . $this->t('respondedOn', ['d' => substr($r->responded_at, 0, 16), 'n' => $this->uname((int) $r->responded_by)]) . '</div>' : '')
                . ($r->note ? '<div style="white-space:pre-line;background:#f8f9fb;padding:6px;border-radius:4px;margin-top:4px">' . self::e($r->note) . '</div>' : '') . $corr
                . ($r->responded_at ? $this->btn($this->url($request, 'report', ['id' => $r->proof_id]), '🖨 ' . $this->t('report'), false, ' target="_blank"') : '') . '</div>';
        }
        return $h ?: '<p>' . $this->t('none') . '</p>';
    }

    public function sub($args, $request)
    {
        $this->editor($request); $sid = (int) $request->getUserVar('sid');
        if (!DB::table('submissions')->where('submission_id', $sid)->where('context_id', $this->cid($request))->exists()) $this->notFound();
        $files = $this->files($sid);
        $form = $files ? '<form method="post" action="' . self::e($this->url($request, 'send')) . '">' . $this->csrf($request) . '<input type="hidden" name="sid" value="' . $sid . '">'
            . '<label style="font-size:12px">' . $this->t('f.file') . '<br><select name="fileId" style="' . self::S_INP . '">' . implode('', array_map(fn ($f) => '<option value="' . (int) $f->submission_file_id . '">' . self::e($this->t('stage.' . $f->file_stage) . ' · ' . basename($f->path) . ' · ' . substr((string) $f->updated_at, 0, 10)) . '</option>', $files)) . '</select></label> '
            . '<label style="font-size:12px">' . $this->t('f.deadline') . '<br><input type="date" name="deadline" value="' . date('Y-m-d', strtotime('+3 days')) . '" style="' . self::S_INP . '"></label>'
            . '<label style="display:block;font-size:12px;margin-top:6px">' . $this->t('f.message') . '<br><textarea name="message" rows="4" style="' . self::S_INP . 'width:100%">' . self::e($this->t('defaultMsg')) . '</textarea></label>'
            . '<label style="font-size:12px"><input type="checkbox" name="notify" value="1" checked> ' . $this->t('f.notify') . '</label> <button style="' . self::S_BTNP . '">' . $this->t('send') . '</button></form>'
            : '<p style="color:#8a5a00">' . $this->t('noFiles') . '</p>';
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->flash($request) . $this->box(self::e($this->title($sid)) . ' · #' . $sid, $form) . $this->box($this->t('history'), $this->history($request, $this->st()->forSub($sid)));
        $this->show($request, $this->t('menu'), $h);
    }

    public function send($args, $request)
    {
        $this->editor($request); $this->postOk($request); $cid = $this->cid($request); $sid = (int) $request->getUserVar('sid');
        if (!DB::table('submissions')->where('submission_id', $sid)->where('context_id', $cid)->exists()) $this->notFound();
        $fid = (int) $request->getUserVar('fileId');
        if (!in_array($fid, array_map(fn ($f) => (int) $f->submission_file_id, $this->files($sid)), true)) $this->notFound();
        $dl = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->v($request, 'deadline')) ? $this->v($request, 'deadline') : null;
        $id = $this->st()->create(['context_id' => $cid, 'submission_id' => $sid, 'submission_file_id' => $fid, 'message' => trim((string) $request->getUserVar('message')), 'deadline' => $dl, 'requested_by' => (int) $request->getUser()->getId()]);
        if ($request->getUserVar('notify')) foreach (Store::authorIds($sid) as $u) if ($usr = Repo::user()->get($u, true))
            Services::mail($request->getContext(), (string) $usr->getEmail(), $usr->getFullName(), $this->t('mail.subject', ['t' => $this->title($sid)]),
                '<p>' . nl2br(self::e(trim((string) $request->getUserVar('message')))) . '</p><p>' . self::e($this->t('mail.deadline', ['d' => $dl ?? '—'])) . '</p><p><a href="' . self::e($this->url($request, 'review', ['id' => $id])) . '">' . self::e($this->t('mail.link')) . '</a></p>');
        $this->back($request, 'sub', ['sid' => $sid], 'sent');
    }

    public function file($args, $request)
    {
        $this->login($request); $r = $this->st()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r) $this->notFound();
        if (!$this->canSee($request, $r)) $this->deny();
        $f = DB::table('submission_files as sf')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('sf.submission_file_id', $r->submission_file_id)->first(['f.path', 'f.mimetype']);
        if (!$f) $this->notFound();
        $fs = app()->get('file')->fs; if (!$fs->has($f->path)) $this->notFound();
        $ext = pathinfo($f->path, PATHINFO_EXTENSION);
        $this->download('proof-' . $r->submission_id . '-r' . $r->round . '.' . $ext, (string) $f->mimetype, $fs->read($f->path));
    }

    public function mine($args, $request)
    {
        $this->login($request); $rows = [];
        foreach ($this->st()->forAuthor($this->cid($request), (int) $request->getUser()->getId()) as $r) {
            if ($r->status === 'superseded') continue;
            $rows[] = [(string) (int) $r->submission_id, '<b>' . self::e($this->title((int) $r->submission_id)) . '</b>', $this->stBadge($r->status) . ' ' . $this->t('roundN', ['n' => $r->round]), '<span dir="ltr">' . self::e($r->deadline ?? '') . '</span>',
                $this->btn($this->url($request, 'review', ['id' => $r->proof_id]), $this->t($r->status === 'pending' ? 'reviewNow' : 'openBtn'), $r->status === 'pending')];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p>' . $this->flash($request) . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.status'), $this->t('col.deadline'), ''], $rows));
        $this->show($request, $this->t('myMenu'), $h);
    }

    public function review($args, $request)
    {
        $this->login($request); $r = $this->st()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r) $this->notFound();
        if (!$this->canSee($request, $r)) $this->deny();
        $isAuthor = in_array((int) $request->getUser()->getId(), Store::authorIds((int) $r->submission_id), true);
        $h = $this->btn($this->url($request, $isAuthor ? 'mine' : 'sub', $isAuthor ? [] : ['sid' => $r->submission_id]), '← ' . $this->t('back')) . $this->flash($request);
        if ($r->status === 'pending' && $isAuthor) {
            $rowsHtml = '';
            for ($i = 0; $i < 8; $i++) $rowsHtml .= '<tr' . ($i > 2 ? ' class="more" style="display:none"' : '') . '><td style="' . self::S_TD . '"><input name="c[' . $i . '][page]" style="' . self::S_INP . 'width:60px"></td><td style="' . self::S_TD . '"><input name="c[' . $i . '][line]" style="' . self::S_INP . 'width:60px"></td>'
                . '<td style="' . self::S_TD . '"><textarea name="c[' . $i . '][wrong]" rows="2" style="' . self::S_INP . 'width:100%"></textarea></td><td style="' . self::S_TD . '"><textarea name="c[' . $i . '][right]" rows="2" style="' . self::S_INP . 'width:100%"></textarea></td><td style="' . self::S_TD . '"><input name="c[' . $i . '][note]" style="' . self::S_INP . 'width:100%"></td></tr>';
            $h .= $this->box(self::e($this->title((int) $r->submission_id)) . ' — ' . $this->t('roundN', ['n' => $r->round]),
                ($r->message ? '<div style="white-space:pre-line;margin-bottom:8px">' . self::e($r->message) . '</div>' : '') . '<p>' . $this->btn($this->url($request, 'file', ['id' => $r->proof_id]), '⬇ ' . $this->t('download'), true) . ($r->deadline ? ' <span style="font-size:13px">' . $this->t('until', ['d' => $r->deadline]) . '</span>' : '') . '</p>')
                . $this->box($this->t('approveTitle'), '<form method="post" action="' . self::e($this->url($request, 'respond')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $r->proof_id . '"><input type="hidden" name="do" value="approve">'
                    . '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="declare" value="1" required> ' . $this->t('declaration') . '</label><button style="' . self::S_BTNP . 'background:#1e7b4a;border-color:#1e7b4a">✔ ' . $this->t('approve') . '</button></form>')
                . $this->box($this->t('changesTitle'), '<form method="post" action="' . self::e($this->url($request, 'respond')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $r->proof_id . '"><input type="hidden" name="do" value="changes">'
                    . '<p style="font-size:12px;color:#555;margin-top:0">' . $this->t('changesHelp') . '</p><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">' . $this->t('c.page') . '</th><th style="' . self::S_TH . '">' . $this->t('c.line') . '</th><th style="' . self::S_TH . '">' . $this->t('c.wrong') . '</th><th style="' . self::S_TH . '">' . $this->t('c.right') . '</th><th style="' . self::S_TH . '">' . $this->t('c.note') . '</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>'
                    . '<button type="button" style="' . self::S_BTN . '" onclick="document.querySelectorAll(\'tr.more\').forEach(function(t){t.style.display=\'\'});this.remove()">+ ' . $this->t('moreRows') . '</button>'
                    . '<label style="display:block;font-size:12px;margin-top:6px">' . $this->t('f.note') . '<br><textarea name="note" rows="3" style="' . self::S_INP . 'width:100%"></textarea></label><button style="' . self::S_BTNP . '">' . $this->t('sendChanges') . '</button></form>');
        } else $h .= $this->box(self::e($this->title((int) $r->submission_id)), $this->history($request, [$r]));
        $this->show($request, $this->t($isAuthor ? 'myMenu' : 'menu'), $h);
    }

    public function respond($args, $request)
    {
        $this->login($request); $this->postOk($request); $r = $this->st()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r) $this->notFound();
        $uid = (int) $request->getUser()->getId();
        if (!in_array($uid, Store::authorIds((int) $r->submission_id), true) || $r->status !== 'pending') $this->deny();
        $approve = $request->getUserVar('do') === 'approve';
        if ($approve && !$request->getUserVar('declare')) $this->deny();
        $corr = [];
        foreach ((array) $request->getUserVar('c') as $c) {
            if (!is_array($c)) continue;
            $c = array_map(fn ($x) => mb_substr(trim((string) $x), 0, 1000), array_intersect_key($c, array_flip(['page', 'line', 'wrong', 'right', 'note'])));
            if (($c['wrong'] ?? '') !== '' || ($c['right'] ?? '') !== '') $corr[] = $c;
        }
        $note = mb_substr(trim((string) $request->getUserVar('note')), 0, 4000);
        if (!$approve && !$corr && $note === '') { $this->back($request, 'review', ['id' => $r->proof_id], 'errEmpty'); return; }
        $this->st()->respond((int) $r->proof_id, $uid, $approve ? 'approved' : 'changes', $approve ? [] : $corr, $note, (string) $request->getRemoteAddr());
        if ($ed = Repo::user()->get((int) $r->requested_by, true))
            Services::mail($request->getContext(), (string) $ed->getEmail(), $ed->getFullName(), $this->t($approve ? 'mail.approved' : 'mail.changes', ['t' => $this->title((int) $r->submission_id)]),
                '<p><a href="' . self::e($this->url($request, 'sub', ['sid' => $r->submission_id])) . '">' . self::e($this->t('mail.open')) . '</a></p>');
        $this->back($request, 'mine', [], $approve ? 'approved' : 'changesSent');
    }

    public function report($args, $request)
    {
        $this->login($request); $r = $this->st()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r || !$this->canSee($request, $r)) $this->deny();
        $j = Meta::journal($request->getContext());
        $rows = '';
        foreach ($r->corrections as $i => $c) $rows .= '<tr><td>' . ($i + 1) . '</td><td>' . self::e($c['page'] ?? '') . '</td><td>' . self::e($c['line'] ?? '') . '</td><td>' . self::e($c['wrong'] ?? '') . '</td><td>' . self::e($c['right'] ?? '') . '</td><td>' . self::e($c['note'] ?? '') . '</td><td style="width:60px"></td></tr>';
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>' . self::e($this->t('report')) . '</title><style>body{font-family:"IBM Plex Sans Arabic",Tahoma,sans-serif;max-width:900px;margin:24px auto;line-height:1.7}table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:5px;vertical-align:top}th{background:#eee}@media print{.np{display:none}}</style></head><body>'
            . '<div class="np"><button onclick="window.print()">🖨</button></div><div style="border-bottom:2px solid #333;padding-bottom:6px"><b>' . self::e(Meta::pick($j['names'])) . '</b> — ' . self::e($this->t('report')) . '</div>'
            . '<h2 style="font-size:17px">' . self::e($this->title((int) $r->submission_id)) . ' · #' . (int) $r->submission_id . ' · ' . self::e($this->t('roundN', ['n' => $r->round])) . '</h2>'
            . '<p>' . self::e($this->t('st.' . $r->status)) . ' — ' . self::e($this->t('respondedOn', ['d' => substr((string) $r->responded_at, 0, 16), 'n' => $this->uname((int) $r->responded_by)])) . '</p>'
            . ($r->status === 'approved' ? '<p style="border:1px solid #1e7b4a;padding:8px">✔ ' . self::e($this->t('declaration')) . '</p>' : '')
            . ($rows ? '<table><thead><tr><th>#</th><th>' . self::e($this->t('c.page')) . '</th><th>' . self::e($this->t('c.line')) . '</th><th>' . self::e($this->t('c.wrong')) . '</th><th>' . self::e($this->t('c.right')) . '</th><th>' . self::e($this->t('c.note')) . '</th><th>' . self::e($this->t('c.done')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '')
            . ($r->note ? '<p style="white-space:pre-line">' . self::e($r->note) . '</p>' : '') . '</body></html>';
        exit;
    }
}
