<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/earlyView/pages/EarlyHandler.php
 * عامة: index (القائمة)، paper، file ؛ المحرر: manage، POST save
 */
namespace APP\plugins\generic\tadabburSuite\m\earlyView\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\earlyView\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;
use PKP\facades\Locale;

class EarlyHandler extends ModuleHandler
{
    private function files(int $sid): array
    {
        return DB::table('submission_files as sf')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('sf.submission_id', $sid)->whereIn('sf.file_stage', [6, 9, 10, 11])
            ->where('f.mimetype', 'application/pdf')->orderByDesc('sf.updated_at')->select('sf.submission_file_id', 'sf.file_stage', 'f.path', 'sf.updated_at')->get()->all();
    }

    private function accepted(int $sid): string { return substr((string) DB::table('edit_decisions')->where('submission_id', $sid)->whereIn('decision', [2, 17])->max('date_decided'), 0, 10); }

    public function manage($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $st = (new Store())->all($cid);
        $ids = DB::table('submissions')->where('context_id', $cid)->whereIn('status', [1, 5])->where('stage_id', '>=', 4)->orderByDesc('submission_id')->pluck('submission_id')->all();
        $rows = [];
        foreach ($ids as $id) {
            $s = Repo::submission()->get((int) $id); if (!$s) continue;
            $e = $st[$id] ?? null; $files = $this->files((int) $id);
            $sel = '<select name="fileId" style="' . self::S_INP . 'max-width:260px"><option value="">' . $this->t('noFile') . '</option>' . implode('', array_map(fn ($f) => '<option value="' . (int) $f->submission_file_id . '"' . ($e && (int) $e->submission_file_id === (int) $f->submission_file_id ? ' selected' : '') . '>' . self::e($this->t('stage.' . $f->file_stage) . ' · ' . substr((string) $f->updated_at, 0, 10)) . '</option>', $files)) . '</select>';
            $rows[] = [(string) $id, '<b>' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</b><div style="font-size:11px;color:#777">' . $this->t('acceptedOn', ['d' => $this->accepted((int) $id) ?: '—']) . '</div>',
                '<form method="post" action="' . self::e($this->url($request, 'save')) . '" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">' . $this->csrf($request) . '<input type="hidden" name="sid" value="' . (int) $id . '">' . $sel
                . '<label style="font-size:12px"><input type="checkbox" name="shown" value="1"' . ($e && $e->shown ? ' checked' : '') . '> ' . $this->t('show') . '</label><button style="' . self::S_BTN . '">' . $this->t('save') . '</button></form>',
                $e && $e->shown ? $this->btn($this->url($request, 'paper', ['sid' => $id]), $this->t('view'), false, ' target="_blank"') : ''];
        }
        $pub = $this->url($request, 'index');
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request)
            . '<p style="font-size:13px">' . $this->t('publicLink') . ' <a href="' . self::e($pub) . '" target="_blank" dir="ltr">' . self::e($pub) . '</a></p>'
            . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.early'), ''], $rows, $this->t('noAccepted')));
        $this->show($request, $this->t('menu'), $h);
    }

    public function save($args, $request)
    {
        $this->editor($request); $this->postOk($request); $cid = $this->cid($request); $sid = (int) $request->getUserVar('sid');
        if (!DB::table('submissions')->where('submission_id', $sid)->where('context_id', $cid)->exists()) $this->notFound();
        $fid = (int) $request->getUserVar('fileId');
        if ($fid && !in_array($fid, array_map(fn ($f) => (int) $f->submission_file_id, $this->files($sid)), true)) $fid = 0;
        (new Store())->set($sid, $cid, (bool) $request->getUserVar('shown'), $fid ?: null);
        $this->back($request, 'manage');
    }

    private function citation(array $m, array $j, bool $ar): string
    {
        $names = implode($ar ? '، ' : ', ', array_map(fn ($a) => Meta::pick($a['names'], $ar ? ['ar', 'en'] : ['en', 'ar']), $m['authors']));
        return $names . '. ' . Meta::pick($m['titles'], $ar ? ['ar', 'en'] : ['en', 'ar']) . '. ' . Meta::pick($j['names'], $ar ? ['ar', 'en'] : ['en', 'ar']) . ' (' . ($ar ? 'قيد النشر' : 'in press') . ')' . ($m['doi'] ? '. https://doi.org/' . $m['doi'] : '.');
    }

    public function index($args, $request)
    {
        $cid = $this->cid($request); $ctx = $request->getContext(); $ar = str_starts_with((string) Locale::getLocale(), 'ar'); $o = $ar ? ['ar', 'en'] : ['en', 'ar'];
        $h = '<p>' . $this->t('publicIntro') . '</p>';
        foreach ((new Store())->visible($cid) as $sid => $e) {
            $s = Repo::submission()->get((int) $sid); if (!$s) continue; $m = Meta::snapshot($s, $ctx, $request);
            $h .= '<div style="border:1px solid #e1d9cc;border-radius:8px;padding:12px 14px;margin:0 0 14px"><div style="font-size:.8rem;color:#8a5a00;font-weight:700">' . $this->t('badge') . ' · ' . $this->t('acceptedOn', ['d' => $this->accepted((int) $sid)]) . '</div>'
                . '<h3 style="margin:6px 0"><a href="' . self::e($this->url($request, 'paper', ['sid' => $sid])) . '">' . self::e(Meta::pick($m['titles'], $o)) . '</a></h3>'
                . '<div style="color:#444">' . self::e(implode('، ', array_map(fn ($a) => Meta::pick($a['names'], $o), $m['authors']))) . '</div></div>';
        }
        $this->showPublic($request, $this->t('publicTitle'), $h);
    }

    private function visibleOr404($request, int $sid): object
    {
        $e = (new Store())->visible($this->cid($request))[$sid] ?? null;
        if (!$e) {
            // إن نُشر البحث نحوّل إلى صفحته النهائية
            $s = Repo::submission()->get($sid);
            if ($s && (int) $s->getData('contextId') === $this->cid($request) && (int) $s->getData('status') === 3) { $request->redirectUrl($request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$s->getBestId()])); exit; }
            $this->notFound();
        }
        return $e;
    }

    public function paper($args, $request)
    {
        $sid = (int) $request->getUserVar('sid'); $e = $this->visibleOr404($request, $sid);
        $ctx = $request->getContext(); $m = Meta::snapshot(Repo::submission()->get($sid), $ctx, $request); $j = Meta::journal($ctx);
        $ar = str_starts_with((string) Locale::getLocale(), 'ar'); $o = $ar ? ['ar', 'en'] : ['en', 'ar'];
        $au = '';
        foreach ($m['authors'] as $a) $au .= '<li><b>' . self::e(Meta::pick($a['names'], $o)) . '</b>' . (Meta::pick($a['affiliations'], $o) ? ' — ' . self::e(Meta::pick($a['affiliations'], $o)) : '') . ($a['orcidId'] ? ' <a href="https://orcid.org/' . self::e($a['orcidId']) . '">ORCID</a>' : '') . '</li>';
        $h = '<div style="background:#fff4dc;border:1px solid #e8c77a;border-radius:8px;padding:10px 14px;margin-bottom:14px">⏳ ' . $this->t('notice') . '</div>'
            . (!empty($m['titles'][$o[1]]) ? '<p style="color:#555;font-size:1.05rem" dir="auto">' . self::e($m['titles'][$o[1]]) . '</p>' : '')
            . '<ul style="padding-inline-start:18px">' . $au . '</ul><p style="font-size:.9rem;color:#666">' . $this->t('acceptedOn', ['d' => $this->accepted($sid)]) . ' · ' . $this->t('submittedOn', ['d' => $m['dateSubmitted']]) . '</p>';
        foreach ($o as $l) if (!empty($m['abstracts'][$l])) $h .= '<h3>' . ($l === 'ar' ? 'الملخص' : 'Abstract') . '</h3><p dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '" style="line-height:1.9">' . nl2br(self::e($m['abstracts'][$l])) . '</p>'
            . (!empty($m['keywords'][$l]) ? '<p dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '"><b>' . ($l === 'ar' ? 'الكلمات المفتاحية: ' : 'Keywords: ') . '</b>' . self::e(implode($l === 'ar' ? '، ' : ', ', $m['keywords'][$l])) . '</p>' : '');
        if ($e->submission_file_id) $h .= '<p><a class="cmp_button" href="' . self::e($this->url($request, 'file', ['sid' => $sid])) . '">⬇ ' . $this->t('download') . '</a></p>';
        $h .= '<h3>' . $this->t('howToCite') . '</h3><p style="background:#f7f3ec;padding:8px 12px;border-radius:6px">' . self::e($this->citation($m, $j, $ar)) . '</p>';
        header('X-Robots-Tag: noarchive');
        $this->showPublic($request, Meta::pick($m['titles'], $o), $h);
    }

    public function file($args, $request)
    {
        $sid = (int) $request->getUserVar('sid'); $e = $this->visibleOr404($request, $sid);
        if (!$e->submission_file_id) $this->notFound();
        $p = DB::table('submission_files as sf')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('sf.submission_file_id', $e->submission_file_id)->value('f.path');
        $fs = app()->get('file')->fs; if (!$p || !$fs->has($p)) $this->notFound();
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="accepted-manuscript-' . $sid . '.pdf"'); header('X-Robots-Tag: noindex');
        echo $fs->read($p); exit;
    }
}
