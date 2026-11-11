<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/preReviewCheck/PreReviewCheckPlugin.php
 *
 * «الفحص الفني قبل التحكيم» — Technical Pre-Review Check for OJS 3.5
 *  - يفحص كل بحث مقدَّم آليًا لحظة تقديمه: البيانات الوصفية، وملف Word (الطول، إخفاء الهوية، التعليقات وتتبع التغييرات،
 *    العناوين الإلزامية، المراجع)، ويقرأ نتائج مدقّق الآيات والإقرارات إن وُجدت.
 *  - تقرير للمحرر يحدد ما يمنع الإحالة إلى التحكيم وما يُستحسن، ورسالة جاهزة ثنائية اللغة تُرسل للمؤلف وتُسجّل في سجل البريد.
 */
namespace APP\plugins\generic\tadabburSuite\m\preReviewCheck;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\preReviewCheck\classes\Checker;
use APP\plugins\generic\tadabburSuite\m\preReviewCheck\classes\Store;
use APP\plugins\generic\tadabburSuite\m\preReviewCheck\pages\CheckHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class PreReviewCheckPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'preReviewCheck';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
            Hook::add('Submission::edit', $this->onSubmissionEdit(...), Hook::SEQUENCE_LATE); // بعد مدقّق الآيات ليُضمَّن تقريره
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.preReviewCheck.name'); }
    public function getDescription() { return __('plugins.generic.preReviewCheck.description'); }
    public function getInstallMigration() { return new Store(); }

    public function cfg(int $cid): array
    {
        $g = fn ($k, $d) => ($v = $this->getSetting($cid, $k)) === null || $v === '' ? $d : $v;
        $headings = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $g('headings', "المقدمة\nالخاتمة")))));
        return [
            'autoRun' => (bool) $g('autoRun', true),
            'locales' => array_values(array_filter((array) $g('locales', ['ar', 'en']))),
            'titleMax' => (int) $g('titleMax', 25), 'absMin' => (int) $g('absMin', 120), 'absMax' => (int) $g('absMax', 300),
            'kwMin' => (int) $g('kwMin', 3), 'kwMax' => (int) $g('kwMax', 7),
            'wordsMin' => (int) $g('wordsMin', 5000), 'wordsMax' => (int) $g('wordsMax', 12000), 'refsMin' => (int) $g('refsMin', 10),
            'fileTypes' => array_values(array_filter(array_map('trim', explode(',', (string) $g('fileTypes', 'docx'))))),
            'orcid' => (string) $g('orcid', 'corresponding'),
            'blind' => (bool) $g('blind', true), 'checkDeclarations' => (bool) $g('checkDeclarations', true), 'romanRefs' => (bool) $g('romanRefs', true),
            'headings' => $headings, 'headingsRaw' => implode("\n", $headings),
        ];
    }

    /** @return array{id:int, error:?string} */
    public function runFor($submission, ?int $fileId, bool $auto): array
    {
        $cid = (int) $submission->getData('contextId');
        $context = app()->get('context')->get($cid);
        $res = (new Checker($this->cfg($cid)))->run($submission, $context, $fileId);
        $id = (new Store())->add(['context_id' => $cid, 'submission_id' => (int) $submission->getId(), 'submission_file_id' => $res['file']['id'] ?? null,
            'file_name' => mb_substr((string) ($res['file']['name'] ?? ''), 0, 250), 'auto' => $auto ? 1 : 0,
            'failed_req' => $res['stats']['failedReq'], 'failed_rec' => $res['stats']['failedRec'], 'result' => json_encode($res, JSON_UNESCAPED_UNICODE)]);
        return ['id' => $id, 'error' => null];
    }

    public function onSubmissionEdit(string $hookName, array $args): bool
    {
        [$new, $old] = [$args[0], $args[1]];
        try {
            if (!$old || (string) $old->getData('submissionProgress') === '' || (string) $new->getData('submissionProgress') !== '') return false;
            if (!$this->cfg((int) $new->getData('contextId'))['autoRun']) return false;
            $this->runFor(Repo::submission()->get((int) $new->getId()) ?? $new, null, true);
        } catch (\Throwable $e) {
            error_log('[preReviewCheck] auto-run failed: ' . $e->getMessage());
        }
        return false;
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
        $handler = new CheckHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canUse($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['preReviewCheck'] = [
            'name' => __('plugins.generic.preReviewCheck.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Checkbox',
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
        array_unshift($actions, new LinkAction('prcOpen', new RedirectAction($url), __('plugins.generic.preReviewCheck.open')));
        return $actions;
    }
}
