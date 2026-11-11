<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/metadataQuality/MetadataQualityPlugin.php
 *
 * «جودة البيانات الوصفية وجاهزية الفهرسة» — Metadata Quality & Indexing Readiness for OJS 3.5
 *  - فحص كل بحث قبل النشر وبعده (ثنائية اللغة، المؤلفون، الانتماء، ORCID، DOI، الترخيص، المراجع، الملفات…)
 *  - قائمة جاهزية المجلة لمعايير Scopus وWeb of Science وDOAJ وCrossref
 *  - بوابة نشر اختيارية تمنع نشر بحث ينقصه حقل إلزامي (Publication::validatePublish)
 *  - تصدير CSV لتقارير الجودة
 */
namespace APP\plugins\generic\tadabburSuite\m\metadataQuality;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\metadataQuality\classes\QualityChecker;
use APP\plugins\generic\tadabburSuite\m\metadataQuality\pages\QualityHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class MetadataQualityPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'metadataQuality';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
            Hook::add('Publication::validatePublish', $this->onValidatePublish(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.metadataQuality.name'); }
    public function getDescription() { return __('plugins.generic.metadataQuality.description'); }

    /** الإعدادات مع القيم الافتراضية */
    public function cfg(int $contextId, $context = null): array
    {
        $locales = $this->getSetting($contextId, 'locales');
        if (!$locales) {
            $supported = $context ? (array) $context->getSupportedSubmissionLocales() : ['ar', 'en'];
            $locales = array_values(array_unique(array_merge(array_intersect(['ar', 'en'], $supported) ?: [$supported[0] ?? 'en'], ['en'])));
        }
        return [
            'locales' => array_values((array) $locales),
            'minAbstractWords' => (int) ($this->getSetting($contextId, 'minAbstractWords') ?: 100),
            'minKeywords' => (int) ($this->getSetting($contextId, 'minKeywords') ?: 3),
            'requireOrcid' => (string) ($this->getSetting($contextId, 'requireOrcid') ?: 'corresponding'),
            'exemptSections' => array_map('intval', (array) ($this->getSetting($contextId, 'exemptSections') ?: [])),
            'gate' => (string) ($this->getSetting($contextId, 'gate') ?: 'off'),
            'gateSkip' => (array) ($this->getSetting($contextId, 'gateSkip') ?: ['doi', 'issue']),
        ];
    }

    public function canView($request): bool
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
        $handler = new QualityHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['metadataQuality'] = [
            'name' => __('plugins.generic.metadataQuality.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Statistics',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    /** بوابة النشر: يمنع النشر عند نقص حقل إلزامي (إن فُعّلت) */
    public function onValidatePublish(string $hookName, array $args): bool
    {
        $errors = &$args[0]; $publication = $args[1]; $submission = $args[2];
        $context = Application::get()->getRequest()->getContext()
            ?? app()->get('context')->get((int) $submission->getData('contextId'));
        if (!$context) return false;
        $cfg = $this->cfg((int) $context->getId(), $context);
        if ($cfg['gate'] !== 'block') return false;
        $publication = \APP\facades\Repo::publication()->get((int) $publication->getId()) ?? $publication;
        $res = (new QualityChecker($cfg))->checkPublication($publication, $submission, $context);
        $missing = [];
        foreach ($res['checks'] as $ch) {
            if ($ch['ok'] || $ch['level'] !== QualityChecker::REQUIRED || in_array($ch['id'], $cfg['gateSkip'], true)) continue;
            $missing[] = '• ' . __('plugins.generic.metadataQuality.check.' . $ch['id']) . ($ch['detail'] !== '' ? ' (' . $ch['detail'] . ')' : '');
        }
        if ($missing) {
            $errors['metadataQuality'] = __('plugins.generic.metadataQuality.gateError') . "\n" . implode("\n", $missing);
        }
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('mqOpen', new RedirectAction($url), __('plugins.generic.metadataQuality.open')));
        return $actions;
    }
}
