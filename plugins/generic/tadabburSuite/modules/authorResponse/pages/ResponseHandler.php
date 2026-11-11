<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/authorResponse/pages/ResponseHandler.php
 * المحرر: index، view، print، reopen ؛ المؤلف: mine، edit، save ؛ محكّم الجولة التالية: view، print
 */
namespace APP\plugins\generic\tadabburSuite\m\authorResponse\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\authorResponse\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;

class ResponseHandler extends ModuleHandler
{
    private const LETTERS = ['أ', 'ب', 'ج', 'د', 'هـ', 'و', 'ز', 'ح'];
    private ?Store $store = null;
    private function store(): Store { return $this->store ??= new Store(); }

    private function rlabel(int $i, int $reviewId = 0): string
    {
        if ($reviewId === 0) return $this->t('editorPoints');
        $ar = str_starts_with((string) \PKP\facades\Locale::getLocale(), 'ar');
        return $this->t('reviewer', ['l' => $ar ? (self::LETTERS[$i] ?? (string) ($i + 1)) : chr(65 + $i)]);
    }

    private function title(int $sid): string
    {
        $s = Repo::submission()->get($sid);
        return $s ? strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()) : '#' . $sid;
    }

    /** خريطة review_id => ترتيب المحكّم في الجولة */
    private function order(int $roundId): array
    {
        $m = [];
        foreach ($this->store()->reviewers($roundId) as $r) $m[$r['review_id']] = $r['label'];
        return $m;
    }

    private function canView($request, object $r): bool
    {
        $uid = (int) $request->getUser()->getId();
        return $this->plugin->isEditor($request) || $this->store()->isAuthor((int) $r->submission_id, $uid) || $this->store()->isLaterReviewer((int) $r->submission_id, (int) $r->round, $uid);
    }

    private function statusBadge(string $s): string
    {
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;' . ($s === 'submitted' ? 'background:#e3f4ea;color:#1e7b4a' : 'background:#fff4dc;color:#8a5a00') . '">' . $this->t('st.' . $s) . '</span>';
    }

    private function statsLine(array $s): string
    {
        return '<span style="font-size:12px">' . $this->t('statsLine', ['a' => $s['answered'], 't' => $s['total'], 'd' => $s['done'], 'p' => $s['partial'], 'x' => $s['declined']]) . '</span>';
    }

    // ================================================================ المحرر
    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request);
        $rows = [];
        foreach ($this->store()->list($cid) as $r) {
            $rows[] = [(string) (int) $r->submission_id, '<b>' . self::e($this->title((int) $r->submission_id)) . '</b>', (string) (int) $r->round, $this->statusBadge($r->status),
                $this->statsLine(Store::stats($this->store()->items((int) $r->response_id))), '<span dir="ltr">' . self::e(substr((string) $r->updated_at, 0, 16)) . '</span>',
                $this->btn($this->url($request, 'view', ['id' => $r->response_id]), $this->t('openBtn'))];
        }
        // جولات تنتظر ردًّا لم يبدأ
        $wait = [];
        foreach ($this->store()->rounds($cid) as $rd) if (!$this->store()->forRound((int) $rd->review_round_id))
            $wait[] = [(string) (int) $rd->submission_id, self::e($this->title((int) $rd->submission_id)), (string) (int) $rd->round, '<span dir="ltr">' . self::e(substr((string) $rd->decided, 0, 10)) . '</span>'];
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>'
            . $this->box($this->t('responses'), $this->table(['#', $this->t('col.title'), $this->t('col.round'), $this->t('col.status'), $this->t('col.progress'), $this->t('col.updated'), ''], $rows))
            . $this->box($this->t('waiting'), $this->table(['#', $this->t('col.title'), $this->t('col.round'), $this->t('col.decided')], $wait));
        $this->show($request, $this->t('menu'), $h);
    }

    public function reopen($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        $r = $this->store()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r) $this->notFound();
        $this->store()->setStatus((int) $r->response_id, 'draft');
        $this->back($request, 'view', ['id' => $r->response_id], 'reopened');
    }

    // ================================================================ المؤلف
    public function mine($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $uid = (int) $request->getUser()->getId();
        $rows = [];
        foreach ($this->store()->rounds($cid, $uid) as $rd) {
            $r = $this->store()->forRound((int) $rd->review_round_id);
            $act = !$r || $r->status === 'draft'
                ? $this->btn($this->url($request, 'edit', ['submissionId' => $rd->submission_id, 'roundId' => $rd->review_round_id]), $this->t($r ? 'continue' : 'start'), true)
                : $this->btn($this->url($request, 'view', ['id' => $r->response_id]), $this->t('openBtn'));
            $rows[] = [(string) (int) $rd->submission_id, '<b>' . self::e($this->title((int) $rd->submission_id)) . '</b>', (string) (int) $rd->round, $r ? $this->statusBadge($r->status) : '—', $act];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p>' . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.round'), $this->t('col.status'), ''], $rows));
        $this->show($request, $this->t('myMenu'), $h);
    }

    private function authorRound($request): array
    {
        $this->login($request); $cid = $this->cid($request); $uid = (int) $request->getUser()->getId();
        $sid = (int) $request->getUserVar('submissionId'); $roundId = (int) $request->getUserVar('roundId');
        if (!$this->store()->isAuthor($sid, $uid)) $this->deny();
        $round = null;
        foreach ($this->store()->rounds($cid, $uid, $sid) as $rd) if ((int) $rd->review_round_id === $roundId) $round = $rd;
        if (!$round) $this->notFound();
        return [$cid, $uid, $round];
    }

    public function edit($args, $request)
    {
        [$cid, $uid, $round] = $this->authorRound($request);
        $r = $this->store()->forRound((int) $round->review_round_id);
        if (!$r) { $this->store()->create($cid, $round, $uid); $r = $this->store()->forRound((int) $round->review_round_id); }
        if ($r->status === 'submitted') { $request->redirectUrl($this->url($request, 'view', ['id' => $r->response_id])); return; }
        $items = $this->store()->items((int) $r->response_id);
        $groups = [];
        foreach ($this->store()->reviewers((int) $round->review_round_id) as $rv) $groups[$rv['review_id']] = ['label' => $this->rlabel($rv['label'], $rv['review_id']), 'items' => []];
        $groups[0] = ['label' => $this->rlabel(0), 'items' => []];
        foreach ($items as $it) $groups[$it->review_id] ??= ['label' => $this->rlabel(count($groups), 1), 'items' => []];
        foreach ($items as $it) $groups[$it->review_id]['items'][] = $it;
        $opt = fn ($cur) => implode('', array_map(fn ($a) => '<option value="' . $a . '"' . ($cur === $a ? ' selected' : '') . '>' . $this->t('act.' . $a) . '</option>', Store::ACTIONS));
        $n = 0;
        $h = $this->flash($request) . '<p style="margin-top:0;color:#444">' . $this->t('editHelp') . '</p><form method="post" action="' . self::e($this->url($request, 'save')) . '">' . $this->csrf($request)
            . '<input type="hidden" name="submissionId" value="' . (int) $round->submission_id . '"><input type="hidden" name="roundId" value="' . (int) $round->review_round_id . '">'
            . $this->box(self::e($this->title((int) $round->submission_id)) . ' — ' . $this->t('roundN', ['n' => $round->round]),
                '<p style="margin:0 0 4px;font-size:12px">' . $this->t('f.intro') . '</p><textarea name="intro" rows="3" style="' . self::S_INP . 'width:100%">' . self::e($r->intro) . '</textarea>');
        foreach ($groups as $rvId => $g) {
            $rows = '';
            $list = $g['items']; $list[] = (object) ['item_id' => 0, 'review_id' => $rvId, 'seq' => count($list) + 1, 'comment' => '', 'reply' => '', 'location' => '', 'action' => 'done'];
            foreach ($list as $k => $it) {
                $i = $n++; $p = 'it[' . $i . ']';
                $rows .= '<tr><td style="' . self::S_TD . 'width:30px">' . ($it->item_id ? ($k + 1) : '+') . '<input type="hidden" name="' . $p . '[id]" value="' . (int) $it->item_id . '"><input type="hidden" name="' . $p . '[review_id]" value="' . (int) $rvId . '"><input type="hidden" name="' . $p . '[seq]" value="' . ($k + 1) . '"></td>'
                    . '<td style="' . self::S_TD . 'width:34%"><textarea name="' . $p . '[comment]" rows="4" placeholder="' . self::e($this->t($it->item_id ? 'f.comment' : 'f.newComment')) . '" style="' . self::S_INP . 'width:100%;background:#f8f9fb">' . self::e($it->comment) . '</textarea></td>'
                    . '<td style="' . self::S_TD . '"><textarea name="' . $p . '[reply]" rows="4" placeholder="' . self::e($this->t('f.reply')) . '" style="' . self::S_INP . 'width:100%">' . self::e($it->reply) . '</textarea></td>'
                    . '<td style="' . self::S_TD . 'width:170px"><select name="' . $p . '[action]" style="' . self::S_INP . 'width:100%">' . $opt($it->action) . '</select><input name="' . $p . '[location]" value="' . self::e($it->location) . '" placeholder="' . self::e($this->t('f.location')) . '" style="' . self::S_INP . 'width:100%;margin-top:4px">'
                    . ($it->item_id ? '<label style="font-size:11px;color:#b02a2a"><input type="checkbox" name="' . $p . '[delete]" value="1"> ' . $this->t('delete') . '</label>' : '') . '</td></tr>';
            }
            $h .= $this->box(self::e($g['label']), '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.comment') . '</th><th style="' . self::S_TH . '">' . $this->t('col.reply') . '</th><th style="' . self::S_TH . '">' . $this->t('col.action') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
        }
        $h .= '<div style="position:sticky;bottom:0;background:#fff;padding:10px;border-top:1px solid #d5dbe1"><button name="do" value="draft" style="' . self::S_BTN . '">' . $this->t('saveDraft') . '</button>'
            . '<button name="do" value="submit" style="' . self::S_BTNP . '" onclick="return confirm(' . self::e(json_encode($this->t('confirmSubmit'), JSON_UNESCAPED_UNICODE)) . ')">' . $this->t('submit') . '</button></div></form>';
        $this->show($request, $this->t('myMenu'), $h);
    }

    public function save($args, $request)
    {
        $this->postOk($request);
        [$cid, $uid, $round] = $this->authorRound($request);
        $r = $this->store()->forRound((int) $round->review_round_id);
        if (!$r || $r->status !== 'draft') $this->deny();
        $items = [];
        foreach ((array) $request->getUserVar('it') as $it) {
            if (!is_array($it)) continue;
            $items[] = ['id' => (int) ($it['id'] ?? 0), 'review_id' => (int) ($it['review_id'] ?? 0), 'seq' => (int) ($it['seq'] ?? 0), 'comment' => trim((string) ($it['comment'] ?? '')),
                'reply' => trim((string) ($it['reply'] ?? '')), 'location' => trim((string) ($it['location'] ?? '')), 'action' => (string) ($it['action'] ?? 'done'), 'delete' => !empty($it['delete'])];
        }
        $submit = $request->getUserVar('do') === 'submit';
        if ($submit) {
            foreach ($items as $it) if (!$it['delete'] && $it['comment'] !== '' && $it['reply'] === '') {
                $this->store()->saveItems((int) $r->response_id, $items, trim((string) $request->getUserVar('intro')));
                $this->back($request, 'edit', ['submissionId' => $round->submission_id, 'roundId' => $round->review_round_id], 'errUnanswered'); return;
            }
        }
        $this->store()->saveItems((int) $r->response_id, $items, trim((string) $request->getUserVar('intro')), $submit ? 'submitted' : null);
        if ($submit) $this->back($request, 'view', ['id' => $r->response_id], 'submitted');
        else $this->back($request, 'edit', ['submissionId' => $round->submission_id, 'roundId' => $round->review_round_id]);
    }

    // ================================================================ العرض والطباعة
    private function matrix(object $r, bool $print = false): string
    {
        $order = $this->order((int) $r->review_round_id);
        $groups = [];
        foreach ($this->store()->items((int) $r->response_id) as $it) $groups[$it->review_id][] = $it;
        $bd = $print ? 'border:1px solid #999;padding:6px;vertical-align:top;' : self::S_TD;
        $th = $print ? 'border:1px solid #999;padding:6px;background:#eee;text-align:start;' : self::S_TH;
        $h = '';
        $colors = ['done' => '#1e7b4a', 'partial' => '#8a5a00', 'declined' => '#b02a2a'];
        foreach ($groups as $rvId => $list) {
            $h .= '<h3 style="font-size:15px;margin:16px 0 6px">' . self::e($this->rlabel($order[$rvId] ?? count($order), (int) $rvId)) . '</h3><table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr>'
                . '<th style="' . $th . 'width:28px">#</th><th style="' . $th . 'width:36%">' . $this->t('col.comment') . '</th><th style="' . $th . '">' . $this->t('col.reply') . '</th><th style="' . $th . 'width:150px">' . $this->t('col.action') . '</th></tr></thead><tbody>';
            foreach ($list as $k => $it) $h .= '<tr><td style="' . $bd . '">' . ($k + 1) . '</td><td style="' . $bd . 'background:#f8f9fb">' . nl2br(self::e($it->comment)) . '</td><td style="' . $bd . '">' . nl2br(self::e($it->reply)) . '</td>'
                . '<td style="' . $bd . '"><b style="color:' . $colors[$it->action] . '">' . $this->t('act.' . $it->action) . '</b>' . ($it->location !== '' ? '<div style="font-size:12px">' . $this->t('f.location') . ': ' . self::e($it->location) . '</div>' : '') . '</td></tr>';
            $h .= '</tbody></table>';
        }
        return $h;
    }

    public function view($args, $request)
    {
        $this->login($request);
        $r = $this->store()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r) $this->notFound();
        if (!$this->canView($request, $r)) $this->deny();
        $isAuthor = $this->store()->isAuthor((int) $r->submission_id, (int) $request->getUser()->getId());
        $s = Store::stats($this->store()->items((int) $r->response_id));
        $bar = $this->btn($this->url($request, 'print', ['id' => $r->response_id]), '🖨 ' . $this->t('print'), false, ' target="_blank"');
        if ($r->status === 'draft' && $isAuthor) $bar .= $this->btn($this->url($request, 'edit', ['submissionId' => $r->submission_id, 'roundId' => $r->review_round_id]), $this->t('continue'), true);
        if ($r->status === 'submitted' && $this->plugin->isEditor($request)) $bar .= $this->postBtn($request, 'reopen', ['id' => $r->response_id], $this->t('reopen'), false, $this->t('confirmReopen'));
        $h = $this->flash($request) . $this->box(self::e($this->title((int) $r->submission_id)) . ' — ' . $this->t('roundN', ['n' => $r->round]),
            '<div style="margin-bottom:8px">' . $this->statusBadge($r->status) . ' ' . $this->statsLine($s) . ($r->submitted_at ? ' · <span dir="ltr">' . self::e(substr($r->submitted_at, 0, 16)) . '</span>' : '') . '</div>'
            . ($r->intro ? '<div style="background:#f8f9fb;padding:8px;border-radius:6px;margin-bottom:8px">' . nl2br(self::e($r->intro)) . '</div>' : '') . $bar . $this->matrix($r));
        $this->show($request, $this->t($this->plugin->isEditor($request) ? 'menu' : 'myMenu'), $h);
    }

    public function print($args, $request)
    {
        $this->login($request);
        $r = $this->store()->get((int) $request->getUserVar('id'), $this->cid($request)); if (!$r) $this->notFound();
        if (!$this->canView($request, $r)) $this->deny();
        $ctx = $request->getContext(); $j = Meta::journal($ctx);
        $ar = str_starts_with((string) \PKP\facades\Locale::getLocale(), 'ar');
        $sub = Repo::submission()->get((int) $r->submission_id);
        $doc = '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><title>' . self::e($this->t('docTitle')) . '</title>'
            . '<style>body{font-family:"IBM Plex Sans Arabic","Segoe UI",Tahoma,sans-serif;margin:24px auto;max-width:900px;color:#111;line-height:1.7}h1{font-size:20px;margin:0}h2{font-size:15px;color:#444;font-weight:normal;margin:4px 0 14px}@media print{.np{display:none}}table{page-break-inside:auto}tr{page-break-inside:avoid}</style></head><body>'
            . '<div class="np" style="margin-bottom:12px"><button onclick="window.print()">🖨 ' . self::e($this->t('print')) . '</button></div>'
            . '<div style="border-bottom:2px solid #333;padding-bottom:8px;margin-bottom:12px"><b>' . self::e(Meta::pick($j['names'], $ar ? ['ar', 'en'] : ['en', 'ar'])) . '</b>' . ($j['eissn'] ? ' · ISSN ' . self::e($j['eissn']) : '') . '</div>'
            . '<h1>' . self::e($this->t('docTitle')) . '</h1><h2>' . self::e($sub ? strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle()) : '') . ' — ' . self::e($this->t('roundN', ['n' => $r->round])) . ' · #' . (int) $r->submission_id . '</h2>'
            . ($r->intro ? '<p>' . nl2br(self::e($r->intro)) . '</p>' : '') . $this->matrix($r, true)
            . '<p style="font-size:11px;color:#666;margin-top:24px">' . self::e($this->t('printFoot', ['d' => substr((string) ($r->submitted_at ?: $r->updated_at), 0, 10)])) . '</p></body></html>';
        header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex');
        echo $doc; exit;
    }
}
