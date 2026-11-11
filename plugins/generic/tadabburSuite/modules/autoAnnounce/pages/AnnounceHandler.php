<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/autoAnnounce/pages/AnnounceHandler.php
 * المحرر: index، social ؛ POST: announceIssue، announceArticle ؛ المدير POST: saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\autoAnnounce\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\autoAnnounce\classes\Announcer;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\facades\Locale;

class AnnounceHandler extends ModuleHandler
{
    private function tags(int $cid): string { return (string) $this->plugin->cfg($cid, 'tags', '#مجلة_تدبر #الدراسات_القرآنية #تدبر_القرآن'); }

    private function annLink($request, ?object $log): string
    {
        if (!$log) return '<span style="color:#888">—</span>';
        $u = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'announcement', 'view', [$log->announcement_id]);
        return $this->badge(true, '<a href="' . self::e($u) . '" target="_blank">' . $this->t('announced') . '</a>') . ' <span style="font-size:11px;color:#777" dir="ltr">' . self::e(substr($log->created_at, 0, 10)) . '</span>';
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $ctx = $request->getContext(); $mgr = $this->plugin->isManager($request);
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request);
        if (!$ctx->getData('enableAnnouncements')) $h .= '<div style="' . self::S_BOX . 'border-color:#b07a00;background:#fff8e6">⚠ ' . $this->t('annOff') . '</div>';
        $rows = [];
        foreach (Repo::issue()->getCollector()->filterByContextIds([$cid])->filterByPublished(true)->orderBy(\APP\issue\Collector::ORDERBY_PUBLISHED_ISSUES)->limit(8)->getMany() as $is) {
            $log = Announcer::done($cid, 'issue', (int) $is->getId());
            $rows[] = ['<b>' . self::e($this->t('issueLabel', ['v' => $is->getVolume(), 'n' => $is->getNumber(), 'y' => $is->getYear()])) . '</b>', (string) count(Announcer::articleIds($is)), $this->annLink($request, $log),
                $this->postBtn($request, 'announceIssue', ['id' => $is->getId()], $log ? $this->t('again') : $this->t('announce'), !$log, $log ? $this->t('confirmAgain') : '') . $this->btn($this->url($request, 'social', ['issue' => $is->getId()]), $this->t('social'))];
        }
        $h .= $this->box($this->t('issues'), $this->table([$this->t('col.issue'), $this->t('col.papers'), $this->t('col.ann'), ''], $rows));
        $ids = DB::table('submissions as s')->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')->where('s.context_id', $cid)->where('s.status', 3)->orderByDesc('p.date_published')->limit(20)->pluck('s.submission_id')->all();
        $rows = [];
        foreach ($ids as $id) {
            $s = Repo::submission()->get((int) $id); if (!$s) continue; $log = Announcer::done($cid, 'article', (int) $id);
            $rows[] = [(string) $id, '<b>' . self::e(mb_strimwidth(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()), 0, 90, '…')) . '</b>', $this->annLink($request, $log),
                $this->btn($this->url($request, 'social', ['sid' => $id]), '📣 ' . $this->t('social'), true) . $this->postBtn($request, 'announceArticle', ['sid' => $id], $log ? $this->t('again') : $this->t('announce'), false, $log ? $this->t('confirmAgain') : '')];
        }
        $h .= $this->box($this->t('articles'), $this->table(['#', $this->t('col.title'), $this->t('col.ann'), ''], $rows));
        if ($mgr) $h .= $this->box($this->t('settings'), '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">' . $this->csrf($request)
            . '<label style="display:block"><input type="checkbox" name="autoIssue" value="1"' . ((string) $this->plugin->cfg($cid, 'autoIssue', '1') === '1' ? ' checked' : '') . '> ' . $this->t('set.autoIssue') . '</label>'
            . '<label style="display:block"><input type="checkbox" name="autoArticle" value="1"' . ((string) $this->plugin->cfg($cid, 'autoArticle', '0') === '1' ? ' checked' : '') . '> ' . $this->t('set.autoArticle') . '</label>'
            . '<label style="display:block;font-size:12px;margin-top:6px">' . $this->t('set.tags') . '<br><input name="tags" value="' . self::e($this->tags($cid)) . '" style="' . self::S_INP . 'width:100%"></label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveSettings($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request);
        $this->plugin->updateSetting($cid, 'autoIssue', $request->getUserVar('autoIssue') ? '1' : '0', 'string');
        $this->plugin->updateSetting($cid, 'autoArticle', $request->getUserVar('autoArticle') ? '1' : '0', 'string');
        $this->plugin->updateSetting($cid, 'tags', mb_substr($this->v($request, 'tags'), 0, 300), 'string');
        $this->back($request, 'index');
    }

    public function announceIssue($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        $is = Repo::issue()->get((int) $request->getUserVar('id'));
        if (!$is || (int) $is->getJournalId() !== $this->cid($request) || !$is->getPublished()) $this->notFound();
        (new Announcer())->issue($this->plugin, $request->getContext(), $request, $is, true);
        $this->back($request, 'index', [], 'done');
    }

    public function announceArticle($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        $s = Repo::submission()->get((int) $request->getUserVar('sid'));
        if (!$s || (int) $s->getData('contextId') !== $this->cid($request) || (int) $s->getData('status') !== 3) $this->notFound();
        (new Announcer())->article($this->plugin, $request->getContext(), $request, $s, true);
        $this->back($request, 'index', [], 'done');
    }

    public function social($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $ctx = $request->getContext(); $j = Meta::journal($ctx); $tags = $this->tags($cid);
        $ar = str_starts_with((string) Locale::getLocale(), 'ar'); $texts = [];
        if ($sid = (int) $request->getUserVar('sid')) {
            $s = Repo::submission()->get($sid); if (!$s || (int) $s->getData('contextId') !== $cid) $this->notFound();
            $m = Meta::snapshot($s, $ctx, $request); $texts = Announcer::social($m, $j, $tags, $ar); $title = Meta::pick($m['titles']);
            if (!empty($m['titles']['en'])) $texts['x_en'] = Announcer::social($m, $j, '', false)['x'];
        } else {
            $is = Repo::issue()->get((int) $request->getUserVar('issue')); if (!$is || (int) $is->getJournalId() !== $cid) $this->notFound();
            $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $ctx->getPath(), 'issue', 'view', [$is->getBestIssueId()]);
            $titles = array_map(fn ($id) => ($x = Repo::submission()->get($id)) ? '▪️ ' . strip_tags((string) $x->getCurrentPublication()->getLocalizedTitle()) : '', Announcer::articleIds($is));
            $title = $this->t('issueLabel', ['v' => $is->getVolume(), 'n' => $is->getNumber(), 'y' => $is->getYear()]);
            $head = '📘 ' . $this->t('tpl.issueTitle', ['v' => $is->getVolume(), 'n' => $is->getNumber(), 'y' => $is->getYear(), 'j' => Meta::pick($j['names'])]);
            $texts = ['x' => $head . "\n" . $url . "\n" . $tags, 'linkedin' => $head . "\n\n" . implode("\n", array_filter($titles)) . "\n\n" . $url . "\n\n" . $tags, 'whatsapp' => '*' . $head . "*\n" . implode("\n", array_filter($titles)) . "\n" . $url];
        }
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back'));
        foreach ($texts as $k => $txt) {
            $id = 'tx' . $k; $len = mb_strlen($txt);
            $h .= $this->box($this->t('net.' . $k) . ' <span style="font-size:12px;color:' . (str_starts_with($k, 'x') && $len > 280 ? '#b02a2a' : '#777') . '">(' . $len . ')</span>',
                '<textarea id="' . $id . '" rows="' . min(14, 3 + substr_count($txt, "\n")) . '" style="' . self::S_INP . 'width:100%" dir="auto">' . self::e($txt) . '</textarea>'
                . '<button type="button" style="' . self::S_BTNP . '" onclick="var t=document.getElementById(\'' . $id . '\');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent=\'✔\'">' . $this->t('copy') . '</button>'
                . (str_starts_with($k, 'x') ? ' <a target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=' . rawurlencode($txt) . '">' . $this->t('openX') . '</a>' : ''));
        }
        $this->show($request, $this->t('menu') . ' — ' . $title, $h);
    }
}
