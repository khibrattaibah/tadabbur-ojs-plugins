<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/depositHub/pages/HubHandler.php
 * المحرر: index، csv ؛ POST: check، checkBatch، refreshJournal
 */
namespace APP\plugins\generic\tadabburSuite\m\depositHub\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\depositHub\classes\Checker;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;

class HubHandler extends ModuleHandler
{
    private const DOI_ST = [1 => 'unregistered', 2 => 'submitted', 3 => 'registered', 4 => 'error', 5 => 'stale'];

    /** البحوث المنشورة مع DOI وحالته في OJS */
    private function published(int $cid): array
    {
        return DB::table('submissions as s')->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')->leftJoin('dois as d', 'd.doi_id', '=', 'p.doi_id')
            ->where('s.context_id', $cid)->where('s.status', 3)->orderByDesc('p.date_published')->orderByDesc('s.submission_id')
            ->select('s.submission_id', 'p.publication_id', 'p.date_published', 'd.doi', 'd.status as doi_status')->get()->all();
    }

    private function urls($request, int $sid): array
    {
        $sub = Repo::submission()->get($sid); if (!$sub) return [];
        $d = $request->getDispatcher(); $ctx = $request->getContext();
        $u = [$d->url($request, PKPApplication::ROUTE_PAGE, $ctx->getPath(), 'article', 'view', [$sub->getBestId()]), $d->url($request, PKPApplication::ROUTE_PAGE, $ctx->getPath(), 'article', 'view', [$sid])];
        // دون رمز اللغة في الرابط
        foreach ($u as $x) $u[] = preg_replace('~/(ar|en)/article/~', '/article/', $x);
        return array_unique($u);
    }

    private function tri(?int $v, string $yes = '', string $no = ''): string
    {
        if ($v === null) return '<span style="color:#999">—</span>';
        return $this->badge((bool) $v, $v ? $yes : $no);
    }

    private function journalCard($request): string
    {
        $cid = $this->cid($request); $j = json_decode((string) $this->plugin->getSetting($cid, 'journalCheck'), true);
        $btn = $this->plugin->isManager($request) ? $this->postBtn($request, 'refreshJournal', [], '⟳ ' . $this->t('refresh')) : '';
        if (!$j) return $this->box($this->t('journal'), '<p>' . $this->t('notChecked') . '</p>' . $btn);
        $pct = fn ($v) => $v === null ? '—' : round(100 * (float) $v) . '%';
        $h = '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        $card = fn ($t, $body) => '<div style="border:1px solid #e1e4e8;border-radius:8px;padding:10px 12px;min-width:230px;flex:1"><b>' . $t . '</b><div style="font-size:13px;margin-top:6px">' . $body . '</div></div>';
        $cr = $j['crossref'] ?? null;
        $h .= $card('Crossref', $cr ? $this->t('crDois', ['n' => $cr['dois']]) . '<table style="margin-top:6px;font-size:12px">' . implode('', array_map(fn ($k) => '<tr><td style="padding:1px 8px 1px 0">' . $this->t('cov.' . $k) . '</td><td><b>' . $pct($cr[$k]) . '</b></td></tr>', ['abstracts', 'references', 'orcids', 'affiliations', 'ror', 'licenses', 'similarity'])) . '</table>'
            : $this->badge(false, $this->t('notFound')));
        $oa = $j['openalex'] ?? null;
        $h .= $card('OpenAlex', $oa ? $this->t('oaLine', ['w' => $oa['works'], 'c' => $oa['cited'], 'h' => $oa['h']]) . '<div><a target="_blank" href="https://openalex.org/' . self::e($oa['id']) . '">' . self::e($oa['id']) . '</a></div>' : $this->badge(false, $this->t('notFound')));
        $dj = $j['doaj'] ?? null;
        $h .= $card('DOAJ', $dj && $dj['found'] ? $this->badge(true, $this->t('listed')) . ($dj['since'] ? '<div style="font-size:12px">' . $this->t('since') . ' ' . self::e(substr($dj['since'], 0, 10)) . '</div>' : '') : $this->badge(false, $this->t('notListed')));
        return $this->box($this->t('journal'), $h . '</div><div style="font-size:12px;color:#666;margin-top:8px">' . $this->t('checkedAt', ['d' => self::e($j['at'] ?? '')]) . ' ' . $btn . '</div>');
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $ctx = $request->getContext();
        $pubs = $this->published($cid); $cache = (new Checker())->cached($cid);
        $only = $request->getUserVar('problems') ? true : false;
        $sum = ['n' => count($pubs), 'doi' => 0, 'reg' => 0, 'cr' => 0, 'land' => 0, 'oa' => 0, 'cited' => 0];
        $rows = [];
        foreach ($pubs as $p) {
            $c = $cache[$p->submission_id] ?? null; $doi = (string) ($p->doi ?? '');
            if ($doi !== '') $sum['doi']++;
            if ((int) $p->doi_status === 3) $sum['reg']++;
            if ($c && $c->crossref) $sum['cr']++;
            if ($c && $c->landing_ok) $sum['land']++;
            if ($c && $c->openalex_id !== '') $sum['oa']++;
            $sum['cited'] += (int) ($c->cited ?? 0);
            $problem = $doi === '' || (int) $p->doi_status !== 3 || ($c && ($c->crossref === 0 || $c->landing_ok === 0));
            if ($only && !$problem) continue;
            $sub = Repo::submission()->get((int) $p->submission_id);
            $title = $sub ? strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle()) : '';
            $st = self::DOI_ST[(int) $p->doi_status] ?? 'none';
            $rows[] = [(string) (int) $p->submission_id, '<b>' . self::e(mb_strimwidth($title, 0, 90, '…')) . '</b>',
                $doi !== '' ? '<a dir="ltr" target="_blank" href="https://doi.org/' . self::e($doi) . '">' . self::e($doi) . '</a>' : $this->badge(false, $this->t('noDoi')),
                $doi === '' ? '—' : $this->badge($st === 'registered', $this->t('doi.' . $st)),
                $c ? $this->tri($c->crossref === null ? null : (int) $c->crossref) . ($c->crossref ? '<div style="font-size:11px">' . $this->t('crMeta') . ' ' . $this->tri((int) $c->cr_abstract, $this->t('abs')) . $this->tri((int) $c->cr_refs, $this->t('refs')) . $this->tri((int) $c->cr_orcid, 'ORCID') . '</div>' : '') : '—',
                $c ? $this->tri($c->landing_ok === null ? null : (int) $c->landing_ok) . ($c->landing_ok === 0 && $c->landing !== '' ? '<div style="font-size:11px;direction:ltr;word-break:break-all">' . self::e($c->landing) . '</div>' : '') : '—',
                $c ? ($c->openalex_id !== '' ? '<a target="_blank" href="https://openalex.org/' . self::e($c->openalex_id) . '">✔</a>' : $this->tri($c->checked_at && $doi !== '' ? 0 : null)) : '—',
                $c && $c->cited !== null ? '<b>' . (int) $c->cited . '</b>' : '—',
                ($c ? '<div style="font-size:11px;color:#777" dir="ltr">' . self::e(substr((string) $c->checked_at, 0, 16)) . '</div>' : '') . ($doi !== '' ? $this->postBtn($request, 'check', ['sid' => $p->submission_id], $this->t('checkNow')) : '')
                . ($sub ? '<a style="font-size:11px" target="_blank" href="https://scholar.google.com/scholar?q=' . rawurlencode('"' . mb_substr($title, 0, 120) . '"') . '">Scholar</a>' : '')];
        }
        $card = fn ($n, $l, $ok = true) => '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px;min-width:120px"><div style="font-size:22px;font-weight:700;color:' . ($ok ? '#222' : '#b02a2a') . '">' . $n . '</div><div style="font-size:12px;color:#555">' . $l . '</div></div>';
        $cards = '<div style="display:flex;gap:8px;flex-wrap:wrap">' . $card($sum['n'], $this->t('s.published')) . $card($sum['doi'], $this->t('s.doi'), $sum['doi'] === $sum['n']) . $card($sum['reg'], $this->t('s.reg'), $sum['reg'] === $sum['doi'])
            . $card($sum['cr'], $this->t('s.cr')) . $card($sum['land'], $this->t('s.land')) . $card($sum['oa'], $this->t('s.oa')) . $card($sum['cited'], $this->t('s.cited')) . '</div>';
        $oaKey = Services::openalexKey($cid);
        $bar = '<div style="margin:10px 0">' . $this->postBtn($request, 'checkBatch', [], '⟳ ' . $this->t('checkBatch'), true) . $this->btn($this->url($request, 'index', $only ? [] : ['problems' => 1]), $this->t($only ? 'showAll' : 'showProblems'))
            . $this->btn($this->url($request, 'csv'), 'CSV') . '<span style="font-size:12px;color:#666;margin-inline-start:8px">' . ($oaKey !== '' ? '✔ ' . $this->t('oaKeyOn') : $this->t('oaKeyOff')) . '</span></div>';
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request) . $this->journalCard($request)
            . $this->box($this->t('articles'), $cards . $bar . $this->table(['#', $this->t('col.title'), 'DOI', $this->t('col.ojs'), 'Crossref', $this->t('col.landing'), 'OpenAlex', $this->t('col.cited'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    private function runCheck($request, int $sid): void
    {
        $cid = $this->cid($request); $ctx = $request->getContext();
        foreach ($this->published($cid) as $p) if ((int) $p->submission_id === $sid) {
            (new Checker())->check($cid, $sid, (string) ($p->doi ?? ''), $this->urls($request, $sid), Services::mailto($ctx), Services::openalexKey($cid));
            return;
        }
        $this->notFound();
    }

    public function check($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        $this->runCheck($request, (int) $request->getUserVar('sid'));
        $this->back($request, 'index', [], 'checked');
    }

    /** يفحص حتى 20 بحثًا: غير المفحوصة أولًا ثم الأقدم فحصًا */
    public function checkBatch($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        @set_time_limit(300);
        $cid = $this->cid($request); $cache = (new Checker())->cached($cid);
        $list = array_values(array_filter($this->published($cid), fn ($p) => (string) $p->doi !== ''));
        usort($list, fn ($a, $b) => strcmp((string) ($cache[$a->submission_id]->checked_at ?? ''), (string) ($cache[$b->submission_id]->checked_at ?? '')));
        foreach (array_slice($list, 0, 20) as $p) $this->runCheck($request, (int) $p->submission_id);
        $this->back($request, 'index', [], 'checkedBatch');
    }

    public function refreshJournal($args, $request)
    {
        $this->manager($request); $ctx = $request->getContext(); $cid = $this->cid($request); $j = Meta::journal($ctx);
        $r = Checker::journal([$j['eissn'], $j['issn']], Services::mailto($ctx), Services::openalexKey($cid)); $r['at'] = date('Y-m-d H:i');
        $this->plugin->updateSetting($cid, 'journalCheck', json_encode($r, JSON_UNESCAPED_UNICODE), 'string');
        $this->back($request, 'index', [], 'checked');
    }

    public function csv($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $cache = (new Checker())->cached($cid);
        $f = fopen('php://temp', 'w+'); fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['submission_id', 'doi', 'ojs_doi_status', 'crossref', 'crossref_abstract', 'crossref_references', 'crossref_orcid', 'doi_landing', 'landing_ok', 'openalex_id', 'cited', 'checked_at']);
        foreach ($this->published($cid) as $p) { $c = $cache[$p->submission_id] ?? null;
            fputcsv($f, [$p->submission_id, $p->doi, self::DOI_ST[(int) $p->doi_status] ?? '', $c->crossref ?? '', $c->cr_abstract ?? '', $c->cr_refs ?? '', $c->cr_orcid ?? '', $c->landing ?? '', $c->landing_ok ?? '', $c->openalex_id ?? '', $c->cited ?? '', $c->checked_at ?? '']); }
        rewind($f); $this->download('deposit-status-' . date('Ymd') . '.csv', 'text/csv; charset=utf-8', stream_get_contents($f));
    }
}
