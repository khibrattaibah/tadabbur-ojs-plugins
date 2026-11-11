<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/issueBuilder/pages/BuilderHandler.php
 * المحرر: index، issue، toc ؛ المدير (POST): add، remove، move، paginate
 */
namespace APP\plugins\generic\tadabburSuite\m\issueBuilder\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\issueBuilder\classes\Readiness;
use APP\plugins\generic\tadabburSuite\m\issueBuilder\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;

class BuilderHandler extends ModuleHandler
{
    private function issueLabel($is): string
    {
        return trim($this->t('issueLabel', ['v' => $is->getVolume(), 'n' => $is->getNumber(), 'y' => $is->getYear()]) . ' ' . strip_tags((string) $is->getLocalizedTitle()));
    }

    private function getIssue($request)
    {
        $is = Repo::issue()->get((int) $request->getUserVar('id'));
        if (!$is || (int) $is->getJournalId() !== $this->cid($request)) $this->notFound();
        return $is;
    }

    /** البحوث المسندة للعدد مرتبة بالقسم ثم الترتيب */
    private function articles($request, $issue): array
    {
        $ctx = $request->getContext();
        $rows = DB::table('publications as p')->join('submissions as s', 's.current_publication_id', '=', 'p.publication_id')->leftJoin('sections as sec', 'sec.section_id', '=', 'p.section_id')
            ->where('p.issue_id', $issue->getId())->where('s.context_id', $ctx->getId())->orderBy('sec.seq')->orderBy('p.section_id')->orderBy('p.seq')->orderBy('p.publication_id')
            ->select('s.submission_id', 'p.publication_id', 'p.section_id', 'p.seq')->get();
        $out = [];
        foreach ($rows as $r) {
            $sub = Repo::submission()->get((int) $r->submission_id); if (!$sub) continue;
            $out[] = ['sub' => $sub, 'pub' => $sub->getCurrentPublication(), 'meta' => Meta::snapshot($sub, $ctx, $request)];
        }
        return $out;
    }

    private function candidates($request): array
    {
        $cid = $this->cid($request);
        $ids = DB::table('submissions as s')->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')->where('s.context_id', $cid)
            ->where('s.status', 1)->where('s.stage_id', '>=', 4)->whereNull('p.issue_id')->orderBy('s.submission_id')->pluck('s.submission_id')->all();
        return array_values(array_filter(array_map(fn ($id) => Repo::submission()->get((int) $id), $ids)));
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request);
        $rows = [];
        $issues = Repo::issue()->getCollector()->filterByContextIds([$cid])->orderBy(\APP\issue\Collector::ORDERBY_LAST_MODIFIED)->getMany()->all();
        usort($issues, fn ($a, $b) => (int) $a->getPublished() <=> (int) $b->getPublished());
        foreach ($issues as $is) {
            $n = DB::table('publications as p')->join('submissions as s', 's.current_publication_id', '=', 'p.publication_id')->where('p.issue_id', $is->getId())->count();
            $rows[] = ['<b>' . self::e($this->issueLabel($is)) . '</b>', $this->badge(!$is->getPublished(), $this->t($is->getPublished() ? 'published' : 'future')), (string) $n,
                $this->btn($this->url($request, 'issue', ['id' => $is->getId()]), $this->t('openIssue'), !$is->getPublished()) . $this->btn($this->url($request, 'toc', ['id' => $is->getId()]), $this->t('toc'), false, ' target="_blank"')];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->box($this->t('issues'), $this->table([$this->t('col.issue'), $this->t('col.state'), $this->t('col.count'), ''], $rows, $this->t('noIssues')))
            . $this->box($this->t('candidates'), $this->table(['#', $this->t('col.title')], array_map(fn ($s) => [(string) $s->getId(), self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()))], $this->candidates($request))));
        $this->show($request, $this->t('menu'), $h);
    }

    public function issue($args, $request)
    {
        $this->editor($request); $is = $this->getIssue($request);
        $mgr = $this->plugin->isManager($request) && !$is->getPublished();
        $arts = $this->articles($request, $is);
        $counts = (new Store())->counts(array_map(fn ($a) => (int) $a['pub']->getId(), $arts));
        $ready = 0; $rows = []; $sum = 0;
        foreach ($arts as $i => $a) {
            $m = $a['meta']; $chk = Readiness::check($m); $pid = (int) $a['pub']->getId();
            $req = !array_filter(Readiness::REQUIRED, fn ($k) => !$chk[$k]); if ($req) $ready++;
            $fails = ''; foreach ($chk as $k => $ok) if (!$ok) $fails .= '<span style="display:inline-block;margin:1px;padding:0 6px;border-radius:8px;font-size:11px;' . (in_array($k, Readiness::REQUIRED, true) ? 'background:#fbe9e9;color:#b02a2a' : 'background:#fff4dc;color:#8a5a00') . '">' . $this->t('chk.' . $k) . '</span>';
            $pc = (int) ($counts[$pid] ?? 0);
            if (!$pc && $m['firstPage'] !== '' && $m['lastPage'] !== '' && ctype_digit($m['firstPage']) && ctype_digit($m['lastPage'])) $pc = (int) $m['lastPage'] - (int) $m['firstPage'] + 1;
            $sum += $pc;
            $ctl = $mgr ? $this->postBtn($request, 'move', ['id' => $is->getId(), 'pub' => $pid, 'dir' => 'up'], '▲') . $this->postBtn($request, 'move', ['id' => $is->getId(), 'pub' => $pid, 'dir' => 'down'], '▼') : (string) ($i + 1);
            $rows[] = [$ctl, '<b>' . self::e(Meta::pick($m['titles'])) . '</b><div style="font-size:11px;color:#666">' . self::e(implode('، ', array_map(fn ($x) => Meta::pick($x['names']), $m['authors']))) . ' · #' . $m['id'] . '</div>',
                self::e(Meta::pick($m['section'])), '<span dir="ltr">' . self::e($m['pages']) . '</span>',
                $mgr ? '<input form="pg" type="number" min="0" name="cnt[' . $pid . ']" value="' . $pc . '" style="' . self::S_INP . 'width:70px">' : (string) $pc,
                ($req ? $this->badge(true, $this->t('ready')) : $this->badge(false, $this->t('notReady'))) . '<div>' . $fails . '</div>',
                $mgr ? $this->postBtn($request, 'remove', ['id' => $is->getId(), 'pub' => $pid], $this->t('remove'), false, $this->t('confirmRemove')) : ''];
        }
        $start = (int) ($request->getUserVar('start') ?: 1);
        $head = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px"><div><b style="font-size:22px">' . count($arts) . '</b> ' . $this->t('articles') . '</div><div><b style="font-size:22px">' . $ready . '</b> ' . $this->t('readyCount') . '</div><div><b style="font-size:22px">' . $sum . '</b> ' . $this->t('totalPages') . '</div></div>'
            . $this->btn($this->url($request, 'toc', ['id' => $is->getId(), 'lang' => 'ar']), $this->t('toc') . ' (ع)', false, ' target="_blank"') . $this->btn($this->url($request, 'toc', ['id' => $is->getId(), 'lang' => 'en']), $this->t('toc') . ' (EN)', false, ' target="_blank"');
        if ($is->getPublished()) $head .= '<p style="color:#8a5a00">' . $this->t('publishedRO') . '</p>';
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->flash($request) . $this->box(self::e($this->issueLabel($is)), $head);
        $tbl = $this->table(['', $this->t('col.title'), $this->t('col.section'), $this->t('col.pages'), $this->t('col.pageCount'), $this->t('col.ready'), ''], $rows, $this->t('emptyIssue'));
        if ($mgr) $tbl .= '<form id="pg" method="post" action="' . self::e($this->url($request, 'paginate')) . '" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">' . $this->csrf($request)
            . '<input type="hidden" name="id" value="' . (int) $is->getId() . '"><label style="font-size:12px">' . $this->t('startPage') . ' <input type="number" min="1" name="start" value="' . $start . '" style="' . self::S_INP . 'width:80px"></label>'
            . '<button name="apply" value="0" style="' . self::S_BTN . '">' . $this->t('saveCounts') . '</button><button name="apply" value="1" style="' . self::S_BTNP . '" onclick="return confirm(' . self::e(json_encode($this->t('confirmPaginate'), JSON_UNESCAPED_UNICODE)) . ')">' . $this->t('paginate') . '</button>'
            . '<span style="font-size:12px;color:#666">' . $this->t('paginateHelp') . '</span></form>';
        $h .= $this->box($this->t('contents'), $tbl);
        if ($mgr) {
            $c = array_map(fn ($s) => [(string) $s->getId(), '<b>' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</b><div style="font-size:11px;color:#666">' . self::e(strip_tags((string) $s->getCurrentPublication()->getShortAuthorString())) . '</div>',
                $this->postBtn($request, 'add', ['id' => $is->getId(), 'sub' => $s->getId()], '+ ' . $this->t('addToIssue'), true)], $this->candidates($request));
            $h .= $this->box($this->t('candidates'), '<p style="margin-top:0;font-size:12px;color:#666">' . $this->t('candHelp') . '</p>' . $this->table(['#', $this->t('col.title'), ''], $c));
        }
        $this->show($request, $this->t('menu'), $h);
    }

    private function editPub($pub, array $params): void { Repo::publication()->edit($pub, $params); }

    private function guard($request)
    {
        $this->manager($request); $is = $this->getIssue($request);
        if ($is->getPublished()) $this->deny();
        return $is;
    }

    private function pubIn($request, $is)
    {
        $pub = Repo::publication()->get((int) $request->getUserVar('pub'));
        if (!$pub || (int) $pub->getData('issueId') !== (int) $is->getId() || (int) $pub->getData('status') === 3) $this->notFound();
        return $pub;
    }

    public function add($args, $request)
    {
        $is = $this->guard($request);
        $sub = Repo::submission()->get((int) $request->getUserVar('sub'));
        if (!$sub || (int) $sub->getData('contextId') !== $this->cid($request)) $this->notFound();
        $pub = $sub->getCurrentPublication();
        if ($pub->getData('issueId') || (int) $pub->getData('status') === 3) $this->deny();
        $max = (int) DB::table('publications')->where('issue_id', $is->getId())->where('section_id', $pub->getData('sectionId'))->max('seq');
        $this->editPub($pub, ['issueId' => (int) $is->getId(), 'seq' => $max + 1]);
        $this->back($request, 'issue', ['id' => $is->getId()], 'added');
    }

    public function remove($args, $request)
    {
        $is = $this->guard($request); $pub = $this->pubIn($request, $is);
        $this->editPub($pub, ['issueId' => null]);
        $this->back($request, 'issue', ['id' => $is->getId()], 'removed');
    }

    public function move($args, $request)
    {
        $is = $this->guard($request); $pub = $this->pubIn($request, $is);
        $ids = DB::table('publications')->where('issue_id', $is->getId())->where('section_id', $pub->getData('sectionId'))->orderBy('seq')->orderBy('publication_id')->pluck('publication_id')->map(fn ($x) => (int) $x)->all();
        $i = array_search((int) $pub->getId(), $ids, true);
        $j = $request->getUserVar('dir') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
        foreach ($ids as $k => $id) DB::table('publications')->where('publication_id', $id)->update(['seq' => $k + 1]);
        $this->back($request, 'issue', ['id' => $is->getId()], 'moved');
    }

    public function paginate($args, $request)
    {
        $is = $this->guard($request); $store = new Store();
        $cnt = (array) $request->getUserVar('cnt');
        $arts = $this->articles($request, $is);
        $page = max(1, (int) $request->getUserVar('start'));
        $apply = (string) $request->getUserVar('apply') === '1';
        foreach ($arts as $a) {
            $pid = (int) $a['pub']->getId();
            $n = (int) ($cnt[$pid] ?? 0);
            $store->setCount($pid, $n);
            if ($apply && $n > 0 && (int) $a['pub']->getData('status') !== 3) { $this->editPub($a['pub'], ['pages' => $page . '-' . ($page + $n - 1)]); $page += $n; }
        }
        $this->back($request, 'issue', ['id' => $is->getId(), 'start' => (int) $request->getUserVar('start')], $apply ? 'paginated' : 'saved');
    }

    public function toc($args, $request)
    {
        $this->editor($request); $is = $this->getIssue($request);
        $en = $request->getUserVar('lang') === 'en'; $order = $en ? ['en', 'ar'] : ['ar', 'en'];
        $j = Meta::journal($request->getContext());
        $L = fn ($ar, $e) => $en ? $e : $ar;
        $groups = [];
        foreach ($this->articles($request, $is) as $a) $groups[Meta::pick($a['meta']['section'], $order)][] = $a['meta'];
        $h = '<!doctype html><html lang="' . ($en ? 'en' : 'ar') . '" dir="' . ($en ? 'ltr' : 'rtl') . '"><head><meta charset="utf-8"><title>TOC</title><style>body{font-family:"IBM Plex Sans Arabic","Segoe UI",Tahoma,sans-serif;max-width:820px;margin:30px auto;color:#111;line-height:1.6}'
            . 'h1{text-align:center;font-size:22px;margin:0}.sub{text-align:center;color:#555;margin-bottom:24px}h2{font-size:16px;border-bottom:2px solid #6b4f2a;padding-bottom:4px;margin-top:26px;color:#6b4f2a}.a{display:flex;gap:12px;margin:12px 0}.t{flex:1}.p{white-space:nowrap;font-weight:700}.au{color:#444;font-size:14px}.doi{font-size:12px;color:#0b5394;direction:ltr;unicode-bidi:embed}@media print{.np{display:none}}</style></head><body>'
            . '<div class="np"><button onclick="window.print()">🖨</button></div><h1>' . self::e(Meta::pick($j['names'], $order)) . '</h1><div class="sub">' . self::e($L('المجلد ', 'Volume ') . $is->getVolume() . $L('، العدد ', ', Issue ') . $is->getNumber() . ' (' . $is->getYear() . ')')
            . ($j['eissn'] ? ' · ISSN ' . self::e($j['eissn']) : '') . '</div><h2 style="text-align:center;border:0">' . $L('فهرس المحتويات', 'Table of Contents') . '</h2>';
        foreach ($groups as $sec => $list) {
            $h .= '<h2>' . self::e($sec) . '</h2>';
            foreach ($list as $m) $h .= '<div class="a"><div class="t"><b>' . self::e(Meta::pick($m['titles'], $order)) . '</b><div class="au">' . self::e(implode($en ? ', ' : '، ', array_map(fn ($x) => Meta::pick($x['names'], $order), $m['authors']))) . '</div>'
                . ($m['doi'] ? '<div class="doi">https://doi.org/' . self::e($m['doi']) . '</div>' : '') . '</div><div class="p" dir="ltr">' . self::e($m['pages']) . '</div></div>';
        }
        header('Content-Type: text/html; charset=utf-8'); echo $h . '</body></html>'; exit;
    }
}
