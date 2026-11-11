<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/abstractAssistant/AbstractAssistantPlugin.php
 *
 * «مساعد الترجمة والملخصات» — Abstract & Metadata Translation Assistant for OJS 3.5
 *  - يترجم العنوان والملخص والكلمات المفتاحية بين العربية والإنجليزية (ولغات أخرى) عبر Claude،
 *    أو يدقق النسخة الإنجليزية الموجودة لغويًا ويقارنها بالأصل.
 *  - مسرد مصطلحات للمجلة يُلزم المساعد بترجمات ثابتة.
 *  - لا يُطبَّق شيء على البحث إلا بعد مراجعة المحرر وتعديله واعتماده حقلًا حقلًا، مع سجل للمسودات.
 */
namespace APP\plugins\generic\tadabburSuite\m\abstractAssistant;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\abstractAssistant\classes\Drafts;
use APP\plugins\generic\tadabburSuite\m\abstractAssistant\pages\AssistantHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class AbstractAssistantPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'abstractAssistant';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.abstractAssistant.name'); }
    public function getDescription() { return __('plugins.generic.abstractAssistant.description'); }
    public function getInstallMigration() { return new Drafts(); }

    public function cfg(int $cid): array
    {
        $glossary = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->getSetting($cid, 'glossary')) as $line) {
            if (!str_contains($line, '=')) continue;
            [$a, $b] = array_map('trim', explode('=', $line, 2));
            if ($a !== '' && $b !== '') $glossary[$a] = $b;
        }
        return [
            'apiKey' => (string) $this->getSetting($cid, 'apiKey'),
            'model' => (string) ($this->getSetting($cid, 'model') ?: 'claude-sonnet-5-5'),
            'endpoint' => (string) ($this->getSetting($cid, 'endpoint') ?: 'https://api.anthropic.com/v1/messages'),
            'field' => (string) ($this->getSetting($cid, 'field') ?: 'Quranic and Islamic studies'),
            'glossaryRaw' => (string) $this->getSetting($cid, 'glossary'),
            'glossary' => $glossary,
        ];
    }

    public function canUse($request): bool
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
        $handler = new AssistantHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canUse($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['abstractAssistant'] = [
            'name' => __('plugins.generic.abstractAssistant.menu'),
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
        array_unshift($actions, new LinkAction('aaOpen', new RedirectAction($url), __('plugins.generic.abstractAssistant.open')));
        return $actions;
    }
}
