<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/kit/ModulePlugin.php
 * أساس مشترك لوحدات الحزمة الجديدة: تسجيل الصفحة، وبند القائمة، والصلاحيات، والإعدادات.
 * الوحدة تعرّف PAGE وhandler() وتعيد تعريف menuFor() عند الحاجة.
 */
namespace APP\plugins\generic\tadabburSuite\classes\kit;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\Hook;
use PKP\security\Role;

abstract class ModulePlugin extends ModuleBase
{
    public const PAGE = '';
    public const ICON = 'Tools';

    abstract public function handler(): object;


    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
            $this->hooks();
        }
        return $success;
    }

    /** خطافات إضافية للوحدة */
    protected function hooks(): void {}

    public function prefix(): string { return 'plugins.generic.' . static::PAGE; }
    public function getDisplayName() { return __($this->prefix() . '.name'); }
    public function getDescription() { return __($this->prefix() . '.description'); }

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

    /** إعداد مخزَّن للمجلة مع قيمة افتراضية */
    public function cfg(int $cid, string $k, $default = null)
    {
        $v = $this->getSetting($cid, $k);
        return $v === null ? $default : $v;
    }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== static::PAGE) return false;
        $handler = &$args[3];
        $handler = $this->handler();
        return true;
    }

    /** [مفتاح الاسم، العملية] لبند القائمة، أو null لإخفائه */
    protected function menuFor($request): ?array { return $this->isEditor($request) ? ['menu', 'index'] : null; }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$request->getUser()) return false;
        $m = $this->menuFor($request);
        if (!$m) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu[static::PAGE] = ['name' => __($this->prefix() . '.' . $m[0]),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, static::PAGE, $m[1]),
            'icon' => static::ICON, 'isCurrent' => $request->getRequestedPage() === static::PAGE];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, static::PAGE, 'index');
        array_unshift($actions, new LinkAction(static::PAGE . 'Open', new RedirectAction($url), __($this->prefix() . '.open')));
        return $actions;
    }
}
