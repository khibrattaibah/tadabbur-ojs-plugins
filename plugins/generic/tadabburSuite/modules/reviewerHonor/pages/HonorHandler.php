<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerHonor/pages/HonorHandler.php
 * المحرر: index، letter ؛ POST: mark، saveText ؛ المحكّم: mine، letter، POST consent ؛ عامة: thanks
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerHonor\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\reviewerHonor\classes\Honor;
use APP\plugins\generic\tadabburSuite\classes\Branding;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use PKP\facades\Locale;

class HonorHandler extends ModuleHandler
{
    private function year($request): int { $y = (int) $request->getUserVar('year'); return $y >= 2000 && $y <= 2100 ? $y : (int) date('Y'); }
    private function user(int $uid) { return Repo::user()->get($uid, true); }
    private function aff($u): string { return $u ? strip_tags((string) $u->getLocalizedData('affiliation')) : ''; }

    private function letterText(int $cid): string
    {
        $t = (string) $this->plugin->getSetting($cid, 'letterText');
        return $t !== '' ? $t : $this->t('defaultLetter');
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $y = $this->year($request); $mgr = $this->plugin->isManager($request);
        $stats = Honor::stats($cid, $y); $marks = Honor::marks($cid, $y); $rows = [];
        foreach ($stats as $i => $s) {
            $u = $this->user($s['uid']); $mk = $marks[$s['uid']] ?? null; $award = $mk->award ?? '';
            $ctl = '';
            if ($mgr) $ctl = '<form method="post" action="' . self::e($this->url($request, 'mark')) . '" style="display:flex;gap:4px;align-items:center;margin:0">' . $this->csrf($request)
                . '<input type="hidden" name="uid" value="' . $s['uid'] . '"><input type="hidden" name="year" value="' . $y . '">'
                . '<label style="font-size:11px"><input type="checkbox" name="public" value="1"' . ($mk && $mk->public ? ' checked' : '') . '> ' . $this->t('public') . '</label>'
                . '<select name="award" style="' . self::S_INP . 'min-height:24px;font-size:12px"><option value="">—</option>' . implode('', array_map(fn ($a) => '<option value="' . $a . '"' . ($award === $a ? ' selected' : '') . '>' . $this->t('award.' . $a) . '</option>', ['year', 'distinguished', 'fastest'])) . '</select>'
                . '<button style="' . self::S_BTN . '">' . $this->t('save') . '</button></form>';
            $rows[] = [(string) ($i + 1), '<b>' . self::e($u ? $u->getFullName() : '#' . $s['uid']) . '</b>' . ($award ? ' 🏅 <span style="font-size:11px;color:#8a5a00">' . $this->t('award.' . $award) . '</span>' : '') . '<div style="font-size:11px;color:#777">' . self::e($this->aff($u)) . '</div>',
                (string) $s['done'], (string) $s['declined'], $s['avgDays'] === null ? '—' : (string) $s['avgDays'], $s['onTime'] . '%', $s['quality'] === null ? '—' : (string) $s['quality'], '<b>' . $s['score'] . '</b>',
                $ctl . $this->btn($this->url($request, 'letter', ['uid' => $s['uid'], 'year' => $y]), '🖨 ' . $this->t('letter'), false, ' target="_blank"')];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request)
            . '<form method="get" action="' . self::e($this->url($request, 'index')) . '" style="margin-bottom:10px">' . $this->t('year') . ' <input type="number" name="year" value="' . $y . '" style="' . self::S_INP . 'width:90px"> <button style="' . self::S_BTN . '">' . $this->t('show') . '</button> '
            . $this->btn($this->url($request, 'thanks', ['year' => $y]), $this->t('publicPage'), false, ' target="_blank"') . '</form>'
            . $this->box($this->t('ranking', ['y' => $y]), $this->table(['#', $this->t('col.name'), $this->t('col.done'), $this->t('col.declined'), $this->t('col.days'), $this->t('col.onTime'), $this->t('col.quality'), $this->t('col.score'), ''], $rows)
                . '<p style="font-size:11px;color:#777;margin:6px 0 0">' . $this->t('scoreNote') . '</p>');
        if ($mgr) $h .= $this->box($this->t('letterTitle'), '<form method="post" action="' . self::e($this->url($request, 'saveText')) . '">' . $this->csrf($request)
            . '<textarea name="text" rows="6" style="' . self::S_INP . 'width:100%">' . self::e($this->letterText($this->cid($request))) . '</textarea><div style="font-size:12px;color:#666">' . $this->t('placeholders') . '</div>'
            . '<label style="display:block;margin:6px 0"><input type="checkbox" name="self" value="1"' . ($this->plugin->getSetting($this->cid($request), 'selfService') !== '0' ? ' checked' : '') . '> ' . $this->t('selfService') . '</label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function mark($args, $request)
    {
        $this->manager($request); $y = $this->year($request);
        $award = in_array($this->v($request, 'award'), ['year', 'distinguished', 'fastest'], true) ? $this->v($request, 'award') : '';
        Honor::set($this->cid($request), $y, (int) $request->getUserVar('uid'), ['public' => $request->getUserVar('public') ? 1 : 0, 'award' => $award]);
        $this->back($request, 'index', ['year' => $y]);
    }

    public function saveText($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request);
        $this->plugin->updateSetting($cid, 'letterText', mb_substr(trim((string) $request->getUserVar('text')), 0, 3000), 'string');
        $this->plugin->updateSetting($cid, 'selfService', $request->getUserVar('self') ? '1' : '0', 'string');
        $this->back($request, 'index');
    }

    public function mine($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $uid = (int) $request->getUser()->getId();
        $self = $this->plugin->getSetting($cid, 'selfService') !== '0'; $rows = [];
        foreach (Honor::years($cid, $uid) as $y) {
            $s = array_values(array_filter(Honor::stats($cid, $y), fn ($x) => $x['uid'] === $uid))[0] ?? null; if (!$s) continue;
            $mk = Honor::marks($cid, $y)[$uid] ?? null;
            $rows[] = [(string) $y, (string) $s['done'], $mk && $mk->award ? '🏅 ' . $this->t('award.' . $mk->award) : '—',
                $this->postBtn($request, 'consent', ['year' => $y, 'v' => $mk && $mk->public ? 0 : 1], $mk && $mk->public ? '✔ ' . $this->t('listedYes') : $this->t('listedAsk')),
                $self ? $this->btn($this->url($request, 'letter', ['uid' => $uid, 'year' => $y]), '🖨 ' . $this->t('letter'), true, ' target="_blank"') : ''];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p>' . $this->flash($request) . $this->box('', $this->table([$this->t('year'), $this->t('col.done'), $this->t('col.award'), $this->t('col.public'), ''], $rows));
        $this->show($request, $this->t('myMenu'), $h);
    }

    public function consent($args, $request)
    {
        $this->login($request); $this->postOk($request); $cid = $this->cid($request); $uid = (int) $request->getUser()->getId(); $y = $this->year($request);
        if (!in_array($y, Honor::years($cid, $uid), true)) $this->deny();
        $mk = Honor::marks($cid, $y)[$uid] ?? null;
        Honor::set($cid, $y, $uid, ['public' => (int) (bool) $request->getUserVar('v'), 'award' => $mk->award ?? '']);
        $this->back($request, 'mine');
    }

    public function thanks($args, $request)
    {
        $cid = $this->cid($request); $y = $this->year($request); $marks = Honor::marks($cid, $y);
        $list = ''; $awards = '';
        foreach (Honor::stats($cid, $y) as $s) {
            $mk = $marks[$s['uid']] ?? null; if (!$mk || !$mk->public) continue;
            $u = $this->user($s['uid']); if (!$u) continue;
            $item = '<b>' . self::e($u->getFullName()) . '</b>' . ($this->aff($u) ? ' <span style="color:#666">— ' . self::e($this->aff($u)) . '</span>' : '');
            if ($mk->award) $awards .= '<li style="margin:6px 0">🏅 ' . $item . ' <span style="color:#8a5a00">(' . $this->t('award.' . $mk->award) . ')</span></li>';
            $list .= '<li style="margin:4px 0">' . $item . '</li>';
        }
        $h = '<p>' . $this->t('thanksIntro', ['y' => $y]) . '</p>' . ($awards ? '<h2>' . $this->t('awards') . '</h2><ul style="list-style:none;padding:0">' . $awards . '</ul>' : '')
            . '<h2>' . $this->t('reviewersOf', ['y' => $y]) . '</h2>' . ($list ? '<ul style="columns:2;padding-inline-start:18px">' . $list . '</ul>' : '<p>' . $this->t('none') . '</p>');
        $this->showPublic($request, $this->t('thanksTitle', ['y' => $y]), $h);
    }

    private static function img(string $file): string
    {
        $p = Branding::path($file, Modules::root() . '/officialLetters/assets/' . $file);
        if (!is_file($p)) return '';
        return 'data:' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($p));
    }

    public function letter($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $uid = (int) $request->getUserVar('uid'); $y = $this->year($request);
        $me = (int) $request->getUser()->getId() === $uid;
        if (!$this->plugin->isEditor($request) && !($me && $this->plugin->getSetting($cid, 'selfService') !== '0')) $this->deny();
        $s = array_values(array_filter(Honor::stats($cid, $y), fn ($x) => $x['uid'] === $uid))[0] ?? null; if (!$s) $this->notFound();
        $u = $this->user($uid); $j = Meta::journal($request->getContext()); $mk = Honor::marks($cid, $y)[$uid] ?? null;
        $hijri = '';
        if (class_exists(\IntlDateFormatter::class)) $hijri = (string) (new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL))->format(time());
        $body = strtr($this->letterText($cid), ['{name}' => $u ? $u->getFullName() : '', '{count}' => (string) $s['done'], '{year}' => (string) $y, '{journal}' => Meta::pick($j['names'])]);
        $bg = self::img('letterhead.jpg'); $sig = self::img('signature.png'); $stamp = self::img('stamp.png'); $nm = self::img('name_editor.png');
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>' . self::e($this->t('letter')) . '</title><style>@page{size:A4;margin:0}body{margin:0;font-family:"IBM Plex Sans Arabic","Traditional Arabic",Tahoma,sans-serif;background:#888}'
            . '.pg{width:210mm;height:297mm;margin:0 auto;position:relative;background:#fff ' . ($bg ? 'url(' . $bg . ') center/100% 100% no-repeat' : '') . ';box-sizing:border-box;padding:58mm 22mm 40mm}'
            . '.d{font-size:12.5pt;color:#333;display:flex;justify-content:space-between}.t{text-align:center;font-size:20pt;font-weight:700;margin:14mm 0 8mm;color:#5b3f1d}.b{font-size:14pt;line-height:2;text-align:justify;white-space:pre-line}'
            . '.aw{text-align:center;font-size:13pt;color:#8a5a00;font-weight:700;margin-top:6mm}.sg{position:absolute;bottom:42mm;left:22mm;width:80mm;text-align:center}.sg img{display:block;margin:0 auto}.np{position:fixed;top:8px;left:8px}@media print{.np{display:none}body{background:#fff}}</style></head><body>'
            . '<button class="np" onclick="window.print()">🖨</button><div class="pg"><div class="d"><span>' . self::e($hijri) . '</span><span dir="ltr">' . date('Y-m-d') . '</span></div>'
            . '<div class="t">' . self::e($this->t('letterHeading')) . '</div><div class="b">' . self::e($body) . '</div>'
            . ($mk && $mk->award ? '<div class="aw">🏅 ' . self::e($this->t('award.' . $mk->award) . ' ' . $y) . '</div>' : '')
            . '<div class="sg">' . ($nm ? '<img src="' . $nm . '" style="max-width:75mm;max-height:16mm">' : '') . ($sig ? '<img src="' . $sig . '" style="max-width:45mm;max-height:22mm">' : '') . ($stamp ? '<img src="' . $stamp . '" style="max-width:30mm;max-height:30mm;margin-top:-18mm;opacity:.9">' : '') . '</div></div></body></html>';
        exit;
    }
}
