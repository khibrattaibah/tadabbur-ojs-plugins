<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/indexingReadiness/IndexingReadinessPlugin.php
 *
 * «ملف الجاهزية للفهرسة» — Indexing Readiness Dossier for OJS 3.5
 *  - يقيس المجلة على معايير Scopus (14 معيارًا + الشروط الأولية) وWeb of Science (24 + 4) وDOAJ.
 *  - مؤشرات كمية من OJS (الانتظام، التنوع الجغرافي، الاستشهاد الذاتي، التغطية الإنجليزية…) ومؤشرات استشهاد من OpenAlex.
 *  - تقييم يدوي موثّق بالأدلة للمعايير النوعية، وخطة عمل مرتبة، وملف قابل للطباعة يُرفق بطلب الفهرسة.
 */
namespace APP\plugins\generic\tadabburSuite\m\indexingReadiness;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\indexingReadiness\pages\ReadinessHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class IndexingReadinessPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'indexingReadiness';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.indexingReadiness.name'); }
    public function getDescription() { return __('plugins.generic.indexingReadiness.description'); }

    public function cfg(int $cid): array
    {
        return [
            'years' => max(1, min(10, (int) ($this->getSetting($cid, 'years') ?: 3))),
            'expectedPerYear' => max(1, min(24, (int) ($this->getSetting($cid, 'expectedPerYear') ?: 2))),
            'manual' => (array) ($this->getSetting($cid, 'manual') ?: []),
            'openalex' => $this->getSetting($cid, 'openalex') ?: null,
        ];
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
        $handler = new ReadinessHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['indexingReadiness'] = [
            'name' => __('plugins.generic.indexingReadiness.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Globe',
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
        array_unshift($actions, new LinkAction('irOpen', new RedirectAction($url), __('plugins.generic.indexingReadiness.open')));
        return $actions;
    }
}
