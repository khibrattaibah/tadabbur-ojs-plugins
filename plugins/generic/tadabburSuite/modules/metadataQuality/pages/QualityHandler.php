<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/metadataQuality/pages/QualityHandler.php
 * المسارات: /metadataQuality (اللوحة)، /metadataQuality/saveSettings (POST)، /metadataQuality/csv (تصدير)
 * لوحة OJS 3.5 تعرض المحتوى داخل تطبيق Vue: لا سكربتات، والتنسيق مضمّن.
 */
namespace APP\plugins\generic\tadabburSuite\m\metadataQuality\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\metadataQuality\classes\QualityChecker;
use APP\plugins\generic\tadabburSuite\m\metadataQuality\MetadataQualityPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class QualityHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:6px 14px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:6px 14px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;';

    public function __construct(private MetadataQualityPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.metadataQuality.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, MetadataQualityPlugin::PAGE, $op, null, $params);
    }

    private function gate($request): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!$this->plugin->canView($request)) { http_response_code(403); echo $this->t('forbidden'); exit; }
    }

    private function badge(array $std): string
    {
        $o = '';
        foreach ($std as $s) $o .= '<span style="display:inline-block;margin:1px 2px;padding:0 6px;border-radius:9px;background:#eef2f6;color:#334;font-size:11px;font-weight:700" dir="ltr">' . self::e($s) . '</span>';
        return $o;
    }

    private function status(array $ch): string
    {
        if ($ch['ok']) return '<span style="color:#1e7b4a;font-weight:700">✔ ' . $this->t('ok') . '</span>';
        return $ch['level'] === QualityChecker::REQUIRED
            ? '<span style="color:#b02a2a;font-weight:700">✖ ' . $this->t('missingRequired') . '</span>'
            : '<span style="color:#8a6d1f;font-weight:700">▲ ' . $this->t('missingRecommended') . '</span>';
    }

    private function scoreBar(int $score): string
    {
        $col = $score >= 90 ? '#1e7b4a' : ($score >= 70 ? '#c08a1e' : '#b02a2a');
        return '<div style="display:flex;align-items:center;gap:8px;min-width:120px"><div style="flex:1;height:8px;background:#e9ecef;border-radius:4px;overflow:hidden">'
            . '<div style="width:' . $score . '%;height:100%;background:' . $col . '"></div></div><b style="color:' . $col . '">' . $score . '%</b></div>';
    }

    /** @return array<int, array{submission: Submission, publication: object, res: array}> */
    private function rows($request, array $cfg, string $scope, int $only = 0): array
    {
        $context = $request->getContext();
        $collector = Repo::submission()->getCollector()->filterByContextIds([(int) $context->getId()]);
        if ($scope === 'published') $collector->filterByStatus([Submission::STATUS_PUBLISHED]);
        elseif ($scope === 'production') $collector->filterByStatus([Submission::STATUS_QUEUED, Submission::STATUS_SCHEDULED])->filterByStageIds([WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION]);
        else {
            $published = (clone $collector)->filterByStatus([Submission::STATUS_PUBLISHED, Submission::STATUS_SCHEDULED])->getIds()->all();
            $prod = (clone $collector)->filterByStatus([Submission::STATUS_QUEUED])->filterByStageIds([WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION])->getIds()->all();
            $collector = Repo::submission()->getCollector()->filterByContextIds([(int) $context->getId()])->filterBySubmissionIds(array_merge($published, $prod) ?: [0]);
        }
        $collector->limit(300)->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        $checker = new QualityChecker($cfg); $out = [];
        foreach ($collector->getMany() as $sub) {
            if ($only && (int) $sub->getId() !== $only) continue;
            if ($sub->getData('submissionProgress')) continue;
            $pub = $sub->getCurrentPublication();
            if (!$pub) continue;
            $out[] = ['submission' => $sub, 'publication' => $pub, 'res' => $checker->checkPublication($pub, $sub, $context)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ اللوحة
    public function index($args, $request)
    {
        $this->gate($request);
        $this->page($request, '');
    }

    private function page($request, string $notice): void
    {
        $context = $request->getContext(); $cid = (int) $context->getId();
        $cfg = $this->plugin->cfg($cid, $context);
        $scope = in_array($request->getUserVar('scope'), ['published', 'production', 'all'], true) ? $request->getUserVar('scope') : 'all';
        $only = (int) $request->getUserVar('submissionId');
        $checker = new QualityChecker($cfg);
        $journal = $checker->checkJournal($context);
        $rows = $this->rows($request, $cfg, $scope, $only);
        $avg = $rows ? (int) round(array_sum(array_map(fn ($r) => $r['res']['score'], $rows)) / count($rows)) : 0;
        $blocked = count(array_filter($rows, fn ($r) => $r['res']['missingRequired'] > 0));

        $h = $notice !== '' ? '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . self::e($notice) . '</b></div>' : '';
        $card = fn ($v, $l, $col) => '<div style="flex:1;min-width:170px;' . self::S_BOX . 'margin:0;border-top:4px solid ' . $col . '"><div style="font-size:28px;font-weight:700;color:' . $col . '">' . $v . '</div><div>' . $l . '</div></div>';
        $h .= '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">'
            . $card($journal['score'] . '%', $this->t('journalReadiness'), $journal['score'] >= 85 ? '#1e7b4a' : '#c08a1e')
            . $card($avg . '%', $this->t('avgArticleScore'), $avg >= 85 ? '#1e7b4a' : '#c08a1e')
            . $card($blocked . ' / ' . count($rows), $this->t('articlesWithGaps'), $blocked ? '#b02a2a' : '#1e7b4a')
            . $card($cfg['gate'] === 'block' ? $this->t('gateOn') : $this->t('gateOff'), $this->t('publishGate'), $cfg['gate'] === 'block' ? '#1e7b4a' : '#6c757d')
            . '</div>';

        // ---- جاهزية المجلة
        $fix = [
            'jIssn' => 'distribution', 'jIssnChecksum' => 'distribution', 'jPublisher' => 'context', 'jContact' => 'context', 'jAbout' => 'context',
            'jEnglish' => 'website', 'jPeerReview' => 'workflow', 'jEthics' => 'context', 'jOpenAccess' => 'distribution', 'jLicense' => 'distribution',
            'jCopyright' => 'distribution', 'jPrivacy' => 'workflow', 'jAuthorGuidelines' => 'workflow', 'jFees' => 'distribution', 'jArchiving' => 'distribution',
            'jDoi' => 'distribution', 'jOai' => 'distribution', 'jBoard' => 'access', 'jBoardAffiliation' => 'access', 'jBoardDiversity' => 'access', 'jScholarPlugin' => 'website',
        ];
        $jr = '';
        foreach ($journal['checks'] as $ch) {
            $link = isset($fix[$ch['id']]) ? ' <a style="font-size:12px" href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'management', 'settings', [$fix[$ch['id']]])) . '">' . $this->t('fix') . '</a>' : '';
            $jr .= '<tr><td style="' . self::S_TD . 'white-space:nowrap">' . $this->status($ch) . '</td><td style="' . self::S_TD . '"><b>' . self::e($this->t('check.' . $ch['id'])) . '</b>'
                . '<div style="color:#555;font-size:12px">' . self::e($this->t('hint.' . $ch['id'])) . '</div></td><td style="' . self::S_TD . '">' . self::e($ch['detail']) . $link . '</td><td style="' . self::S_TD . '">' . $this->badge($ch['std']) . '</td></tr>';
        }
        $h .= '<details open style="' . self::S_BOX . '"><summary style="cursor:pointer;font-size:17px;font-weight:700">' . $this->t('journalChecklist') . ' — ' . $journal['score'] . '%</summary>'
            . '<p style="color:#555">' . $this->t('journalChecklistIntro') . '</p><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:130px">' . $this->t('status') . '</th><th style="' . self::S_TH . '">' . $this->t('criterion') . '</th><th style="' . self::S_TH . '">' . $this->t('value') . '</th><th style="' . self::S_TH . 'width:150px">' . $this->t('standards') . '</th></tr></thead><tbody>' . $jr . '</tbody></table></details>';

        // ---- الإعدادات
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request, $cfg);

        // ---- البحوث
        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between"><h2 style="margin:0;font-size:17px">' . $this->t('articles') . '</h2>'
            . '<form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">'
            . '<select name="scope" style="' . self::S_INP . '">' . implode('', array_map(fn ($k) => '<option value="' . $k . '"' . ($scope === $k ? ' selected' : '') . '>' . $this->t('scope.' . $k) . '</option>', ['all', 'production', 'published'])) . '</select>'
            . '<input type="number" min="1" name="submissionId" value="' . ($only ?: '') . '" placeholder="ID" style="' . self::S_INP . 'width:90px">'
            . '<button type="submit" style="' . self::S_BTN . '">' . $this->t('apply') . '</button>'
            . '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'csv', ['scope' => $scope])) . '">CSV</a></form></div>';
        $tr = '';
        usort($rows, fn ($a, $b) => $a['res']['score'] <=> $b['res']['score']);
        foreach ($rows as $r) {
            $sub = $r['submission']; $pub = $r['publication']; $res = $r['res'];
            $req = array_filter($res['checks'], fn ($c) => !$c['ok'] && $c['level'] === QualityChecker::REQUIRED);
            $detail = '';
            foreach ($res['checks'] as $ch) {
                $detail .= '<tr><td style="' . self::S_TD . 'white-space:nowrap">' . $this->status($ch) . '</td><td style="' . self::S_TD . '">' . self::e($this->t('check.' . $ch['id'])) . '</td><td style="' . self::S_TD . '">' . self::e($ch['detail']) . '</td><td style="' . self::S_TD . '">' . $this->badge($ch['std']) . '</td></tr>';
            }
            $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'workflow', 'access', [$sub->getId()]);
            $status = $sub->getData('status') == Submission::STATUS_PUBLISHED ? $this->t('scope.published') : ($sub->getData('status') == Submission::STATUS_SCHEDULED ? $this->t('scheduled') : $this->t('inProgress'));
            $tr .= '<tr><td style="' . self::S_TD . '"><a href="' . self::e($wf) . '">#' . (int) $sub->getId() . '</a></td>'
                . '<td style="' . self::S_TD . '">' . self::e(mb_substr(strip_tags((string) $pub->getLocalizedFullTitle()), 0, 120)) . '<div style="color:#666;font-size:12px">' . $status . '</div></td>'
                . '<td style="' . self::S_TD . '">' . $this->scoreBar($res['score']) . '</td>'
                . '<td style="' . self::S_TD . '">' . ($req ? '<span style="color:#b02a2a">' . self::e(implode('، ', array_unique(array_map(fn ($c) => $this->t('check.' . $c['id']), $req)))) . '</span>' : '—') . '</td>'
                . '<td style="' . self::S_TD . 'text-align:center">' . (int) $res['missingRecommended'] . '</td>'
                . '<td style="' . self::S_TD . '"><details><summary style="cursor:pointer;color:#006798">' . $this->t('details') . '</summary><table style="' . self::S_TBL . 'margin-top:6px">' . $detail . '</table></details></td></tr>';
        }
        $h .= '<table style="' . self::S_TBL . 'margin-top:10px"><thead><tr><th style="' . self::S_TH . '">ID</th><th style="' . self::S_TH . '">' . $this->t('title') . '</th><th style="' . self::S_TH . '">' . $this->t('score') . '</th><th style="' . self::S_TH . '">' . $this->t('missingRequired') . '</th><th style="' . self::S_TH . '">' . $this->t('missingRecommended') . '</th><th style="' . self::S_TH . '"></th></tr></thead><tbody>'
            . ($tr !== '' ? $tr : '<tr><td colspan="6" style="' . self::S_TD . '">' . $this->t('noArticles') . '</td></tr>') . '</tbody></table>'
            . '<p style="font-size:12px;color:#555">' . $this->t('scoreNote') . '</p></div>';

        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $this->t('menu'), 'pluginHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    private function settingsForm($request, array $cfg): string
    {
        $context = $request->getContext();
        $locales = '';
        foreach ((array) $context->getSupportedSubmissionLocales() as $l) {
            $locales .= '<label style="margin-inline-end:12px"><input type="checkbox" name="locales[]" value="' . self::e($l) . '"' . (in_array($l, $cfg['locales'], true) ? ' checked' : '') . '> ' . self::e(Locale::getMetadata($l)?->getDisplayName() ?? $l) . '</label>';
        }
        $sections = '';
        foreach (Repo::section()->getCollector()->filterByContextIds([(int) $context->getId()])->getMany() as $s) {
            $sections .= '<label style="margin-inline-end:12px"><input type="checkbox" name="exemptSections[]" value="' . (int) $s->getId() . '"' . (in_array((int) $s->getId(), $cfg['exemptSections'], true) ? ' checked' : '') . '> ' . self::e($s->getLocalizedTitle()) . '</label>';
        }
        $opt = fn ($name, $val, $opts) => '<select name="' . $name . '" style="' . self::S_INP . '">' . implode('', array_map(fn ($k) => '<option value="' . $k . '"' . ($val === $k ? ' selected' : '') . '>' . $this->t($name . '.' . $k) . '</option>', $opts)) . '</select>';
        $row = fn ($label, $field) => '<div style="display:grid;grid-template-columns:minmax(160px,240px) 1fr;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eee"><b>' . $label . '</b><div>' . $field . '</div></div>';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-size:17px;font-weight:700">' . $this->t('settings') . '</summary>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">'
            . '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'
            . $row($this->t('set.locales'), $locales)
            . $row($this->t('set.minAbstractWords'), '<input type="number" min="0" name="minAbstractWords" value="' . (int) $cfg['minAbstractWords'] . '" style="' . self::S_INP . 'width:100px">')
            . $row($this->t('set.minKeywords'), '<input type="number" min="0" name="minKeywords" value="' . (int) $cfg['minKeywords'] . '" style="' . self::S_INP . 'width:100px">')
            . $row($this->t('set.requireOrcid'), $opt('requireOrcid', $cfg['requireOrcid'], ['none', 'corresponding', 'all']))
            . $row($this->t('set.exemptSections'), $sections ?: '—')
            . $row($this->t('set.gate'), $opt('gate', $cfg['gate'], ['off', 'block']) . '<div style="font-size:12px;color:#555;margin-top:4px">' . $this->t('set.gateHelp') . '</div>')
            . '<p><button type="submit" style="' . self::S_BTNP . '">' . $this->t('save') . '</button></p></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->gate($request);
        if (!$this->plugin->isManager($request) || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || !$request->checkCSRF()) {
            http_response_code(403); echo $this->t('forbidden'); exit;
        }
        $cid = (int) $request->getContext()->getId();
        $supported = (array) $request->getContext()->getSupportedSubmissionLocales();
        $locales = array_values(array_intersect((array) $request->getUserVar('locales'), $supported));
        $this->plugin->updateSetting($cid, 'locales', $locales ?: null);
        $this->plugin->updateSetting($cid, 'minAbstractWords', max(0, min(1000, (int) $request->getUserVar('minAbstractWords'))));
        $this->plugin->updateSetting($cid, 'minKeywords', max(0, min(20, (int) $request->getUserVar('minKeywords'))));
        $this->plugin->updateSetting($cid, 'requireOrcid', in_array($request->getUserVar('requireOrcid'), ['none', 'corresponding', 'all'], true) ? $request->getUserVar('requireOrcid') : 'corresponding');
        $this->plugin->updateSetting($cid, 'exemptSections', array_map('intval', (array) $request->getUserVar('exemptSections')));
        $this->plugin->updateSetting($cid, 'gate', $request->getUserVar('gate') === 'block' ? 'block' : 'off');
        $this->page($request, $this->t('saved'));
    }

    public function csv($args, $request)
    {
        $this->gate($request);
        $context = $request->getContext();
        $cfg = $this->plugin->cfg((int) $context->getId(), $context);
        $scope = in_array($request->getUserVar('scope'), ['published', 'production', 'all'], true) ? $request->getUserVar('scope') : 'all';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="metadata-quality-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['submission_id', 'title', 'doi', 'score', 'missing_required', 'missing_recommended', 'required_gaps', 'recommended_gaps']);
        foreach ($this->rows($request, $cfg, $scope) as $r) {
            $gaps = fn ($lvl) => implode(' | ', array_unique(array_map(fn ($c) => __('plugins.generic.metadataQuality.check.' . $c['id']) . ($c['detail'] !== '' ? ' (' . $c['detail'] . ')' : ''), array_filter($r['res']['checks'], fn ($c) => !$c['ok'] && $c['level'] === $lvl))));
            fputcsv($out, [$r['submission']->getId(), strip_tags((string) $r['publication']->getLocalizedFullTitle()), (string) $r['publication']->getDoi(), $r['res']['score'],
                $r['res']['missingRequired'], $r['res']['missingRecommended'], $gaps(QualityChecker::REQUIRED), $gaps(QualityChecker::RECOMMENDED)]);
        }
        fclose($out);
        exit;
    }
}
