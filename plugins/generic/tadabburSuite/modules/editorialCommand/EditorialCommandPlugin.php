<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialCommand/EditorialCommandPlugin.php
 *
 * «غرفة القيادة التحريرية» — Editorial Command Center for OJS 3.5
 *  - لوحة واحدة لكل البحوث النشطة: المرحلة، والأيام فيها، ومن ينتظر الآن، والمتأخر عن المهلة، والخطوة التالية.
 *  - أعباء المحررين ومتأخراتهم، ومؤشرات سريعة، وتصفية، وتصدير CSV.
 *  - تذكير المؤلف أو المحرر برسالة قابلة للتعديل تُسجَّل في سجل البحث.
 *  - ملخص يومي بالبريد لكل محرر بما ينتظره (يعمل دون Cron: يُشغَّل مرة يوميًا مع أول زيارة للوحة).
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialCommand;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\editorialCommand\classes\Board;
use APP\plugins\generic\tadabburSuite\m\editorialCommand\classes\Digest;
use APP\plugins\generic\tadabburSuite\m\editorialCommand\pages\CommandHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class EditorialCommandPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'editorialCommand';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.editorialCommand.name'); }
    public function getDescription() { return __('plugins.generic.editorialCommand.description'); }

    /** المهل بالأيام */
    public function cfg(int $cid): array
    {
        $g = fn ($k, $d) => ($v = $this->getSetting($cid, $k)) === null || $v === '' ? $d : $v;
        return [
            'sla' => [
                'assign' => (int) $g('slaAssign', 3), 'desk' => (int) $g('slaDesk', 7), 'invite' => (int) $g('slaInvite', 7),
                'decision' => (int) $g('slaDecision', 10), 'revision' => (int) $g('slaRevision', 30), 'copyedit' => (int) $g('slaCopyedit', 21),
                'production' => (int) $g('slaProduction', 14), 'minReviewers' => (int) $g('minReviewers', 2),
            ],
            'digest' => (bool) $g('digest', false),
            'digestManagers' => (bool) $g('digestManagers', true),
            'lastDigest' => (string) $g('lastDigest', ''),
        ];
    }

    public function board(int $cid, ?string $locale = null): array
    {
        return (new Board($cid, $this->cfg($cid)['sla']))->rows($locale);
    }

    /** الصفوف التي يحق لهذا المستخدم رؤيتها (سياق + إسناد) */
    public function visible($request, array $rows): array
    {
        return \APP\plugins\generic\tadabburSuite\classes\kit\Access::filterRows($request, $rows, 'id');
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
        $handler = new CommandHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return false;
        // الملخص اليومي (مرة واحدة في اليوم، مع أول زيارة لأي مستخدم للوحة)
        try {
            $cfg = $this->cfg((int) $context->getId());
            if (!$this->readOnly() && $cfg['digest'] && $cfg['lastDigest'] !== date('Y-m-d') && (int) date('G') >= 6) {
                $this->updateSetting((int) $context->getId(), 'lastDigest', date('Y-m-d'), 'string');
                (new Digest($this))->send($context);
            }
        } catch (\Throwable $e) {
            error_log('[editorialCommand] digest: ' . $e->getMessage());
        }
        if (!$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu = ['editorialCommand' => [
            'name' => __('plugins.generic.editorialCommand.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Dashboard',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ]] + $menu;
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('ecOpen', new RedirectAction($url), __('plugins.generic.editorialCommand.open')));
        return $actions;
    }
}
