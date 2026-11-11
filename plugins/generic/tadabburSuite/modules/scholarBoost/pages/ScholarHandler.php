<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/scholarBoost/pages/ScholarHandler.php
 * المحرر: index (الفحص)، tags (معاينة الوسوم) ؛ المدير POST: saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\scholarBoost\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\scholarBoost\classes\Tags;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\plugins\PluginRegistry;

class ScholarHandler extends ModuleHandler
{
    private const MAX_PDF = 5 * 1024 * 1024;

    private function siteChecks($request): array
    {
        $ctx = $request->getContext(); $d = $request->getDispatcher();
        $gs = PluginRegistry::getPlugin('generic', 'googlescholarplugin');
        $base = rtrim($request->getBaseUrl(), '/');
        $origin = preg_replace('~^(https?://[^/]+).*$~', '$1', $base);
        $out = ['core' => [$gs && $gs->getEnabled((int) $ctx->getId()), true, '']];
        $out['open'] = [(int) $ctx->getData('publishingMode') !== 1, true, ''];
        $out['restricted'] = [!$request->getSite()->getData('restrictSiteAccess') && !$ctx->getData('restrictSiteAccess'), true, ''];
        // robots.txt على أصل الموقع
        $st = 0;
        try {
            $res = \APP\core\Application::get()->getHttpClient()->request('GET', $origin . '/robots.txt', ['timeout' => 8, 'http_errors' => false]);
            $st = $res->getStatusCode(); $body = (string) $res->getBody();
        } catch (\Throwable $e) { $body = ''; }
        if ($st === 200) {
            $block = false; $agent = false;
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim(preg_replace('/#.*/', '', $line));
                if (preg_match('/^user-agent:\s*(.+)$/i', $line, $mm)) $agent = in_array(strtolower(trim($mm[1])), ['*', 'googlebot', 'googlebot-scholar'], true);
                elseif ($agent && preg_match('/^disallow:\s*(\S+)/i', $line, $mm)) { $p = $mm[1]; if ($p === '/' || str_contains($p, '/article') || str_contains($p, '/download') || str_contains($p, '/index.php')) $block = true; }
            }
            $out['robots'] = [!$block, true, $block ? $this->t('robotsBlocks') : $origin . '/robots.txt'];
        } else $out['robots'] = [true, false, $this->t('robotsNone')];
        $sm = $d->url($request, PKPApplication::ROUTE_PAGE, $ctx->getPath(), 'sitemap', null, null, [], urlLocaleForPage: '');
        $out['sitemap'] = [true, false, $sm];
        return $out;
    }

    /** مشكلات بحث منشور واحد وفق إرشادات Scholar */
    private function issues(array $m, array $titles): array
    {
        $lang = substr($m['locale'], 0, 2); $p = [];
        $pdfs = array_filter($m['galleys'], fn ($g) => $g['pdf']);
        if (!$pdfs) $p['noPdf'] = true;
        foreach ($pdfs as $g) {
            if ($g['remote'] !== '') $p['remotePdf'] = true;
            $size = 0;
            $path = DB::table('publication_galleys as g')->join('submission_files as sf', 'sf.submission_file_id', '=', 'g.submission_file_id')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('g.galley_id', $g['id'])->value('f.path');
            if ($path) { try { $size = (int) app()->get('file')->fs->fileSize($path); } catch (\Throwable $e) {} }
            if ($size > self::MAX_PDF) $p['bigPdf'] = round($size / 1048576, 1) . ' MB';
        }
        if (array_filter($m['authors'], fn ($a) => empty($a['names'][$lang]))) $p['authorLocale'] = true;
        if (!$m['authors']) $p['noAuthors'] = true;
        if (empty($m['abstracts'][$lang])) $p['abstractLocale'] = true;
        if (empty($m['titles'][$lang])) $p['titleLocale'] = true;
        if ($m['firstPage'] === '') $p['noPages'] = true;
        if (!$m['citations']) $p['noRefs'] = true;
        if ($m['doi'] === '') $p['noDoi'] = true;
        $t = mb_strtolower(trim(Meta::pick($m['titles'])));
        if (($titles[$t] ?? 0) > 1) $p['dupTitle'] = true;
        return $p;
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $ctx = $request->getContext();
        $site = '';
        foreach ($this->siteChecks($request) as $k => [$ok, $must, $detail])
            $site .= '<tr><td style="' . self::S_TD . '">' . $this->t('site.' . $k) . '</td><td style="' . self::S_TD . 'font-weight:700;color:' . ($ok ? '#1e7b4a' : ($must ? '#b02a2a' : '#b07a00')) . '">' . ($ok ? '✔' : ($must ? '✖' : '!')) . '</td><td style="' . self::S_TD . 'font-size:12px" dir="auto">' . (str_starts_with($detail, 'http') ? '<a target="_blank" href="' . self::e($detail) . '">' . self::e($detail) . '</a>' : self::e($detail)) . '</td></tr>';
        $ids = DB::table('submissions')->where('context_id', $cid)->where('status', 3)->orderByDesc('submission_id')->limit(300)->pluck('submission_id')->all();
        $metas = [];
        foreach ($ids as $id) if ($s = Repo::submission()->get((int) $id)) $metas[] = Meta::snapshot($s, $ctx, $request);
        $titles = [];
        foreach ($metas as $m) { $t = mb_strtolower(trim(Meta::pick($m['titles']))); $titles[$t] = ($titles[$t] ?? 0) + 1; }
        $rows = []; $clean = 0; $counts = [];
        foreach ($metas as $m) {
            $p = $this->issues($m, $titles);
            if (!$p) { $clean++; continue; }
            foreach ($p as $k => $v) $counts[$k] = ($counts[$k] ?? 0) + 1;
            $tags = '';
            foreach ($p as $k => $v) $tags .= '<span style="display:inline-block;margin:1px;padding:0 6px;border-radius:8px;font-size:11px;' . (in_array($k, ['noPdf', 'bigPdf', 'authorLocale', 'titleLocale', 'noAuthors', 'dupTitle'], true) ? 'background:#fbe9e9;color:#b02a2a' : 'background:#fff4dc;color:#8a5a00') . '">' . $this->t('p.' . $k) . (is_string($v) ? ' (' . self::e($v) . ')' : '') . '</span>';
            $rows[] = [(string) $m['id'], '<a target="_blank" href="' . self::e($m['url']) . '">' . self::e(mb_strimwidth(Meta::pick($m['titles']), 0, 90, '…')) . '</a>', $tags, $this->btn($this->url($request, 'tags', ['sid' => $m['id']]), $this->t('viewTags'))];
        }
        $sum = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"><div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px"><b style="font-size:22px">' . $clean . '/' . count($metas) . '</b><div style="font-size:12px">' . $this->t('cleanCount') . '</div></div>';
        foreach ($counts as $k => $n) $sum .= '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:8px 12px"><b style="font-size:22px">' . $n . '</b><div style="font-size:12px">' . $this->t('p.' . $k) . '</div></div>';
        $sum .= '</div>';
        $o = $this->plugin->opts($cid);
        $set = $this->plugin->isManager($request) ? $this->box($this->t('settings'), '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">' . $this->csrf($request)
            . '<label style="display:block"><input type="checkbox" name="tags" value="1"' . ($o['tags'] ? ' checked' : '') . '> ' . $this->t('set.tags') . '</label>'
            . '<label style="display:block"><input type="checkbox" name="jsonld" value="1"' . ($o['jsonld'] ? ' checked' : '') . '> ' . $this->t('set.jsonld') . '</label><button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>') : '';
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request)
            . $this->box($this->t('siteTitle'), '<table style="' . self::S_TBL . '"><tbody>' . $site . '</tbody></table>')
            . $this->box($this->t('articlesTitle'), $sum . $this->table(['#', $this->t('col.title'), $this->t('col.problems'), ''], $rows, $this->t('allClean')))
            . $this->box($this->t('guideTitle'), '<ul style="margin:0;padding-inline-start:18px;font-size:13px">' . $this->t('guide') . '</ul>') . $set;
        $this->show($request, $this->t('menu'), $h);
    }

    public function tags($args, $request)
    {
        $this->editor($request);
        $s = Repo::submission()->get((int) $request->getUserVar('sid'));
        if (!$s || (int) $s->getData('contextId') !== $this->cid($request)) $this->notFound();
        $hs = Tags::headers($s, $request->getContext(), $request, ['tags' => true, 'jsonld' => true]);
        $json = isset($hs['JsonLd']) ? json_encode(json_decode(strip_tags($hs['JsonLd']), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        unset($hs['JsonLd']);
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->box($this->t('extraTags'), '<pre dir="ltr" style="white-space:pre-wrap;font-size:12px;background:#f6f8fa;padding:10px;border-radius:6px">' . self::e(implode("\n", $hs)) . '</pre>')
            . $this->box('JSON-LD (schema.org ScholarlyArticle)', '<pre dir="ltr" style="white-space:pre-wrap;font-size:12px;background:#f6f8fa;padding:10px;border-radius:6px">' . self::e($json) . '</pre>'
            . '<a target="_blank" href="https://search.google.com/test/rich-results?url=' . rawurlencode(Meta::snapshot($s, $request->getContext(), $request)['url']) . '">' . $this->t('richTest') . '</a>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveSettings($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request);
        $this->plugin->updateSetting($cid, 'tags', $request->getUserVar('tags') ? '1' : '0', 'string');
        $this->plugin->updateSetting($cid, 'jsonld', $request->getUserVar('jsonld') ? '1' : '0', 'string');
        $this->back($request, 'index');
    }
}
