<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/quranVerifier/QuranVerifierPlugin.php
 *
 * «مدقّق الآيات والتخريج» — Quran & Hadith Citation Verifier for OJS 3.5
 *  - يستخرج الآيات المقتبسة من ملف البحث (Word/PDF) مع حواشيه، ويطابقها بنص المصحف (Tanzil)،
 *    ويكشف أخطاء النص والعزو (السورة/رقم الآية) ويقترح الصواب بالرسم العثماني.
 *  - يرصد عبارات التخريج الحديثي الناقصة (بلا رقم أو بلا حكم لغير الصحيحين) والأحاديث بلا تخريج.
 *  - تشغيل تلقائي عند تقديم البحث، وتقارير محفوظة لكل بحث، وأداة مفتوحة للباحثين قبل التقديم.
 */
namespace APP\plugins\generic\tadabburSuite\m\quranVerifier;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\classes\Runner;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\classes\Store;
use APP\plugins\generic\tadabburSuite\m\quranVerifier\pages\VerifierHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class QuranVerifierPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'quranVerifier';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
            Hook::add('Submission::edit', $this->onSubmissionEdit(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.quranVerifier.name'); }
    public function getDescription() { return __('plugins.generic.quranVerifier.description'); }
    public function getInstallMigration() { return new Store(); }

    public function cfg(int $contextId): array
    {
        $auto = $this->getSetting($contextId, 'autoRun');
        $tool = $this->getSetting($contextId, 'authorTool');
        return [
            'autoRun' => $auto === null ? true : (bool) $auto,
            'authorTool' => $tool === null ? true : (bool) $tool,
        ];
    }

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

    /** أداة الباحث: لأي مستخدم مسجّل في المجلة (مؤلف، محكّم…) إن فُعّلت */
    public function canUseTool($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        if (!$u || !$c) return false;
        if ($this->isEditor($request)) return true;
        return $this->cfg((int) $c->getId())['authorTool']
            && $u->hasRole([Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_READER], (int) $c->getId());
    }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== self::PAGE) return false;
        $handler = &$args[3];
        $handler = new VerifierHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canUseTool($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $editor = $this->isEditor($request);
        $menu['quranVerifier'] = [
            'name' => __($editor ? 'plugins.generic.quranVerifier.menu' : 'plugins.generic.quranVerifier.toolMenu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, $editor ? 'index' : 'tool'),
            'icon' => 'Book',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    /** تشغيل تلقائي لحظة إتمام المؤلف للتقديم (لا يعطّل التقديم إن فشل) */
    public function onSubmissionEdit(string $hookName, array $args): bool
    {
        [$new, $old] = [$args[0], $args[1]];
        try {
            if (!$old || (string) $old->getData('submissionProgress') === '' || (string) $new->getData('submissionProgress') !== '') return false;
            $cid = (int) $new->getData('contextId');
            if (!$this->cfg($cid)['autoRun']) return false;
            (new Runner($this))->runForSubmission($new, null, null, true);
        } catch (\Throwable $e) {
            error_log('[quranVerifier] auto-run failed: ' . $e->getMessage());
        }
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('qvOpen', new RedirectAction($url), __('plugins.generic.quranVerifier.open')));
        return $actions;
    }
}
