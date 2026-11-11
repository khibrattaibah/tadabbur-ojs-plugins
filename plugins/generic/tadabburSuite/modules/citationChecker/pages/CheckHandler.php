<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/citationChecker/pages/CheckHandler.php
 * المحرر: index، check ؛ POST: run، paste ؛ المدير POST: apply (إضافة DOI المقترحة إلى المراجع)
 */
namespace APP\plugins\generic\tadabburSuite\m\citationChecker\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\citationChecker\classes\Checker;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckHandler extends ModuleHandler
{
    private function sub($request)
    {
        $s = Repo::submission()->get((int) $request->getUserVar('sid'));
        if (!$s || (int) $s->getData('contextId') !== $this->cid($request)) $this->notFound();
        return $s;
    }

    /** المراجع: من OJS، وإلا من متن Word المحوَّل في وحدة JATS */
    private function refs($sub): array
    {
        $r = DB::table('citations')->where('publication_id', $sub->getCurrentPublication()->getId())->orderBy('seq')->pluck('raw_citation')->all();
        if ($r) return [$r, 'ojs'];
        if (Schema::hasTable('jats_sources') && ($c = DB::table('jats_sources')->where('submission_id', $sub->getId())->value('conv'))) {
            $refs = json_decode($c, true)['refs'] ?? [];
            if ($refs) return [$refs, 'word'];
        }
        return [[], 'none'];
    }

    private function summary(array $items): array
    {
        $s = ['n' => count($items), 'issues' => 0, 'retracted' => 0, 'suggest' => 0, 'doi' => 0];
        foreach ($items as $it) { if ($it['issues']) $s['issues']++; if ($it['retracted']) $s['retracted']++; if ($it['suggest']) $s['suggest']++; if ($it['doi']) $s['doi']++; }
        return $s;
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $ck = new Checker();
        $ids = DB::table('submissions')->where('context_id', $cid)->whereNotNull('date_submitted')->whereIn('status', [1, 3, 5])->orderByDesc('submission_id')->limit(60)->pluck('submission_id')->all();
        $rows = [];
        foreach ($ids as $id) {
            $s = Repo::submission()->get((int) $id); if (!$s) continue;
            [$refs, $src] = $this->refs($s); $c = $ck->cached((int) $id);
            $sum = $c ? $this->summary($c['items']) : null;
            $rows[] = [(string) $id, '<b>' . self::e(mb_strimwidth(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()), 0, 80, '…')) . '</b>', count($refs) . ($src === 'word' ? ' <span style="font-size:11px;color:#777">(Word)</span>' : ''),
                $sum ? ($sum['retracted'] ? $this->badge(false, $this->t('retractedN', ['n' => $sum['retracted']])) . ' ' : '') . $this->t('sumLine', ['i' => $sum['issues'], 'n' => $sum['n'], 's' => $sum['suggest']]) : '—',
                $this->btn($this->url($request, 'check', ['sid' => $id]), $this->t('openBtn'), true)];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.refs'), $this->t('col.result'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    private function render($request, array $items, ?int $sid, bool $canApply): string
    {
        $s = $this->summary($items); $brk = Checker::orderBreak($items);
        $card = fn ($n, $l, $bad = false) => '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px;min-width:110px"><div style="font-size:20px;font-weight:700;color:' . ($bad ? '#b02a2a' : '#222') . '">' . $n . '</div><div style="font-size:12px;color:#555">' . $l . '</div></div>';
        $h = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">' . $card($s['n'], $this->t('s.refs')) . $card($s['doi'], $this->t('s.doi')) . $card($s['issues'], $this->t('s.issues'), $s['issues'] > 0)
            . $card($s['suggest'], $this->t('s.suggest')) . $card($s['retracted'], $this->t('s.retracted'), $s['retracted'] > 0) . '</div>'
            . ($brk ? '<p style="color:#8a5a00">⚠ ' . $this->t('orderBreak', ['n' => $brk]) . '</p>' : '');
        $rows = [];
        foreach ($items as $it) {
            $notes = '';
            foreach ($it['issues'] as [$k, $p]) $notes .= '<div style="color:' . (in_array($k, ['retracted', 'doiNotFound', 'doiMismatch'], true) ? '#b02a2a' : '#8a5a00') . ';font-size:12px">⚠ ' . self::e($this->t('i.' . $k, $p)) . '</div>';
            if (in_array('doiOk', $it['ok'], true)) $notes .= '<div style="color:#1e7b4a;font-size:12px">✔ ' . $this->t('i.doiOk') . (!empty($it['found']['journal']) ? ' — ' . self::e($it['found']['journal']) : '') . '</div>';
            if ($it['suggest']) $notes .= '<div style="font-size:12px;background:#e8f0fb;padding:4px 6px;border-radius:4px;margin-top:3px">💡 ' . $this->t('i.suggest') . ' <a dir="ltr" target="_blank" href="https://doi.org/' . self::e($it['suggest']['doi']) . '">' . self::e($it['suggest']['doi']) . '</a><div style="color:#555">' . self::e($it['suggest']['title'] . ' (' . $it['suggest']['year'] . ')') . '</div>'
                . ($canApply ? '<label><input type="checkbox" form="apply" name="apply[]" value="' . ($it['n'] - 1) . '" checked> ' . $this->t('addDoi') . '</label>' : '') . '</div>';
            $rows[] = [(string) $it['n'], '<div dir="auto" style="font-size:13px">' . self::e($it['text']) . '</div>', $notes ?: '<span style="color:#1e7b4a">✔</span>'];
        }
        $h .= $this->table(['#', $this->t('col.ref'), $this->t('col.notes')], $rows);
        if ($canApply && $s['suggest'] && $sid) $h .= '<form id="apply" method="post" action="' . self::e($this->url($request, 'apply')) . '" style="margin-top:10px" onsubmit="return confirm(' . self::e(json_encode($this->t('confirmApply'), JSON_UNESCAPED_UNICODE)) . ')">' . $this->csrf($request)
            . '<input type="hidden" name="sid" value="' . $sid . '"><button style="' . self::S_BTNP . '">' . $this->t('applyBtn') . '</button></form>';
        return $h;
    }

    public function check($args, $request)
    {
        $this->editor($request); $sub = $this->sub($request); $sid = (int) $sub->getId();
        [$refs, $src] = $this->refs($sub); $c = (new Checker())->cached($sid);
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->flash($request)
            . $this->box(self::e(strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle())) . ' · #' . $sid,
                '<p style="margin-top:0;font-size:13px">' . $this->t('src.' . $src, ['n' => count($refs)]) . ($c ? ' · ' . $this->t('lastCheck', ['d' => substr($c['at'], 0, 16)]) : '') . '</p>'
                . ($refs ? $this->postBtn($request, 'run', ['sid' => $sid], '⟳ ' . $this->t('run'), true) : '')
                . '<details style="margin-top:8px"><summary style="cursor:pointer">' . $this->t('pasteTitle') . '</summary><form method="post" action="' . self::e($this->url($request, 'run')) . '">' . $this->csrf($request) . '<input type="hidden" name="sid" value="' . $sid . '">'
                . '<textarea name="paste" rows="8" placeholder="' . self::e($this->t('pasteHint')) . '" style="' . self::S_INP . 'width:100%"></textarea><button style="' . self::S_BTN . '">' . $this->t('run') . '</button></form></details>');
        if ($c) $h .= $this->box($this->t('results'), $this->render($request, $c['items'], $sid, $this->plugin->isManager($request) && $src === 'ojs' && !($c['items'][0]['pasted'] ?? false)));
        $this->show($request, $this->t('menu'), $h);
    }

    public function run($args, $request)
    {
        $this->editor($request); $this->postOk($request); $sub = $this->sub($request); @set_time_limit(300);
        $paste = trim((string) $request->getUserVar('paste'));
        $refs = $paste !== '' ? array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $paste)))) : $this->refs($sub)[0];
        $items = (new Checker())->run($refs, Services::mailto($request->getContext()));
        if ($paste !== '') foreach ($items as &$x) { $x['pasted'] = true; } unset($x);
        (new Checker())->save((int) $sub->getId(), $this->cid($request), $items);
        $this->back($request, 'check', ['sid' => $sub->getId()], 'checked');
    }

    public function apply($args, $request)
    {
        $this->manager($request); $sub = $this->sub($request); $c = (new Checker())->cached((int) $sub->getId()); if (!$c) $this->notFound();
        [$refs, $src] = $this->refs($sub); if ($src !== 'ojs') $this->deny();
        $sel = array_map('intval', (array) $request->getUserVar('apply')); $n = 0;
        foreach ($c['items'] as $it) {
            $i = $it['n'] - 1;
            if (!in_array($i, $sel, true) || empty($it['suggest']['doi']) || !isset($refs[$i]) || stripos($refs[$i], $it['suggest']['doi']) !== false) continue;
            $refs[$i] = rtrim($refs[$i]) . ' https://doi.org/' . $it['suggest']['doi']; $n++;
        }
        if ($n) {
            $pub = $sub->getCurrentPublication();
            Repo::publication()->edit($pub, ['citationsRaw' => implode("\n", $refs)]);
            $items = (new Checker())->run($refs, Services::mailto($request->getContext()), false);
            foreach ($items as $k => $x) if (isset($c['items'][$k]['found'])) { $items[$k]['found'] = $c['items'][$k]['found']; $items[$k]['ok'] = $c['items'][$k]['ok']; }
            (new Checker())->save((int) $sub->getId(), $this->cid($request), $items);
        }
        $this->back($request, 'check', ['sid' => $sub->getId()], 'applied');
    }
}
