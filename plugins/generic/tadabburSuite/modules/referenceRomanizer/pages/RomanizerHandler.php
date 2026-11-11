<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/referenceRomanizer/pages/RomanizerHandler.php
 * المسارات: /referenceRomanizer (القائمة)، /referenceRomanizer/edit?submissionId= (المراجعة)،
 *           /referenceRomanizer/action (POST: حفظ/تطبيق/استعادة/توليد/ذكاء اصطناعي)، /referenceRomanizer/saveSettings (POST)
 */
namespace APP\plugins\generic\tadabburSuite\m\referenceRomanizer\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes\AiAssistant;
use APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes\Romanizer;
use APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes\Store;
use APP\plugins\generic\tadabburSuite\m\referenceRomanizer\ReferenceRomanizerPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class RomanizerHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:6px 14px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:6px 14px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:5px 7px;font-size:13px;background:#fff;color:#222;min-height:30px;width:100%;box-sizing:border-box;font-family:inherit;';

    private Store $store;

    public function __construct(private ReferenceRomanizerPlugin $plugin)
    {
        parent::__construct();
        $this->store = new Store();
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.referenceRomanizer.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, ReferenceRomanizerPlugin::PAGE, $op, null, $params);
    }

    private function gate($request): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!$this->plugin->canUse($request)) { http_response_code(403); echo $this->t('forbidden'); exit; }
    }

    private function postGate($request): void
    {
        $this->gate($request);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || !$request->checkCSRF()) { http_response_code(403); echo $this->t('forbidden'); exit; }
    }

    private function render($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    private static function lines(?string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $raw)), fn ($l) => $l !== ''));
    }

    private static function nonRoman(string $l): bool
    {
        $lat = preg_match_all('/\p{Latin}/u', $l); $ara = preg_match_all('/\p{Arabic}/u', $l);
        return $ara > 0 && $ara >= $lat;
    }

    private function csrf($request): string
    {
        return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">';
    }

    // ------------------------------------------------------------------ القائمة
    public function index($args, $request)
    {
        $this->gate($request);
        $context = $request->getContext(); $cid = (int) $context->getId();
        $rows = $this->store->all($cid);
        $collector = Repo::submission()->getCollector()->filterByContextIds([$cid]);
        $published = (clone $collector)->filterByStatus([Submission::STATUS_PUBLISHED, Submission::STATUS_SCHEDULED])->getIds()->all();
        $prod = (clone $collector)->filterByStatus([Submission::STATUS_QUEUED])->filterByStageIds([WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION])->getIds()->all();
        $only = (int) $request->getUserVar('submissionId');
        $ids = array_slice(array_reverse(array_unique(array_merge($published, $prod))), 0, 300);
        if ($only) $ids = [$only];
        $tr = ''; $pending = 0;
        foreach ($ids as $sid) {
            $sub = Repo::submission()->get((int) $sid);
            if (!$sub || (int) $sub->getData('contextId') !== $cid) continue;
            $pub = $sub->getCurrentPublication();
            $lines = self::lines($pub->getData('citationsRaw'));
            $ar = count(array_filter($lines, [self::class, 'nonRoman']));
            $row = $rows[(int) $pub->getId()] ?? null;
            $state = $row ? ((int) $row->applied ? '<span style="color:#1e7b4a;font-weight:700">' . $this->t('state.applied') . '</span>' : '<span style="color:#8a6d1f;font-weight:700">' . $this->t('state.draft') . '</span>')
                : ($ar ? '<span style="color:#b02a2a;font-weight:700">' . $this->t('state.todo') . '</span>' : '<span style="color:#1e7b4a">' . $this->t('state.roman') . '</span>');
            if ($ar && !($row && (int) $row->applied)) $pending++;
            $tr .= '<tr><td style="' . self::S_TD . '">#' . (int) $sid . '</td><td style="' . self::S_TD . '">' . self::e(mb_substr(strip_tags((string) $pub->getLocalizedFullTitle()), 0, 120)) . '</td>'
                . '<td style="' . self::S_TD . 'text-align:center">' . count($lines) . '</td><td style="' . self::S_TD . 'text-align:center">' . $ar . '</td><td style="' . self::S_TD . '">' . $state . '</td>'
                . '<td style="' . self::S_TD . '"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'edit', ['submissionId' => $sid])) . '">' . $this->t('review') . '</a></td></tr>';
        }
        $h = '<div style="' . self::S_BOX . 'border-inline-start:4px solid #006798"><p style="margin:0">' . $this->t('intro') . '</p></div>'
            . '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center"><b style="font-size:16px">' . $this->t('pending', ['count' => $pending]) . '</b>'
            . '<form method="get" action="' . self::e($this->url($request, 'index')) . '"><input type="number" name="submissionId" placeholder="ID" value="' . ($only ?: '') . '" style="' . self::S_INP . 'width:110px;display:inline-block"> <button style="' . self::S_BTN . '">' . $this->t('find') . '</button></form></div>'
            . '<table style="' . self::S_TBL . 'margin-top:10px"><thead><tr><th style="' . self::S_TH . '">ID</th><th style="' . self::S_TH . '">' . $this->t('title') . '</th><th style="' . self::S_TH . '">' . $this->t('refs') . '</th><th style="' . self::S_TH . '">' . $this->t('arabicRefs') . '</th><th style="' . self::S_TH . '">' . $this->t('state') . '</th><th style="' . self::S_TH . '"></th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="6" style="' . self::S_TD . '">' . $this->t('none') . '</td></tr>') . '</tbody></table></div>';
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request);
        $this->render($request, $this->t('menu'), $h);
    }

    private function settingsForm($request): string
    {
        $cfg = $this->plugin->cfg((int) $request->getContext()->getId());
        $row = fn ($label, $field, $help = '') => '<div style="display:grid;grid-template-columns:minmax(160px,240px) 1fr;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eee"><b>' . $label . '</b><div>' . $field . ($help ? '<div style="font-size:12px;color:#555">' . $help . '</div>' : '') . '</div></div>';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-size:16px;font-weight:700">' . $this->t('settings') . '</summary>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">' . $this->csrf($request)
            . $row($this->t('set.style'), '<select name="style" style="' . self::S_INP . 'width:auto"><option value="alalc"' . ($cfg['style'] === 'alalc' ? ' selected' : '') . '>ALA-LC (al-Ṭabarī)</option><option value="simple"' . ($cfg['style'] === 'simple' ? ' selected' : '') . '>' . $this->t('set.simple') . ' (al-Tabari)</option></select>')
            . $row($this->t('set.suffix'), '<input name="suffix" value="' . self::e($cfg['suffix']) . '" style="' . self::S_INP . 'max-width:260px" dir="ltr">', $this->t('set.suffixHelp'))
            . $row($this->t('set.showOriginal'), '<label><input type="checkbox" name="showOriginal" value="1"' . ($cfg['showOriginal'] ? ' checked' : '') . '> ' . $this->t('set.showOriginalHelp') . '</label>')
            . $row($this->t('set.apiKey'), '<input type="password" name="apiKey" autocomplete="off" placeholder="' . ($cfg['apiKey'] ? '••••••••' . self::e(substr($cfg['apiKey'], -4)) : 'sk-ant-…') . '" style="' . self::S_INP . 'max-width:360px" dir="ltr">'
                . ($cfg['apiKey'] ? ' <label style="font-size:12px"><input type="checkbox" name="clearKey" value="1"> ' . $this->t('set.clearKey') . '</label>' : ''), $this->t('set.apiKeyHelp'))
            . $row($this->t('set.model'), '<input name="model" value="' . self::e($cfg['model']) . '" style="' . self::S_INP . 'max-width:260px" dir="ltr">')
            . '<p><button type="submit" style="' . self::S_BTNP . '">' . $this->t('save') . '</button></p></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->postGate($request);
        if (!$this->plugin->isManager($request)) { http_response_code(403); exit; }
        $cid = (int) $request->getContext()->getId();
        $this->plugin->updateSetting($cid, 'style', $request->getUserVar('style') === 'simple' ? 'simple' : 'alalc');
        $this->plugin->updateSetting($cid, 'suffix', mb_substr(trim((string) $request->getUserVar('suffix')), 0, 60));
        $this->plugin->updateSetting($cid, 'showOriginal', (bool) $request->getUserVar('showOriginal'), 'bool');
        $key = trim((string) $request->getUserVar('apiKey'));
        if ($request->getUserVar('clearKey')) $this->plugin->updateSetting($cid, 'apiKey', '');
        elseif ($key !== '') $this->plugin->updateSetting($cid, 'apiKey', $key);
        $model = trim((string) $request->getUserVar('model'));
        if (preg_match('/^[a-z0-9.\-]{3,80}$/i', $model)) $this->plugin->updateSetting($cid, 'model', $model);
        $request->redirectUrl($this->url($request, 'index', ['saved' => 1]));
    }

    // ------------------------------------------------------------------ المراجعة
    private function load($request): array
    {
        $cid = (int) $request->getContext()->getId();
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $cid) { http_response_code(404); echo $this->t('notFound'); exit; }
        return [$sub, $sub->getCurrentPublication()];
    }

    /** يبني صفوف المسودة من الأصل (أو من النص الحالي إن لم يُطبَّق شيء) */
    private function buildRows(string $raw, Romanizer $r): array
    {
        $rows = [];
        foreach (self::lines($raw) as $l) {
            $ar = self::nonRoman($l);
            $res = $ar ? $r->romanize($l) : ['text' => $l, 'unknown' => []];
            $rows[] = ['orig' => $l, 'arabic' => $ar, 'roman' => $res['text'], 'trans' => '', 'unknown' => $res['unknown']];
        }
        return $rows;
    }

    public function edit($args, $request)
    {
        $this->gate($request);
        $this->editPage($request, '', true);
    }

    private function editPage($request, string $notice, bool $ok): void
    {
        [$sub, $pub] = $this->load($request);
        $cid = (int) $request->getContext()->getId(); $cfg = $this->plugin->cfg($cid);
        $row = $this->store->get((int) $pub->getId());
        $source = $row && (int) $row->applied ? (string) $row->original_raw : (string) $pub->getData('citationsRaw');
        $rows = $row && $row->rows_json ? (json_decode($row->rows_json, true) ?: []) : $this->buildRows($source, new Romanizer($cfg['style'] === 'simple'));
        $arCount = count(array_filter($rows, fn ($x) => $x['arabic']));

        $h = $notice !== '' ? '<div style="' . self::S_BOX . 'border-color:' . ($ok ? '#1e7b4a' : '#b02a2a') . ';background:' . ($ok ? '#eef8f2' : '#fdf0f0') . '"><b>' . self::e($notice) . '</b></div>' : '';
        $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'workflow', 'access', [$sub->getId()]);
        $h .= '<div style="' . self::S_BOX . '"><a href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a>'
            . '<h2 style="margin:8px 0 4px;font-size:18px">#' . (int) $sub->getId() . ' — ' . self::e(strip_tags((string) $pub->getLocalizedFullTitle())) . '</h2>'
            . '<div style="color:#555">' . $this->t('stats', ['total' => count($rows), 'arabic' => $arCount]) . ' · ' . ($row && (int) $row->applied ? '<b style="color:#1e7b4a">' . $this->t('state.applied') . '</b>' : ($row ? '<b style="color:#8a6d1f">' . $this->t('state.draft') . '</b>' : '')) . ' · <a href="' . self::e($wf) . '">' . $this->t('workflow') . '</a></div>'
            . ($pub->getData('status') == \PKP\submission\PKPSubmission::STATUS_PUBLISHED ? '<div style="margin-top:8px;padding:8px;background:#fff8e6;border:1px solid #f0d9a6;border-radius:6px;font-size:13px">' . $this->t('publishedNote') . '</div>' : '')
            . '</div>';

        if (!$rows) {
            $h .= '<div style="' . self::S_BOX . '">' . $this->t('noRefs') . '</div>';
            $this->render($request, $this->t('menu'), $h);
            return;
        }
        $tr = '';
        foreach ($rows as $i => $x) {
            if (!$x['arabic']) {
                $tr .= '<tr><td style="' . self::S_TD . 'text-align:center">' . ($i + 1) . '</td><td style="' . self::S_TD . '" dir="auto" colspan="2"><span style="color:#1e7b4a">✔</span> ' . self::e($x['orig']) . '<input type="hidden" name="roman[' . $i . ']" value="' . self::e($x['roman']) . '"><input type="hidden" name="trans[' . $i . ']" value=""></td><td style="' . self::S_TD . '"></td></tr>';
                continue;
            }
            $unk = $x['unknown'] ? '<div style="font-size:11px;color:#8a6d1f;margin-top:3px">' . $this->t('unknown') . ': <span dir="rtl">' . self::e(implode('، ', $x['unknown'])) . '</span></div>' : '';
            $tr .= '<tr><td style="' . self::S_TD . 'text-align:center">' . ($i + 1) . '</td>'
                . '<td style="' . self::S_TD . 'width:34%" dir="rtl" lang="ar">' . self::e($x['orig']) . '</td>'
                . '<td style="' . self::S_TD . '"><textarea name="roman[' . $i . ']" rows="3" dir="ltr" lang="en" style="' . self::S_INP . '">' . self::e($x['roman']) . '</textarea>' . $unk . '</td>'
                . '<td style="' . self::S_TD . 'width:22%"><input name="trans[' . $i . ']" value="' . self::e($x['trans'] ?? '') . '" dir="ltr" lang="en" placeholder="' . self::e($this->t('transPlaceholder')) . '" style="' . self::S_INP . '"></td></tr>';
        }
        $preview = self::e(implode("\n", $this->compose($rows, $cfg)));
        $btn = fn ($op, $label, $style, $confirm = '') => '<button type="submit" name="do" value="' . $op . '" style="' . $style . '">' . $label . '</button>';
        $h .= '<form method="post" action="' . self::e($this->url($request, 'action', ['submissionId' => (int) $sub->getId()])) . '">' . $this->csrf($request)
            . '<div style="' . self::S_BOX . '"><div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px">'
            . $btn('save', $this->t('do.save'), self::S_BTN)
            . $btn('regenerate', $this->t('do.regenerate'), self::S_BTN)
            . ($cfg['apiKey'] ? $btn('ai', $this->t('do.ai'), self::S_BTN) : '<span style="font-size:12px;color:#666">' . $this->t('aiOff') . '</span>')
            . '<span style="flex:1"></span>'
            . $btn('apply', $this->t('do.apply'), self::S_BTNP)
            . ($row && (int) $row->applied ? $btn('restore', $this->t('do.restore'), self::S_BTN) : '')
            . '</div><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:36px">#</th><th style="' . self::S_TH . '">' . $this->t('original') . '</th><th style="' . self::S_TH . '">' . $this->t('romanized') . '</th><th style="' . self::S_TH . '">' . $this->t('translation') . '</th></tr></thead><tbody>' . $tr . '</tbody></table>'
            . '<h3 style="font-size:15px;margin:16px 0 6px">' . $this->t('preview') . '</h3><pre dir="ltr" style="white-space:pre-wrap;background:#f8f9fa;border:1px solid #e1e4e8;border-radius:6px;padding:10px;font-size:12.5px;font-family:inherit;max-height:360px;overflow:auto">' . $preview . '</pre>'
            . '<p style="font-size:12px;color:#555">' . $this->t('applyHelp') . '</p></div></form>';
        $this->render($request, $this->t('menu'), $h);
    }

    /** يركّب قائمة المراجع النهائية */
    private function compose(array $rows, array $cfg): array
    {
        $out = [];
        foreach ($rows as $x) {
            if (!$x['arabic']) { $out[] = $x['orig']; continue; }
            $r = rtrim(trim($x['roman']));
            $t = trim((string) ($x['trans'] ?? ''));
            if ($t !== '') {
                // يُدرج الترجمة بعد العنوان (المقطع الثاني في صيغة: المؤلف. العنوان. المكان: الناشر، السنة.)
                $parts = preg_split('/(?<=[^A-Z\s]\.)\s+/u', $r);
                if (count($parts) >= 3) { $parts[1] = rtrim($parts[1], '. ') . ' [' . $t . '].'; $r = implode(' ', $parts); }
                else $r = rtrim($r, '. ') . ' [' . $t . '].';
            }
            if ($cfg['suffix'] !== '') $r = rtrim($r) . ' ' . $cfg['suffix'];
            $out[] = $r;
        }
        return $out;
    }

    public function action($args, $request)
    {
        $this->postGate($request);
        [$sub, $pub] = $this->load($request);
        $cid = (int) $request->getContext()->getId(); $cfg = $this->plugin->cfg($cid);
        $row = $this->store->get((int) $pub->getId());
        $source = $row && (int) $row->applied ? (string) $row->original_raw : (string) $pub->getData('citationsRaw');
        $do = (string) $request->getUserVar('do');
        $rows = $row && $row->rows_json ? (json_decode($row->rows_json, true) ?: []) : $this->buildRows($source, new Romanizer($cfg['style'] === 'simple'));
        // دمج ما أدخله المحرر
        $roman = (array) $request->getUserVar('roman'); $trans = (array) $request->getUserVar('trans');
        foreach ($rows as $i => &$x) {
            if (isset($roman[$i])) $x['roman'] = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) $roman[$i])), 0, 4000);
            if (isset($trans[$i])) $x['trans'] = mb_substr(trim((string) $trans[$i]), 0, 1000);
        }
        unset($x);
        try {
            switch ($do) {
                case 'regenerate':
                    $rows = $this->buildRows($source, new Romanizer($cfg['style'] === 'simple'));
                    $msg = $this->t('msg.regenerated');
                    break;
                case 'ai':
                    if (!$cfg['apiKey']) throw new \RuntimeException($this->t('aiOff'));
                    $idx = array_keys(array_filter($rows, fn ($x) => $x['arabic']));
                    $ai = (new AiAssistant($cfg['apiKey'], $cfg['model'], $cfg['endpoint'], $cfg['style'] === 'simple'))->suggest(array_map(fn ($i) => $rows[$i]['orig'], $idx));
                    foreach ($idx as $k => $i) {
                        if (!empty($ai[$k]['romanized'])) { $rows[$i]['roman'] = $ai[$k]['romanized']; $rows[$i]['unknown'] = []; }
                        if (!empty($ai[$k]['translation'])) $rows[$i]['trans'] = $ai[$k]['translation'];
                    }
                    $msg = $this->t('msg.ai', ['count' => count($ai)]);
                    break;
                case 'apply':
                    $final = implode("\n", $this->compose($rows, $cfg));
                    Repo::publication()->edit($pub, ['citationsRaw' => $final]);
                    $this->store->save((int) $pub->getId(), $cid, ['original_raw' => $source, 'rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'applied' => 1,
                        'applied_by' => (int) $request->getUser()->getId(), 'applied_at' => gmdate('Y-m-d H:i:s')]);
                    $this->editPage($request, $this->t('msg.applied'), true);
                    return;
                case 'restore':
                    if (!$row || !(int) $row->applied) throw new \RuntimeException($this->t('msg.nothing'));
                    Repo::publication()->edit($pub, ['citationsRaw' => (string) $row->original_raw]);
                    $this->store->save((int) $pub->getId(), $cid, ['applied' => 0, 'rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE)]);
                    $this->editPage($request, $this->t('msg.restored'), true);
                    return;
                default:
                    $msg = $this->t('msg.saved');
            }
            $this->store->save((int) $pub->getId(), $cid, ['original_raw' => $source, 'rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'applied' => $row ? (int) $row->applied : 0]);
            $this->editPage($request, $msg, true);
        } catch (\Throwable $e) {
            error_log('[referenceRomanizer] ' . $e->getMessage());
            $this->store->save((int) $pub->getId(), $cid, ['original_raw' => $source, 'rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'applied' => $row ? (int) $row->applied : 0]);
            $this->editPage($request, $this->t('msg.error') . ' ' . mb_substr($e->getMessage(), 0, 200), false);
        }
    }
}
