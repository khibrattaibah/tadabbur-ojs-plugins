<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/deskAssistant/DeskAssistantPlugin.php
 *
 * «مساعد القرار الأولي» — Desk Decision Assistant for OJS 3.5
 *  صفحة واحدة لكل بحث جديد تجمع للمحرر ما يحتاجه لقرار سريع: الملاءمة للنطاق وأقرب المنشور، واحتمال التكرار،
 *  وسجل المؤلفين، ونتائج الفحص الفني وتدقيق الآيات، وتوصية مبدئية، وتحليلًا اختياريًا عبر Claude.
 */
namespace APP\plugins\generic\tadabburSuite\m\deskAssistant;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\deskAssistant\classes\Analyzer;
use APP\plugins\generic\tadabburSuite\m\deskAssistant\pages\DeskHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class DeskAssistantPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'deskAssistant';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.deskAssistant.name'); }
    public function getDescription() { return __('plugins.generic.deskAssistant.description'); }
    public function getInstallMigration() { return new Analyzer(); }

    public function cfg($context): array
    {
        $cid = (int) $context->getId();
        $own = (string) $this->getSetting($cid, 'apiKey');
        $shared = '';
        if ($own === '') {
            // يُستعمل مفتاح «مساعد الترجمة» إن كان مضبوطًا، فلا يُدخل المدير المفتاح مرتين
            $shared = (string) \Illuminate\Support\Facades\DB::table('plugin_settings')->where('plugin_name', 'tdbabstractassistantplugin')->where('context_id', $cid)->where('setting_name', 'apiKey')->value('setting_value');
        }
        $scope = (string) $this->getSetting($cid, 'scope');
        if ($scope === '') {
            $parts = [];
            foreach (['description', 'about'] as $k) foreach ((array) $context->getData($k) as $v) $parts[] = trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES, 'UTF-8'));
            $scope = trim(implode("\n", array_filter($parts)));
        }
        return [
            'apiKey' => $own !== '' ? $own : $shared, 'keySource' => $own !== '' ? 'own' : ($shared !== '' ? 'shared' : 'none'),
            'model' => (string) ($this->getSetting($cid, 'model') ?: 'claude-sonnet-5-5'),
            'endpoint' => (string) ($this->getSetting($cid, 'endpoint') ?: 'https://api.anthropic.com/v1/messages'),
            'sendText' => (bool) $this->getSetting($cid, 'sendText'),
            'maxWords' => (int) ($this->getSetting($cid, 'maxWords') ?: 6000),
            'scope' => $scope, 'scopeOverride' => (string) $this->getSetting($cid, 'scope'),
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
        $handler = new DeskHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['deskAssistant'] = [
            'name' => __('plugins.generic.deskAssistant.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'InProgress',
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
        array_unshift($actions, new LinkAction('daOpen', new RedirectAction($url), __('plugins.generic.deskAssistant.open')));
        return $actions;
    }
}
