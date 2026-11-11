<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/articleImpact/pages/ImpactHandler.php
 * /articleImpact (اللوحة)، /articleImpact/csv، و POST: refresh، saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\articleImpact\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\articleImpact\ArticleImpactPlugin;
use APP\plugins\generic\tadabburSuite\m\articleImpact\classes\Impact;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class ImpactHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';

    public function __construct(private ArticleImpactPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.articleImpact.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, ArticleImpactPlugin::PAGE, $op, null, $params);
    }
    private function gate($request, bool $manager = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!($manager ? $this->plugin->isManager($request) : $this->plugin->canView($request))) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }
    private function csrfOk($request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; }
    }

    /** @return array<int, array{id:int, title:string, doi:string, date:string, v:int, d:int, c:?int, cr:?int, oa:?int, fetched:?string, err:string, url:string}> */
    private function rows($request): array
    {
        $context = $request->getContext(); $cid = (int) $context->getId();
        $usage = Impact::usageAll($cid);
        $imp = $this->plugin->impact($context)->all($cid);
        $out = [];
        $subs = Repo::submission()->getCollector()->filterByContextIds([$cid])->filterByStatus([Submission::STATUS_PUBLISHED])->getMany();
        foreach ($subs as $s) {
            $p = $s->getCurrentPublication(); if (!$p) continue;
            $id = (int) $s->getId(); $r = $imp[$id] ?? null;
            $out[] = ['id' => $id, 'title' => strip_tags((string) $p->getLocalizedFullTitle()), 'doi' => (string) $p->getDoi(), 'date' => substr((string) $p->getData('datePublished'), 0, 10),
                'v' => (int) ($usage[$id]['v'] ?? 0), 'd' => (int) ($usage[$id]['d'] ?? 0), 'c' => Impact::best($r), 'cr' => $r?->crossref_count, 'oa' => $r?->openalex_count,
                'fetched' => $r?->fetched_at, 'err' => (string) ($r?->error ?? ''),
                'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$s->getBestId()])];
        }
        return $out;
    }

    public function index($args, $request)
    {
        $this->gate($request);
        $context = $request->getContext(); $cid = (int) $context->getId(); $cfg = $this->plugin->cfg($cid);
        $rows = $this->rows($request);
        $sort = in_array($request->getUserVar('sort'), ['v', 'd', 'c', 'date'], true) ? $request->getUserVar('sort') : 'c';
        usort($rows, fn ($a, $b) => $sort === 'date' ? strcmp($b['date'], $a['date']) : (($b[$sort] ?? -1) <=> ($a[$sort] ?? -1)));
        $sum = fn ($k) => array_sum(array_map(fn ($r) => (int) ($r[$k] ?? 0), $rows));
        $withC = count(array_filter($rows, fn ($r) => ($r['c'] ?? 0) > 0));
        $fetched = array_filter(array_column($rows, 'fetched'));
        $card = fn ($v, $l, $col, $sub = '') => '<div style="flex:1;min-width:160px;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:26px;font-weight:700;color:' . $col . '">' . $v . '</div><div style="font-size:13px">' . $l . '</div>' . ($sub ? '<div style="font-size:11px;color:#666">' . $sub . '</div>' : '') . '</div>';
        $h = '';
        foreach (['done' => 'refreshed', 'saved' => 'saved'] as $q => $k) if ($request->getUserVar($q) !== null) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t($k, ['n' => (int) $request->getUserVar($q)]) . '</b></div>';
        $h .= '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p><div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">'
            . $card(count($rows), $this->t('stat.articles'), '#0b4f4a') . $card(number_format($sum('v')), $this->t('views'), '#33658a') . $card(number_format($sum('d')), $this->t('downloads'), '#33658a')
            . $card(number_format($sum('c')), $this->t('stat.citations'), '#8a6d3b', $this->t('stat.cited', ['n' => $withC])) . '</div>';

        // ---- التحديث
        $h .= '<div style="' . self::S_BOX . 'display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:center"><div><b>' . $this->t('refreshTitle') . '</b><div style="font-size:12px;color:#555">'
            . $this->t('lastRefresh', ['d' => $fetched ? substr((string) max($fetched), 0, 16) : '—']) . ' · ' . $this->t('refreshHelp') . '</div></div>';
        if ($this->plugin->isManager($request)) {
            $h .= '<form method="post" action="' . self::e($this->url($request, 'refresh')) . '" style="margin:0"><input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '"><button style="' . self::S_BTNP . '">⟳ ' . $this->t('refreshBtn') . '</button></form>';
        }
        $h .= '</div>';

        // ---- الجدول
        $sl = fn ($k, $label) => '<a href="' . self::e($this->url($request, 'index', ['sort' => $k])) . '" style="color:inherit;' . ($sort === $k ? 'text-decoration:underline' : 'text-decoration:none') . '">' . $label . ($sort === $k ? ' ▼' : '') . '</a>';
        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px"><h2 style="margin:0;font-size:17px">' . $this->t('ranking') . '</h2><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'csv')) . '">CSV</a></div>';
        if (!$rows) $h .= '<p>' . $this->t('none') . '</p>';
        else {
            $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:40px">#</th><th style="' . self::S_TH . '">' . $this->t('col.article') . '</th><th style="' . self::S_TH . 'width:95px">' . $sl('date', $this->t('col.published')) . '</th>'
                . '<th style="' . self::S_TH . 'width:90px">' . $sl('v', $this->t('views')) . '</th><th style="' . self::S_TH . 'width:90px">' . $sl('d', $this->t('downloads')) . '</th><th style="' . self::S_TH . 'width:120px">' . $sl('c', $this->t('citations')) . '</th></tr></thead><tbody>';
            foreach ($rows as $i => $r) {
                $cit = $r['c'] === null ? '<span style="color:#999" title="' . self::e($r['err'] !== '' ? $this->t('err.' . $r['err']) : $this->t('notFetched')) . '">—' . ($r['err'] !== '' ? ' ⓘ' : '') . '</span>'
                    : '<b>' . $r['c'] . '</b><div style="font-size:11px;color:#666">Crossref ' . ($r['cr'] ?? '—') . ' · OpenAlex ' . ($r['oa'] ?? '—') . '</div>';
                $h .= '<tr><td style="' . self::S_TD . '">' . ($i + 1) . '</td><td style="' . self::S_TD . '"><a href="' . self::e($r['url']) . '" target="_blank">' . self::e($r['title']) . '</a>'
                    . ($r['doi'] ? '<div dir="ltr" style="font-size:11px;color:#666;text-align:start">' . self::e($r['doi']) . '</div>' : '<div style="font-size:11px;color:#b02a2a">' . $this->t('noDoi') . '</div>') . '</td>'
                    . '<td style="' . self::S_TD . '">' . self::e($r['date']) . '</td><td style="' . self::S_TD . '">' . number_format($r['v']) . '</td><td style="' . self::S_TD . '">' . number_format($r['d']) . '</td><td style="' . self::S_TD . '">' . $cit . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '</div>';

        // ---- الإعدادات
        if ($this->plugin->isManager($request)) {
            $cb = fn ($n, $l) => '<label style="display:block;margin:5px 0"><input type="checkbox" name="' . $n . '" value="1"' . ($cfg[$n] ? ' checked' : '') . '> ' . $l . '</label>';
            $h .= '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary><form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:8px">'
                . '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'
                . $cb('showBlock', $this->t('set.showBlock')) . $cb('showChart', $this->t('set.showChart')) . $cb('showCitedBy', $this->t('set.showCitedBy'))
                . $cb('altmetric', $this->t('set.altmetric')) . $cb('dimensions', $this->t('set.dimensions')) . $cb('refreshOnView', $this->t('set.refreshOnView'))
                . '<label style="display:block;margin:6px 0">' . $this->t('set.staleDays') . ' <input type="number" min="1" max="365" name="staleDays" value="' . (int) $cfg['staleDays'] . '" style="' . self::S_INP . 'width:80px"></label>'
                . '<label style="display:block;margin:6px 0">' . $this->t('set.openalexKey') . '<br><input name="openalexKey" value="' . self::e($cfg['openalexKey']) . '" dir="ltr" autocomplete="off" style="' . self::S_INP . 'width:340px"><div style="font-size:12px;color:#555">' . $this->t('set.openalexKeyHelp') . '</div></label>'
                . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></details>';
        }
        $csl = DB::table('plugin_settings')->where('plugin_name', 'citationstylelanguageplugin')->where('context_id', $cid)->where('setting_name', 'enabled')->where('setting_value', '1')->exists();
        $h .= '<div style="font-size:12px;color:#555">' . $this->t($csl ? 'cslOn' : 'cslOff') . ' ' . $this->t('countNote') . '</div>';

        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $this->t('menu'), 'pluginHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    /** يحدّث دفعة: الأقدم تحديثًا أولًا، حتى 40 بحثًا أو 50 ثانية */
    public function refresh($args, $request)
    {
        $this->gate($request, true); $this->csrfOk($request);
        @set_time_limit(120);
        $context = $request->getContext(); $cid = (int) $context->getId();
        $imp = $this->plugin->impact($context);
        $rows = $this->rows($request);
        usort($rows, fn ($a, $b) => strcmp((string) ($a['fetched'] ?? ''), (string) ($b['fetched'] ?? '')));
        $n = 0; $t0 = time();
        foreach ($rows as $r) {
            if ($n >= 40 || time() - $t0 > 50) break;
            $imp->refresh($cid, $r['id'], $r['doi']);
            $n++;
            if ($r['doi'] !== '') usleep(150000); // تهذيب الطلبات
        }
        $request->redirectUrl($this->url($request, 'index', ['done' => $n]));
    }

    public function saveSettings($args, $request)
    {
        $this->gate($request, true); $this->csrfOk($request);
        $cid = (int) $request->getContext()->getId();
        foreach (['showBlock', 'showChart', 'showCitedBy', 'altmetric', 'dimensions', 'refreshOnView'] as $k) $this->plugin->updateSetting($cid, $k, (bool) $request->getUserVar($k), 'bool');
        $this->plugin->updateSetting($cid, 'staleDays', max(1, min(365, (int) $request->getUserVar('staleDays'))), 'int');
        $this->plugin->updateSetting($cid, 'openalexKey', preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $request->getUserVar('openalexKey')), 'string');
        $request->redirectUrl($this->url($request, 'index', ['saved' => 1]));
    }

    public function csv($args, $request)
    {
        $this->gate($request);
        $rows = $this->rows($request);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="article-impact-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Title', 'DOI', 'Published', 'Abstract views', 'Downloads', 'Citations (max)', 'Crossref', 'OpenAlex', 'Fetched']);
        foreach ($rows as $r) fputcsv($out, [$r['id'], $r['title'], $r['doi'], $r['date'], $r['v'], $r['d'], $r['c'], $r['cr'], $r['oa'], $r['fetched']]);
        fclose($out); exit;
    }
}
