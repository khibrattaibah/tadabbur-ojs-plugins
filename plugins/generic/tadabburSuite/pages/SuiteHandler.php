<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/pages/SuiteHandler.php
 * لوحة الحزمة (لمدير المجلة): الوحدات وتفعيلها، وهوية المجلة (التوقيع والختم والترويسة)، وفحص البيئة.
 */
namespace APP\plugins\generic\tadabburSuite\pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\classes\Branding;
use APP\plugins\generic\tadabburSuite\classes\Manifest;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use APP\plugins\generic\tadabburSuite\classes\Readiness;
use APP\plugins\generic\tadabburSuite\classes\SuiteMigration;
use APP\plugins\generic\tadabburSuite\TadabburSuitePlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class SuiteHandler extends Handler
{
    private const BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;';
    private const TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    private const BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';

    public function __construct(private TadabburSuitePlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.tadabburSuite.' . $k, $p); }
    private function url($request, string $op, array $q = []): string { return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, TadabburSuitePlugin::PAGE, $op, null, $q); }
    private function csrf($request): string { return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'; }
    private function gate($request, bool $post = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!$this->plugin->isManager($request)) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
        if ($post && (!$request->isPost() || !$request->checkCSRF())) { http_response_code(400); echo 'Bad request'; exit; }
    }
    private function back($request, string $msg = '', bool $ok = true): void
    {
        $request->redirectUrl($this->url($request, 'index', $msg !== '' ? ['m' => $msg, 'ok' => $ok ? 1 : 0] : []));
    }

    public const CATS = [
        'docs' => ['documentCenter', 'reviewerCertificate', 'officialLetters', 'reviewerHonor', 'declarations', 'smartTemplate'],
        'editorial' => ['editorialCommand', 'deskAssistant', 'preReviewCheck', 'reviewerHub', 'reviewCompare', 'boardVote', 'tasksBoard', 'integrityLog', 'authorResponse', 'authorTracker', 'proofApproval', 'boardPortal'],
        'quality' => ['metadataQuality', 'citationChecker', 'quranVerifier', 'referenceRomanizer', 'abstractAssistant', 'jatsExport'],
        'reach' => ['indexingReadiness', 'depositHub', 'scholarBoost', 'arabicIndexes', 'articleImpact', 'autoAnnounce', 'earlyView', 'issueBuilder', 'editorialTransparency'],
    ];

    private static function cat(string $key): string
    {
        foreach (self::CATS as $c => $list) if (in_array($key, $list, true)) return $c;
        return 'other';
    }

    private function card(string $title, string $inner, string $help = '', string $actions = ''): string
    {
        return '<section class="tdb-card"><header class="tdb-card__head"><h2>' . $title . '</h2>' . ($actions !== '' ? '<div class="tdb-actions">' . $actions . '</div>' : '') . '</header><div class="tdb-card__body">'
            . ($help !== '' ? '<p class="tdb-muted">' . $help . '</p>' : '') . $inner . '</div></section>';
    }

    public function index($args, $request)
    {
        $this->gate($request);
        $cid = (int) $request->getContext()->getId();
        $on = $this->plugin->enabledModules($cid);
        $h = '';
        if ($m = (string) $request->getUserVar('m')) {
            $ok = (bool) $request->getUserVar('ok');
            $h .= '<div class="tdb-alert tdb-alert--' . ($ok ? 'ok' : 'bad') . '" role="status">' . self::e($this->t('msg.' . preg_replace('/[^a-zA-Z]/', '', $m))) . '</div>';
        }
        $all = Modules::all();
        $mig = SuiteMigration::status();
        $checks = Readiness::run($cid); $tally = Readiness::tally($checks);
        $released = Modules::released();
        $h .= '<div class="tdb-head"><p class="tdb-lead">' . $this->t('intro', ['v' => TadabburSuitePlugin::VERSION]) . '</p></div>'
            . '<div class="tdb-stats">'
            . '<div class="tdb-stat tdb-stat--ok"><b>' . count(array_intersect($on, $released)) . '<small style="font-size:14px;color:var(--tdb-muted)"> / ' . count($released) . '</small></b><span>' . $this->t('st.active') . '</span></div>'
            . '<div class="tdb-stat"><b>' . (count($all) - count($released)) . '</b><span>' . $this->t('st.held') . '</span></div>'
            . '<div class="tdb-stat' . ($tally['fail'] ? ' tdb-stat--bad' : ' tdb-stat--ok') . '"><b>' . $tally['fail'] . '</b><span>' . $this->t('st.fail') . '</span></div>'
            . '<div class="tdb-stat"><b>' . $tally['unknown'] . '</b><span>' . $this->t('st.unknown') . '</span></div></div>'
            . '<p><a class="tdb-btn tdb-btn--primary" href="' . self::e($this->url($request, 'readiness')) . '">' . $this->t('readinessOpen') . ' ←</a></p>';
        if (Branding::legacyFiles() && count(array_filter(array_map(fn ($k) => Branding::custom($k), array_keys(Branding::ASSETS)))) < count(Branding::legacyFiles())) {
            $h .= '<div class="tdb-alert tdb-alert--info">' . $this->t('legacyBrand') . ' <form method="post" class="tdb-inline" action="' . self::e($this->url($request, 'importBrand')) . '">' . $this->csrf($request) . '<button class="tdb-btn tdb-btn--sm">' . $this->t('legacyBrandImport') . '</button></form></div>';
        }

        // ---------------- الوحدات (شبكة بطاقات + تصفية)
        $rows = [];
        foreach ($all as $key => $mm) {
            $name = $key; $desc = '';
            if (class_exists($mm['class'])) { $p = new $mm['class'](); $name = (string) $p->getDisplayName(); $desc = (string) $p->getDescription(); }
            // وحدة مؤجلة لم تُحمَّل ترجمتها: لا نعرض مفتاحًا خامًا ##...##
            if (str_starts_with($name, '##')) $name = $key;
            if (str_starts_with($desc, '##')) $desc = '';
            $desc = trim(preg_split('/(?:\s+[—-]\s+|(?<=[.،])\s+)(?:تطوير|Developed|Developer)/u', $desc)[0]);
            if (mb_strlen($desc) > 200) $desc = mb_substr($desc, 0, 198) . '…';
            $rows[$key] = [$name, $desc, $mm];
        }
        uasort($rows, fn ($x, $y) => strcmp($x[0], $y[0]));
        $chips = '<button type="button" class="tdb-chip" aria-pressed="true" data-cat="">' . $this->t('cat.all') . '</button>';
        foreach (array_merge(array_keys(self::CATS), ['other']) as $c) $chips .= '<button type="button" class="tdb-chip" aria-pressed="false" data-cat="' . $c . '">' . $this->t('cat.' . $c) . '</button>';
        $grid = '';
        foreach ($rows as $key => [$name, $desc, $mm]) {
            $rel = Manifest::released($key);
            $isOn = $rel && in_array($key, $on, true);
            $mrow = $mig[$key] ?? null;
            $missing = $rel ? Modules::missingRequires($key, $on) : [];
            $state = !$rel ? ['held', $this->t('state.held', ['n' => Manifest::stage($key)])]
                : (!$isOn ? ['off', $this->t('state.off')]
                : ($mrow && $mrow->state === 'ok' ? ($missing ? ['bad', $this->t('state.needs', ['m' => implode('، ', $missing)])] : ['ok', $this->t('state.on')]) : ['bad', $this->t('state.noMig')]));
            $link = $isOn && Modules::isActive($key) && defined($mm['class'] . '::PAGE') ? '<a class="tdb-btn tdb-btn--sm" href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, constant($mm['class'] . '::PAGE'), 'index')) . '">' . $this->t('open') . ' ←</a>' : '';
            $grid .= '<article class="tdb-module' . ($isOn ? '' : ' is-off') . '" data-cat="' . self::cat($key) . '" data-q="' . self::e(mb_strtolower($name . ' ' . $key . ' ' . $desc)) . '">'
                . '<div class="tdb-module__head"><h3>' . self::e($name) . '</h3>'
                . ($rel ? '<label class="tdb-switch" title="' . self::e($this->t('on')) . '"><input type="checkbox" name="on[]" value="' . self::e($key) . '"' . ($isOn ? ' checked' : '') . ' onchange="this.closest(\'.tdb-module\').classList.toggle(\'is-off\',!this.checked)"><span class="tdb-switch__ui"></span></label>' : '') . '</div>'
                . '<p>' . self::e($desc) . '</p>'
                . '<div class="tdb-badge tdb-badge--' . $state[0] . '">' . $state[1] . '</div>' . ($rel && Manifest::readOnly($key) ? ' <span class="tdb-badge tdb-badge--info">' . $this->t('state.ro') . '</span>' : '')
                . '<div class="tdb-module__foot"><span class="tdb-mono tdb-muted">' . self::e($key) . ' · v' . self::e($mm['version']) . '</span>' . $link . '</div></article>';
        }
        $js = '<script>(function(){var r=document.currentScript.parentNode,cat="",q="";function f(){r.querySelectorAll(".tdb-module").forEach(function(m){m.hidden=(cat&&m.dataset.cat!==cat)||(q&&m.dataset.q.indexOf(q)<0)})}'
            . 'r.querySelectorAll(".tdb-chip").forEach(function(b){b.onclick=function(){r.querySelectorAll(".tdb-chip").forEach(function(x){x.setAttribute("aria-pressed","false")});b.setAttribute("aria-pressed","true");cat=b.dataset.cat;f()}});'
            . 'r.querySelector(".tdb-filterbox").oninput=function(){q=this.value.trim().toLowerCase();f()}})();</script>';
        $h .= '<form method="post" action="' . self::e($this->url($request, 'saveModules')) . '">' . $this->csrf($request)
            . $this->card($this->t('modules'), '<div><div class="tdb-filter">' . $chips . '</div><input class="tdb-input tdb-filterbox" type="search" placeholder="' . self::e($this->t('searchModules')) . '"><div class="tdb-modules">' . $grid . '</div>' . $js . '</div>'
                . '<div class="tdb-checks" style="margin-top:14px"><label class="tdb-switch"><input type="checkbox" name="groupMenu" value="1"' . ($this->plugin->groupEnabled($cid) ? ' checked' : '') . '><span class="tdb-switch__ui"></span><span>' . $this->t('groupMenu') . '</span></label></div>'
                . '<div class="tdb-actions tdb-sticky"><button class="tdb-btn tdb-btn--primary">' . $this->t('save') . '</button></div>')
            . '</form>';

        // ---------------- الهوية
        $assets = '';
        foreach (Branding::ASSETS as $key => [$ext, $ar, $en, $size]) {
            $custom = Branding::custom($key);
            $assets .= '<div class="tdb-asset"><h3>' . self::e($ar) . '</h3><div class="tdb-muted tdb-mono">' . self::e($key . '.' . $ext . ' · ' . $size) . '</div>'
                . '<div class="tdb-asset__img">' . ($custom ? '<img alt="" src="' . self::e($this->url($request, 'file', ['key' => $key, 't' => filemtime($custom)])) . '">' : '<span class="tdb-muted">' . $this->t('usingDefault') . '</span>') . '</div>'
                . '<form method="post" enctype="multipart/form-data" class="tdb-toolbar" action="' . self::e($this->url($request, 'upload')) . '">' . $this->csrf($request) . '<input type="hidden" name="key" value="' . self::e($key) . '">'
                . '<input type="file" name="file" accept="image/png,image/jpeg" required style="font-size:12px;max-width:100%"><button class="tdb-btn tdb-btn--primary tdb-btn--sm">' . $this->t('upload') . '</button></form>'
                . ($custom ? '<form method="post" class="tdb-inline" action="' . self::e($this->url($request, 'resetAsset')) . '">' . $this->csrf($request) . '<input type="hidden" name="key" value="' . self::e($key) . '"><button class="tdb-btn tdb-btn--danger tdb-btn--sm">' . $this->t('reset') . '</button></form>' : '')
                . '</div>';
        }
        $h .= $this->card($this->t('branding'), '<div class="tdb-assets">' . $assets . '</div>', $this->t('brandingHelp'));

        // ---------------- مفاتيح الخدمات المشتركة
        $key = (string) $this->plugin->getSetting($cid, 'openalexKey');
        $h .= $this->card($this->t('services'), '<form method="post" class="tdb-toolbar" action="' . self::e($this->url($request, 'saveServices')) . '">' . $this->csrf($request)
            . '<label class="tdb-field" style="flex:1 1 320px;margin:0"><span class="tdb-field__label">' . $this->t('openalexKey') . '</span><input class="tdb-input" name="openalexKey" value="' . self::e($key) . '" dir="ltr" autocomplete="off"></label>'
            . '<button class="tdb-btn tdb-btn--primary" style="align-self:flex-end">' . $this->t('save') . '</button> <a href="https://openalex.org/signup" target="_blank" rel="noopener" style="align-self:flex-end">openalex.org/signup</a></form>', $this->t('servicesHelp'));

        // ---------------- الترحيل (الفحص التفصيلي في صفحة الجاهزية)
        $h .= $this->card($this->t('env'), '<p class="tdb-muted">' . $this->t('envHelp') . '</p>',
            '', '<form method="post" class="tdb-inline" action="' . self::e($this->url($request, 'migrate')) . '">' . $this->csrf($request) . '<button class="tdb-btn tdb-btn--sm">' . $this->t('runDb') . '</button></form>');

        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $this->t('menuManage'), 'pluginHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    public function saveModules($args, $request)
    {
        $this->gate($request, true);
        $cid = (int) $request->getContext()->getId();
        // لا يُفعَّل إلا ما صُرِّح بإطلاقه ونجح ترحيله، وتسبق التبعياتُ ما يعتمد عليها
        $want = array_map('strval', (array) $request->getUserVar('on'));
        $ok = SuiteMigration::okModules();
        $want = array_values(array_filter($want, fn ($k) => Manifest::released($k) && in_array($k, $ok, true)));
        $keep = Modules::loadOrder($want);
        $this->plugin->setEnabledModules($cid, $keep);
        $this->plugin->updateSetting($cid, 'groupMenu', $request->getUserVar('groupMenu') ? '1' : '0', 'string');
        $this->back($request, count($keep) === count($want) ? 'saved' : 'savedPartial', count($keep) === count($want));
    }

    public function importBrand($args, $request)
    {
        $this->gate($request, true);
        Branding::importLegacy();
        $this->back($request, 'saved');
    }

    public function readiness($args, $request)
    {
        $this->gate($request);
        $cid = (int) $request->getContext()->getId();
        $checks = Readiness::run($cid); $t = Readiness::tally($checks);
        $lab = ['ok' => $this->t('rd.ok'), 'fail' => $this->t('rd.fail'), 'unknown' => $this->t('rd.unknown'), 'info' => $this->t('rd.info')];
        $kind = ['ok' => 'ok', 'fail' => 'bad', 'unknown' => 'warn', 'info' => 'info'];
        $h = '<div class="tdb-head"><p class="tdb-lead">' . $this->t('rd.lead') . '</p></div><div class="tdb-stats">'
            . '<div class="tdb-stat tdb-stat--ok"><b>' . $t['ok'] . '</b><span>' . $lab['ok'] . '</span></div>'
            . '<div class="tdb-stat' . ($t['fail'] ? ' tdb-stat--bad' : '') . '"><b>' . $t['fail'] . '</b><span>' . $lab['fail'] . '</span></div>'
            . '<div class="tdb-stat"><b>' . $t['unknown'] . '</b><span>' . $lab['unknown'] . '</span></div></div>';
        $groups = [];
        foreach ($checks as $c) $groups[$c['group']][] = $c;
        foreach ($groups as $g => $list) {
            $rows = '';
            foreach ($list as $c) $rows .= '<tr><td>' . self::e($c['label']) . '</td><td><span class="tdb-badge tdb-badge--' . $kind[$c['status']] . '">' . $lab[$c['status']] . '</span></td><td dir="auto" class="tdb-muted">' . self::e($c['detail']) . '</td></tr>';
            $h .= $this->card($this->t('rd.g.' . $g), '<div class="tdb-table-wrap"><table class="tdb-table"><tbody>' . $rows . '</tbody></table></div>');
        }
        $h .= '<p><a class="tdb-btn" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a></p>';
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $this->t('readinessOpen'), 'pluginHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    public function saveServices($args, $request)
    {
        $this->gate($request, true);
        $this->plugin->updateSetting((int) $request->getContext()->getId(), 'openalexKey', preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $request->getUserVar('openalexKey')), 'string');
        $this->back($request, 'saved');
    }

    public function upload($args, $request)
    {
        $this->gate($request, true);
        $err = Branding::store((string) $request->getUserVar('key'), $_FILES['file'] ?? []);
        $this->back($request, $err ?? 'uploaded', $err === null);
    }

    public function resetAsset($args, $request)
    {
        $this->gate($request, true);
        Branding::reset((string) $request->getUserVar('key'));
        $this->back($request, 'reset');
    }

    public function file($args, $request)
    {
        $this->gate($request);
        $f = Branding::custom((string) $request->getUserVar('key'));
        if (!$f) { http_response_code(404); exit; }
        header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
        header('Cache-Control: private, max-age=60');
        readfile($f); exit;
    }

    public function migrate($args, $request)
    {
        $this->gate($request, true);
        $errors = SuiteMigration::run();
        $this->back($request, $errors ? 'migrateErr' : 'migrated', !$errors);
    }
}
