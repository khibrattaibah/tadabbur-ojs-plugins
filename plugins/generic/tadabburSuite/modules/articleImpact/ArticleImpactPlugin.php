<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/articleImpact/ArticleImpactPlugin.php
 *
 * «مؤشرات تأثير البحث» — Article Impact Metrics for OJS 3.5
 *  - في صفحة البحث: المشاهدات والتنزيلات (مع منحنى شهري) وعدد الاستشهادات (Crossref/OpenAlex)، وقائمة «استُشهد به في».
 *  - شارات Altmetric وDimensions اختيارية.
 *  - لوحة للمحررين: ترتيب البحوث بالقراءة والتنزيل والاستشهاد، وتحديث جماعي، وتصدير CSV.
 *  - «كيف تستشهد» وتنزيل RIS/BibTeX توفرهما إضافة Citation Style Language المدمجة في OJS، فلا تكررهما هذه الإضافة.
 */
namespace APP\plugins\generic\tadabburSuite\m\articleImpact;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\articleImpact\classes\Impact;
use APP\plugins\generic\tadabburSuite\m\articleImpact\pages\ImpactHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class ArticleImpactPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'articleImpact';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('Templates::Article::Details', $this->onDetails(...));
            Hook::add('Templates::Article::Main', $this->onMain(...));
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.articleImpact.name'); }
    public function getDescription() { return __('plugins.generic.articleImpact.description'); }
    public function getInstallMigration() { return new Impact(); }

    public function cfg(int $cid): array
    {
        $g = fn ($k, $d) => ($v = $this->getSetting($cid, $k)) === null ? $d : $v;
        return [
            'showBlock' => (bool) $g('showBlock', true),
            'showCitedBy' => (bool) $g('showCitedBy', true),
            'showChart' => (bool) $g('showChart', true),
            'altmetric' => (bool) $g('altmetric', false),
            'dimensions' => (bool) $g('dimensions', false),
            'refreshOnView' => (bool) $g('refreshOnView', false),
            'staleDays' => max(1, (int) $g('staleDays', 30)),
            'openalexKey' => (string) $g('openalexKey', ''),
        ];
    }

    public function impact($context): Impact
    {
        return new Impact((string) $context->getData('contactEmail'), \APP\plugins\generic\tadabburSuite\classes\kit\Services::openalexKey((int) $context->getId(), $this->cfg((int) $context->getId())['openalexKey']));
    }

    /** يحدّث إن كانت البيانات قديمة (اختياري، بمهلة قصيرة) */
    private function maybeRefresh($context, $submission, $publication, ?object $row): ?object
    {
        $cfg = $this->cfg((int) $context->getId());
        if (!$cfg['refreshOnView'] || !$publication->getDoi()) return $row;
        if ($row && $row->fetched_at && strtotime((string) $row->fetched_at) > time() - $cfg['staleDays'] * 86400) return $row;
        $imp = $this->impact($context);
        $imp->refresh((int) $context->getId(), (int) $submission->getId(), (string) $publication->getDoi(), 4);
        return $imp->get((int) $submission->getId());
    }

    public static function sparkline(array $monthly): string
    {
        $vals = array_map(fn ($m) => $m['v'] + $m['d'], array_values($monthly));
        $max = max(1, max($vals ?: [0]));
        $w = 220; $h = 46; $n = count($vals); $bw = $n ? $w / $n : $w;
        $o = '<svg viewBox="0 0 ' . $w . ' ' . ($h + 14) . '" width="100%" role="img" style="direction:ltr;display:block">';
        foreach ($vals as $i => $v) {
            $bh = round($v / $max * $h, 1);
            $o .= '<rect x="' . round($i * $bw + 1, 1) . '" y="' . ($h - $bh) . '" width="' . round($bw - 2, 1) . '" height="' . max(0.5, $bh) . '" rx="1.5" fill="#8a6d3b"><title>' . htmlspecialchars(array_keys($monthly)[$i]) . ': ' . $v . '</title></rect>';
        }
        $keys = array_keys($monthly);
        if ($keys) $o .= '<text x="0" y="' . ($h + 12) . '" font-size="9" fill="#777">' . htmlspecialchars($keys[0]) . '</text><text x="' . $w . '" y="' . ($h + 12) . '" font-size="9" fill="#777" text-anchor="end">' . htmlspecialchars(end($keys)) . '</text>';
        return $o . '</svg>';
    }

    public function onDetails(string $hookName, array $args): bool
    {
        $smarty = $args[1]; $output = &$args[2];
        $request = Application::get()->getRequest(); $context = $request->getContext();
        $submission = $smarty->getTemplateVars('article'); $publication = $smarty->getTemplateVars('publication');
        if (!$context || !$submission || !$publication) return false;
        $cfg = $this->cfg((int) $context->getId());
        if (!$cfg['showBlock']) return false;
        try {
            $u = Impact::usage((int) $context->getId(), (int) $submission->getId(), 12);
            $row = $this->maybeRefresh($context, $submission, $publication, $this->impact($context)->get((int) $submission->getId()));
        } catch (\Throwable $e) {
            return false;
        }
        $smarty->assign([
            'aiViews' => number_format($u['views']), 'aiDownloads' => number_format($u['downloads']), 'aiCitations' => Impact::best($row) === null ? '—' : number_format(Impact::best($row)),
            'aiChart' => $cfg['showChart'] && array_sum(array_map(fn ($m) => $m['v'] + $m['d'], $u['monthly'])) > 0 ? self::sparkline($u['monthly']) : '',
            'aiDoi' => (string) $publication->getDoi(), 'aiAltmetric' => $cfg['altmetric'], 'aiDimensions' => $cfg['dimensions'],
            'aiHasCiting' => $row && $row->citing && $cfg['showCitedBy'],
        ]);
        $output .= $smarty->fetch($this->getTemplateResource('block.tpl'));
        return false;
    }

    public function onMain(string $hookName, array $args): bool
    {
        $smarty = $args[1]; $output = &$args[2];
        $context = Application::get()->getRequest()->getContext(); $submission = $smarty->getTemplateVars('article');
        if (!$context || !$submission || !$this->cfg((int) $context->getId())['showCitedBy']) return false;
        try { $row = $this->impact($context)->get((int) $submission->getId()); } catch (\Throwable $e) { return false; }
        $citing = $row && $row->citing ? (json_decode((string) $row->citing, true) ?: []) : [];
        if (!$citing) return false;
        $smarty->assign(['aiCiting' => $citing, 'aiCitingTotal' => Impact::best($row)]);
        $output .= $smarty->fetch($this->getTemplateResource('citedBy.tpl'));
        return false;
    }

    public function canView($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        if (!$u || !$c) return false;
        return $u->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR], (int) $c->getId())
            || $u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID);
    }

    public function isManager($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        return $u && $c && ($u->hasRole([Role::ROLE_ID_MANAGER], (int) $c->getId()) || $u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID));
    }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== self::PAGE) return false;
        $handler = &$args[3];
        $handler = new ImpactHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['articleImpact'] = [
            'name' => __('plugins.generic.articleImpact.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Statistics',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('aiOpen', new RedirectAction($url), __('plugins.generic.articleImpact.open')));
        return $actions;
    }
}
