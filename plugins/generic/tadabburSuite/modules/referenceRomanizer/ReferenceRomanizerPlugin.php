<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/referenceRomanizer/ReferenceRomanizerPlugin.php
 *
 * «مساعد المراجع والرومنة» — Reference Romanizer for OJS 3.5
 *  - يكتشف المراجع غير اللاتينية في كل بحث، ويقترح رومنتها (ALA-LC أو مبسطة) وترجمة عناوينها.
 *  - محرك مدمج يعمل دون إنترنت، ومساعد اختياري عبر Claude API.
 *  - يطبّق النتيجة على قائمة المراجع في OJS (فتصل إلى Crossref وScopus بحروف لاتينية)،
 *    ويحتفظ بالأصل العربي ويعرضه في صفحة البحث، مع إمكان الاستعادة.
 */
namespace APP\plugins\generic\tadabburSuite\m\referenceRomanizer;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes\Store;
use APP\plugins\generic\tadabburSuite\m\referenceRomanizer\pages\RomanizerHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class ReferenceRomanizerPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'referenceRomanizer';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
            Hook::add('Templates::Article::Main', $this->onArticleMain(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.referenceRomanizer.name'); }
    public function getDescription() { return __('plugins.generic.referenceRomanizer.description'); }
    public function getInstallMigration() { return new Store(); }

    public function cfg(int $contextId): array
    {
        return [
            'style' => (string) ($this->getSetting($contextId, 'style') ?: 'alalc'),
            'suffix' => (string) ($this->getSetting($contextId, 'suffix') ?? '(in Arabic)') ?: '',
            'showOriginal' => $this->getSetting($contextId, 'showOriginal') === null ? true : (bool) $this->getSetting($contextId, 'showOriginal'),
            'apiKey' => (string) $this->getSetting($contextId, 'apiKey'),
            'model' => (string) ($this->getSetting($contextId, 'model') ?: 'claude-sonnet-5-5'),
            'endpoint' => (string) ($this->getSetting($contextId, 'endpoint') ?: 'https://api.anthropic.com/v1/messages'),
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
        $handler = new RomanizerHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canUse($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['referenceRomanizer'] = [
            'name' => __('plugins.generic.referenceRomanizer.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Catalog',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    /** يعرض المراجع العربية الأصلية تحت المراجع المرومنة في صفحة البحث */
    public function onArticleMain(string $hookName, array $args): bool
    {
        $smarty = $args[1]; $output = &$args[2];
        $publication = $smarty->getTemplateVars('publication');
        $context = Application::get()->getRequest()->getContext();
        if (!$publication || !$context || !$this->cfg((int) $context->getId())['showOriginal']) return false;
        try {
            $row = (new Store())->get((int) $publication->getId());
        } catch (\Throwable $e) {
            return false;
        }
        if (!$row || !(int) $row->applied || trim((string) $row->original_raw) === '') return false;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $row->original_raw))));
        $smarty->assign('rrOriginal', $lines);
        $output .= $smarty->fetch($this->getTemplateResource('originalRefs.tpl'));
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('rrOpen', new RedirectAction($url), __('plugins.generic.referenceRomanizer.open')));
        return $actions;
    }
}
