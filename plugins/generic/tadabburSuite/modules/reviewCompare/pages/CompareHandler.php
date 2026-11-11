<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewCompare/pages/CompareHandler.php
 * المحرر: index، round، brief (مذكرة قرار للطباعة)
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewCompare\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\reviewCompare\classes\Reports;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;
use PKP\facades\Locale;

class CompareHandler extends ModuleHandler
{
    private const LETTERS = ['أ', 'ب', 'ج', 'د', 'هـ', 'و', 'ز', 'ح'];

    private function label(int $i): string { return $this->t('reviewer', ['l' => str_starts_with((string) Locale::getLocale(), 'ar') ? (self::LETTERS[$i] ?? (string) ($i + 1)) : chr(65 + $i)]); }
    private function rec(?int $r): string { return $r === null ? '—' : $this->t('rec.' . $r); }

    private function agreeBadge(string $lvl): string
    {
        $c = ['consensus' => ['#e3f4ea', '#1e7b4a'], 'close' => ['#e8f0fb', '#0b5394'], 'divergent' => ['#fbe9e9', '#b02a2a'], 'insufficient' => ['#f1f3f5', '#555']][$lvl];
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;background:' . $c[0] . ';color:' . $c[1] . '">' . $this->t('ag.' . $lvl) . '</span>';
    }

    private function loadRound($request): array
    {
        $rr = DB::table('review_rounds as rr')->join('submissions as s', 's.submission_id', '=', 'rr.submission_id')->where('rr.review_round_id', (int) $request->getUserVar('id'))
            ->where('s.context_id', $this->cid($request))->select('rr.*')->first();
        if (!$rr) $this->notFound();
        return [$rr, Reports::get((int) $rr->review_round_id)];
    }

    private function title(int $sid): string { $s = Repo::submission()->get($sid); return $s ? strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()) : ''; }

    public function index($args, $request)
    {
        $this->editor($request); $rows = [];
        foreach (Reports::rounds($this->cid($request)) as $rr) {
            $rep = Reports::get((int) $rr->review_round_id);
            $done = count(array_filter($rep, fn ($r) => $r['done']));
            [$sp, $med, $lvl] = Reports::agreement($rep); $sug = Reports::suggestion($med);
            $rows[] = [(string) (int) $rr->submission_id, '<b>' . self::e(mb_strimwidth($this->title((int) $rr->submission_id), 0, 80, '…')) . '</b>', (string) (int) $rr->round, $done . '/' . count($rep),
                implode(' ', array_map(fn ($r) => '<span style="font-size:11px">' . self::e($this->rec($r['rec'])) . '</span>', array_filter($rep, fn ($r) => $r['done']))),
                $this->agreeBadge($lvl), $sug ? self::e($this->rec($sug)) : '—', $this->btn($this->url($request, 'round', ['id' => $rr->review_round_id]), $this->t('compare'), true)];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.round'), $this->t('col.reports'), $this->t('col.recs'), $this->t('col.agreement'), $this->t('col.suggest'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    private function grid(array $rep, bool $names, bool $print = false): string
    {
        $done = array_values(array_filter($rep, fn ($r) => $r['done']));
        if (!$done) return '<p>' . $this->t('noReports') . '</p>';
        $td = $print ? 'border:1px solid #999;padding:6px;vertical-align:top;' : self::S_TD; $th = $print ? 'border:1px solid #999;padding:6px;background:#eee;text-align:start;' : self::S_TH;
        $h = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed"><thead><tr><th style="' . $th . 'width:170px"></th>';
        foreach ($done as $r) {
            $name = '';
            if ($names && ($u = Repo::user()->get($r['reviewer'], true))) $name = '<div style="font-weight:normal;font-size:11px;color:#666">' . self::e($u->getFullName()) . '</div>';
            $h .= '<th style="' . $th . '">' . self::e($this->label($r['i'])) . $name . '</th>';
        }
        $row = function (string $label, callable $f) use ($done, $td) { $x = '<tr><th style="' . $td . 'background:#f8f9fb;text-align:start">' . $label . '</th>'; foreach ($done as $r) $x .= '<td style="' . $td . '">' . $f($r) . '</td>'; return $x . '</tr>'; };
        $h .= '</tr></thead><tbody>' . $row($this->t('r.rec'), fn ($r) => '<b>' . self::e($this->rec($r['rec'])) . '</b>')
            . $row($this->t('r.days'), fn ($r) => $r['days'] === null ? '—' : $this->t('days', ['n' => $r['days']]) . ($r['late'] ? ' <span style="color:#b02a2a">(' . $this->t('late') . ')</span>' : ''));
        if (!$print) $h .= $row($this->t('r.quality'), fn ($r) => $r['quality'] ? str_repeat('★', $r['quality']) . str_repeat('☆', 5 - $r['quality']) : '—');
        // أسئلة نموذج التحكيم (النماذج المستعملة في الجولة)
        $forms = array_unique(array_filter(array_map(fn ($r) => $r['formId'], $done)));
        foreach ($forms as $fid) foreach (Reports::form($fid, (string) Locale::getLocale()) as $eid => $el)
            $h .= $row(self::e($el['q']), fn ($r) => isset($r['resp'][$eid]) ? nl2br(self::e(Reports::answer($el, $r['resp'][$eid]))) : '<span style="color:#aaa">—</span>');
        $txt = fn (array $list) => $list ? implode('<hr style="border:0;border-top:1px dashed #ccc">', array_map(fn ($c) => nl2br(self::e(Meta::plain($c))), $list)) : '<span style="color:#aaa">—</span>';
        $words = fn (array $list) => count(preg_split('/\s+/u', trim(Meta::plain(implode(' ', $list))), -1, PREG_SPLIT_NO_EMPTY));
        $h .= $row($this->t('r.toAuthor'), fn ($r) => '<div style="font-size:11px;color:#777">' . $this->t('words', ['n' => $words($r['toAuthor'])]) . '</div><div style="max-height:' . ($print ? 'none' : '320px') . ';overflow:auto">' . $txt($r['toAuthor']) . '</div>');
        $h .= $row($this->t('r.toEditor'), fn ($r) => '<div style="max-height:' . ($print ? 'none' : '220px') . ';overflow:auto">' . $txt($r['toEditor']) . '</div>');
        return $h . '</tbody></table></div>';
    }

    private function summary(array $rep): string
    {
        [$sp, $med, $lvl] = Reports::agreement($rep); $sug = Reports::suggestion($med);
        $counts = [];
        foreach ($rep as $r) if ($r['done'] && $r['rec'] !== null) $counts[$r['rec']] = ($counts[$r['rec']] ?? 0) + 1;
        $pend = count(array_filter($rep, fn ($r) => !$r['done']));
        return '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center"><div>' . $this->t('agreement') . ': ' . $this->agreeBadge($lvl) . '</div>'
            . '<div>' . $this->t('suggested') . ': <b>' . ($sug ? self::e($this->rec($sug)) : '—') . '</b></div>'
            . '<div style="font-size:12px">' . implode(' · ', array_map(fn ($k, $n) => self::e($this->rec((int) $k)) . ': ' . $n, array_keys($counts), $counts)) . '</div>'
            . ($pend ? '<div style="font-size:12px;color:#8a5a00">' . $this->t('pending', ['n' => $pend]) . '</div>' : '') . '</div>'
            . ($lvl === 'divergent' ? '<p style="margin:8px 0 0;color:#b02a2a;font-size:13px">' . $this->t('divergentHelp') . '</p>' : '')
            . '<p style="margin:6px 0 0;font-size:11px;color:#777">' . $this->t('suggestNote') . '</p>';
    }

    public function round($args, $request)
    {
        $this->editor($request); [$rr, $rep] = $this->loadRound($request);
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->btn($this->url($request, 'brief', ['id' => $rr->review_round_id]), '🖨 ' . $this->t('brief'), false, ' target="_blank"')
            . $this->box(self::e($this->title((int) $rr->submission_id)) . ' — ' . $this->t('roundN', ['n' => $rr->round]) . ' · #' . (int) $rr->submission_id, $this->summary($rep))
            . $this->box($this->t('side'), $this->grid($rep, true));
        $this->show($request, $this->t('menu'), $h);
    }

    public function brief($args, $request)
    {
        $this->editor($request); [$rr, $rep] = $this->loadRound($request);
        $ar = str_starts_with((string) Locale::getLocale(), 'ar'); $j = Meta::journal($request->getContext());
        echo '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><title>' . self::e($this->t('brief')) . '</title><style>body{font-family:"IBM Plex Sans Arabic","Segoe UI",Tahoma,sans-serif;max-width:1000px;margin:20px auto;line-height:1.6;color:#111}@media print{.np{display:none}}h1{font-size:19px;margin:0}h2{font-size:14px;color:#444;font-weight:normal;margin:4px 0 12px}</style></head><body>'
            . '<div class="np"><button onclick="window.print()">🖨</button></div><div style="border-bottom:2px solid #333;margin-bottom:10px;padding-bottom:6px"><b>' . self::e(Meta::pick($j['names'])) . '</b> — ' . self::e($this->t('brief')) . ' · ' . date('Y-m-d') . '</div>'
            . '<h1>' . self::e($this->title((int) $rr->submission_id)) . '</h1><h2>#' . (int) $rr->submission_id . ' · ' . self::e($this->t('roundN', ['n' => $rr->round])) . '</h2>'
            . $this->summary($rep) . '<div style="margin-top:12px">' . $this->grid($rep, false, true) . '</div>'
            . '<div style="margin-top:24px;display:flex;gap:40px"><div>' . self::e($this->t('decisionLine')) . ' ____________________</div><div>' . self::e($this->t('signLine')) . ' ____________________</div></div></body></html>';
        exit;
    }
}
