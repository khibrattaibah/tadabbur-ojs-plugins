<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/authorTracker/pages/TrackerHandler.php
 * المؤلف: mine، view ؛ المحرر: index، view (معاينة ما يراه المؤلف)
 */
namespace APP\plugins\generic\tadabburSuite\m\authorTracker\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\authorTracker\classes\Timeline;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;

class TrackerHandler extends ModuleHandler
{
    private const STEPS = ['received', 'review', 'revision', 'copyediting', 'production', 'published'];

    private function title(int $sid): string
    {
        $s = Repo::submission()->get($sid);
        return $s ? strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()) : '#' . $sid;
    }

    private function stepper(int $cur): string
    {
        if ($cur < 0) return '';
        $h = '<div style="display:flex;gap:4px;margin:8px 0;flex-wrap:wrap">';
        foreach (self::STEPS as $i => $k) {
            $bg = $i < $cur ? '#1e7b4a' : ($i === $cur ? '#006798' : '#e1e4e8'); $fg = $i <= $cur ? '#fff' : '#555';
            $h .= '<div style="flex:1;min-width:90px;text-align:center;padding:5px 4px;border-radius:4px;font-size:12px;font-weight:700;background:' . $bg . ';color:' . $fg . '">' . ($i < $cur ? '✔ ' : '') . $this->t('step.' . $k) . '</div>';
        }
        return $h . '</div>';
    }

    private function statusLine(int $sid): string
    {
        [$k, $who, $p, $step] = Timeline::status($sid);
        $color = ['author' => '#b06000', 'journal' => '#006798', 'none' => '#1e7b4a'][$who];
        if ($k === 'declined') $color = '#b02a2a';
        return '<div style="font-size:15px;font-weight:700;color:' . $color . '">' . self::e($this->t('st.' . $k, $p)) . '</div>'
            . ($who !== 'none' ? '<div style="font-size:12px;color:#555">' . $this->t('waiting.' . $who) . '</div>' : '') . $this->stepper($step);
    }

    private function card($request, int $sid, bool $link = true): string
    {
        $s = DB::table('submissions')->where('submission_id', $sid)->first();
        $days = $s->date_submitted ? (int) floor((time() - strtotime($s->date_submitted)) / 86400) : 0;
        return $this->box(self::e($this->title($sid)), '<div style="font-size:12px;color:#666;margin-bottom:6px">#' . $sid . ' · ' . $this->t('since', ['d' => substr((string) $s->date_submitted, 0, 10), 'n' => $days]) . '</div>'
            . $this->statusLine($sid) . ($link ? $this->btn($this->url($request, 'view', ['sid' => $sid]), $this->t('details'), true) : ''));
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request);
        $ids = DB::table('submissions')->where('context_id', $cid)->whereNotNull('date_submitted')->whereIn('status', [1, 5])->orderByDesc('date_submitted')->limit(80)->pluck('submission_id')->all();
        $rows = [];
        foreach ($ids as $id) { [$k, $who, $p] = Timeline::status((int) $id);
            $rows[] = [(string) $id, '<b>' . self::e(mb_strimwidth($this->title((int) $id), 0, 90, '…')) . '</b>', self::e($this->t('st.' . $k, $p)), $this->t('waiting.' . $who), $this->btn($this->url($request, 'view', ['sid' => $id]), $this->t('preview'))]; }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->bench($request) . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.status'), $this->t('col.waiting'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    private function bench($request): string
    {
        $b = Timeline::benchmarks($this->cid($request)); $h = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">';
        foreach (['firstDecision', 'review', 'acceptToPub', 'total'] as $k) $h .= '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px;background:#fff;min-width:140px"><div style="font-size:20px;font-weight:700">' . ($b[$k] === null ? '—' : $this->t('days', ['n' => $b[$k]])) . '</div><div style="font-size:12px;color:#555">' . $this->t('b.' . $k) . '</div></div>';
        return $h . '</div>';
    }

    public function mine($args, $request)
    {
        $this->login($request); $cid = $this->cid($request);
        $ids = Timeline::authorSubs($cid, (int) $request->getUser()->getId());
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p>' . $this->bench($request);
        foreach ($ids as $id) $h .= $this->card($request, $id);
        if (!$ids) $h .= '<p>' . $this->t('none') . '</p>';
        $this->show($request, $this->t('myMenu'), $h);
    }

    public function view($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $sid = (int) $request->getUserVar('sid');
        $editor = $this->plugin->isEditor($request);
        if (!DB::table('submissions')->where('submission_id', $sid)->where('context_id', $cid)->exists()) $this->notFound();
        if (!$editor && !in_array($sid, Timeline::authorSubs($cid, (int) $request->getUser()->getId()), true)) $this->deny();
        $tl = '<div style="border-inline-start:3px solid #d5dbe1;padding-inline-start:14px;margin-inline-start:6px">';
        foreach (Timeline::events($sid) as [$d, $k, $p]) {
            $label = $this->t('ev.' . $k, $p);
            if (str_starts_with($label, '##')) continue;
            $tl .= '<div style="position:relative;margin:0 0 12px"><span style="position:absolute;inset-inline-start:-21px;top:4px;width:11px;height:11px;border-radius:50%;background:#006798"></span><div style="font-size:12px;color:#777" dir="ltr">' . self::e($d) . '</div><div>' . self::e($label) . '</div></div>';
        }
        $tl .= '</div>';
        $links = '';
        foreach (['officialLetters' => 'mine', 'authorResponse' => 'mine', 'proofApproval' => 'mine'] as $mod => $op)
            if (\APP\plugins\generic\tadabburSuite\classes\Modules::isActive($mod)) $links .= $this->btn($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $mod, $op), $this->t('link.' . $mod));
        $h = ($editor ? $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . '<p style="color:#8a5a00;font-size:12px">' . $this->t('editorPreview') . '</p>' : $this->btn($this->url($request, 'mine'), '← ' . $this->t('back')))
            . $this->card($request, $sid, false) . $this->box($this->t('timeline'), $tl) . $this->bench($request) . ($links ? $this->box($this->t('related'), $links) : '');
        $this->show($request, $this->t($editor ? 'menu' : 'myMenu'), $h);
    }
}
