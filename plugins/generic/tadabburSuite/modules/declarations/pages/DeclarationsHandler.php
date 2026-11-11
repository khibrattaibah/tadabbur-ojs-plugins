<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/declarations/pages/DeclarationsHandler.php
 * /declarations (السجل والإعدادات)، /declarations/csv، /declarations/saveSettings (POST)
 */
namespace APP\plugins\generic\tadabburSuite\m\declarations\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\declarations\DeclarationsPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class DeclarationsHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';

    public function __construct(private DeclarationsPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.declarations.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, DeclarationsPlugin::PAGE, $op, null, $params);
    }
    private function gate($request, bool $manager = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!($manager ? $this->plugin->isManager($request) : $this->plugin->canView($request))) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }

    /** @return array<int, array{sub:Submission, pub:object, st:array, done:int, total:int}> */
    private function rows($request, string $scope): array
    {
        $cid = (int) $request->getContext()->getId(); $cfg = $this->plugin->cfg($cid);
        $col = Repo::submission()->getCollector()->filterByContextIds([$cid])->limit(200)
            ->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        if ($scope === 'published') $col->filterByStatus([Submission::STATUS_PUBLISHED]);
        $out = [];
        foreach ($col->getMany() as $sub) {
            if ((string) $sub->getData('submissionProgress') !== '') continue;
            $pub = $sub->getCurrentPublication(); if (!$pub) continue;
            $st = []; $done = 0;
            foreach ($cfg['items'] as $item) {
                $v = (string) $pub->getData(DeclarationsPlugin::field($item));
                [$no, $yes] = DeclarationsPlugin::ITEMS[$item];
                $hasText = (bool) array_filter(array_map(fn ($t) => trim(strip_tags((string) $t)), (array) $pub->getData(DeclarationsPlugin::textField($item))));
                $ok = $v === $no || ($v === $yes && $hasText);
                $st[$item] = ['v' => $v, 'ok' => $ok, 'yes' => $v === $yes];
                if ($ok) $done++;
            }
            $out[] = ['sub' => $sub, 'pub' => $pub, 'st' => $st, 'done' => $done, 'total' => count($cfg['items'])];
        }
        return $out;
    }

    public function index($args, $request)
    {
        $this->gate($request);
        $cid = (int) $request->getContext()->getId(); $cfg = $this->plugin->cfg($cid);
        $scope = $request->getUserVar('scope') === 'all' ? 'all' : 'published';
        $rows = $this->rows($request, $scope);
        $complete = count(array_filter($rows, fn ($r) => $r['done'] === $r['total']));
        $h = '';
        if ($request->getUserVar('saved')) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t('saved') . '</b></div>';
        $h .= '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>';
        $pct = $rows ? (int) round(100 * $complete / count($rows)) : 0;
        $card = fn ($v, $l, $col) => '<div style="flex:1;min-width:170px;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:26px;font-weight:700;color:' . $col . '">' . $v . '</div><div style="font-size:13px">' . $l . '</div></div>';
        $declared = [];
        foreach ($cfg['items'] as $item) $declared[$item] = count(array_filter($rows, fn ($r) => $r['st'][$item]['yes']));
        $h .= '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">' . $card($pct . '%', $this->t('stat.complete'), $pct >= 90 ? '#1e7b4a' : '#a86a00') . $card(count($rows), $this->t('stat.' . $scope), '#0b4f4a');
        foreach ($declared as $item => $n) $h .= $card($n, $this->t('stat.declared.' . $item), '#33658a');
        $h .= '</div>';

        if ($this->plugin->isManager($request)) {
            $cb = fn ($name, $val, $on, $label) => '<label style="display:inline-block;margin:4px 14px 4px 0"><input type="checkbox" name="' . $name . '" value="' . $val . '"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
            $items = '';
            foreach (array_keys(DeclarationsPlugin::ITEMS) as $item) $items .= $cb('items[]', $item, in_array($item, $cfg['items'], true), self::e($this->t("$item.label")));
            $h .= '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary><form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:8px">'
                . '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'
                . '<div style="font-weight:700">' . $this->t('set.items') . '</div>' . $items
                . '<div style="margin-top:6px">' . $cb('required', 1, $cfg['required'], $this->t('set.required')) . '</div>'
                . '<div>' . $cb('showOnArticle', 1, $cfg['showOnArticle'], $this->t('set.show')) . '</div>'
                . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></details>';
        }

        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px"><h2 style="margin:0;font-size:17px">' . $this->t('register') . '</h2><div>'
            . '<a style="' . ($scope === 'published' ? self::S_BTNP : self::S_BTN) . '" href="' . self::e($this->url($request, 'index', ['scope' => 'published'])) . '">' . $this->t('scope.published') . '</a>'
            . '<a style="' . ($scope === 'all' ? self::S_BTNP : self::S_BTN) . '" href="' . self::e($this->url($request, 'index', ['scope' => 'all'])) . '">' . $this->t('scope.all') . '</a>'
            . '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'csv', ['scope' => $scope])) . '">CSV</a></div></div>';
        if (!$rows) $h .= '<p>' . $this->t('none') . '</p>';
        else {
            $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:50px">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th>';
            foreach ($cfg['items'] as $item) $h .= '<th style="' . self::S_TH . 'width:110px">' . self::e($this->t("$item.short")) . '</th>';
            $h .= '<th style="' . self::S_TH . 'width:80px">' . $this->t('col.done') . '</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $sid = (int) $r['sub']->getId();
                $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $sid]);
                $h .= '<tr><td style="' . self::S_TD . '">' . $sid . '</td><td style="' . self::S_TD . '"><a href="' . self::e($wf) . '">' . self::e(strip_tags((string) $r['pub']->getLocalizedFullTitle())) . '</a></td>';
                foreach ($cfg['items'] as $item) {
                    $s = $r['st'][$item];
                    $cell = $s['v'] === '' ? '<span style="color:#b02a2a;font-weight:700">✖ ' . $this->t('missing') . '</span>'
                        : (!$s['ok'] ? '<span style="color:#a86a00;font-weight:700">▲ ' . $this->t('noText') . '</span>'
                        : '<span style="color:#1e7b4a;font-weight:700">✔</span> ' . self::e($this->t($item . '.' . $s['v'] . '.short')));
                    $h .= '<td style="' . self::S_TD . '">' . $cell . '</td>';
                }
                $col = $r['done'] === $r['total'] ? '#1e7b4a' : '#b02a2a';
                $h .= '<td style="' . self::S_TD . 'color:' . $col . ';font-weight:700">' . $r['done'] . '/' . $r['total'] . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('note') . '</div></div>';

        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $this->t('menu'), 'pluginHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    public function saveSettings($args, $request)
    {
        $this->gate($request, true);
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); exit; }
        $cid = (int) $request->getContext()->getId();
        $items = array_values(array_intersect(array_keys(DeclarationsPlugin::ITEMS), (array) $request->getUserVar('items')));
        $this->plugin->updateSetting($cid, 'items', $items, 'object');
        $this->plugin->updateSetting($cid, 'required', (bool) $request->getUserVar('required'), 'bool');
        $this->plugin->updateSetting($cid, 'showOnArticle', (bool) $request->getUserVar('showOnArticle'), 'bool');
        $request->redirectUrl($this->url($request, 'index', ['saved' => 1]));
    }

    public function csv($args, $request)
    {
        $this->gate($request);
        $cfg = $this->plugin->cfg((int) $request->getContext()->getId());
        $rows = $this->rows($request, $request->getUserVar('scope') === 'all' ? 'all' : 'published');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="declarations-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $head = ['ID', 'Title'];
        foreach ($cfg['items'] as $item) { $head[] = $item; $head[] = $item . '_text'; }
        fputcsv($out, array_merge($head, ['complete']));
        foreach ($rows as $r) {
            $line = [$r['sub']->getId(), strip_tags((string) $r['pub']->getLocalizedFullTitle())];
            foreach ($cfg['items'] as $item) {
                $line[] = $r['st'][$item]['v'];
                $line[] = trim(strip_tags((string) ($r['pub']->getLocalizedData(DeclarationsPlugin::textField($item)) ?? '')));
            }
            $line[] = $r['done'] . '/' . $r['total'];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}
