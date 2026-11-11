<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardPortal/BoardPortalPlugin.php
 * «بوابة الهيئتين» — هيئة التحرير والهيئة الاستشارية: الأعضاء، والاجتماعات والحضور والمحاضر، ومتابعة القرارات،
 * ونشاط كل عضو السنوي من بيانات OJS، وبوابة لكل عضو، وصفحة عامة للهيئتين.
 */
namespace APP\plugins\generic\tadabburSuite\m\boardPortal;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\boardPortal\classes\Store;
use APP\plugins\generic\tadabburSuite\m\boardPortal\pages\PortalHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class BoardPortalPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'boardPortal';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.boardPortal.name'); }
    public function getDescription() { return __('plugins.generic.boardPortal.description'); }
    public function getInstallMigration() { return new Store(); }

    public function isEditor($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        if (!$u || !$c) return false;
        return $u->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], (int) $c->getId())
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
        $handler = new PortalHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        $c = $request->getContext(); $u = $request->getUser();
        if (!$c || !$u) return false;
        $editor = $this->isEditor($request);
        $member = !$editor && (new Store())->memberByUser((int) $c->getId(), (int) $u->getId());
        if (!$editor && !$member) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['boardPortal'] = [
            'name' => __($editor ? 'plugins.generic.boardPortal.menu' : 'plugins.generic.boardPortal.myMenu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, $editor ? 'index' : 'mine'),
            'icon' => 'Users',
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
        array_unshift($actions, new LinkAction('bpOpen', new RedirectAction($url), __('plugins.generic.boardPortal.open')));
        return $actions;
    }
}
