<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/integrityLog/IntegrityLogPlugin.php
 *
 * «سجل النزاهة البحثية» — Research Integrity Log for OJS 3.5
 *  - سجل سري لحالات النزاهة (الاستلال، النشر المكرر، نزاعات التأليف، التلفيق، سوء سلوك المحكّم، مصانع البحوث…)
 *    مع خطوات COPE الموصى بها لكل نوع، وخط زمني موثّق لكل إجراء، وحالة ونتيجة.
 *  - إشعارات عامة مرتبطة بالبحث: تصحيح، تعبير عن قلق، سحب، إزالة — تظهر شريطًا بارزًا أعلى صفحة البحث
 *    وقائمةً في صفحة عامة /integrity/notices.
 */
namespace APP\plugins\generic\tadabburSuite\m\integrityLog;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\integrityLog\classes\Store;
use APP\plugins\generic\tadabburSuite\m\integrityLog\pages\IntegrityHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class IntegrityLogPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'integrity';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
            Hook::add('TemplateManager::display', $this->onDisplay(...));
            Hook::add('Templates::Article::Main', $this->onArticleMain(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.integrityLog.name'); }
    public function getDescription() { return __('plugins.generic.integrityLog.description'); }
    public function getInstallMigration() { return new Store(); }

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
        $handler = new IntegrityHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['integrityLog'] = [
            'name' => __('plugins.generic.integrityLog.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Lock',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public static function localized(string $json, ?string $fallback = null): string
    {
        $v = json_decode($json, true);
        if (!is_array($v)) return $json;
        foreach ([Locale::getLocale(), $fallback, 'ar', 'en'] as $l) if ($l && trim((string) ($v[$l] ?? '')) !== '') return (string) $v[$l];
        foreach ($v as $t) if (trim((string) $t) !== '') return (string) $t;
        return '';
    }

    private function noticesFor($submission): array
    {
        try {
            return (new Store())->notices((int) $submission->getData('contextId'), (int) $submission->getId(), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** شريط بارز أعلى صفحة البحث */
    public function onDisplay(string $hookName, array $args): bool
    {
        $template = $args[1] ?? '';
        if ($template !== 'frontend/pages/article.tpl') return false;
        $tm = $args[0];
        $tm->registerFilter('output', function ($output, $tm) {
            if (str_contains($output, 'id="integrity-banner"')) return $output;
            $submission = $tm->getTemplateVars('article');
            if (!$submission) return $output;
            $notices = $this->noticesFor($submission);
            if (!$notices) return $output;
            $n = $notices[0];
            $colors = ['retraction' => ['#7a1010', '#fdecec'], 'removal' => ['#7a1010', '#fdecec'], 'concern' => ['#7a4d00', '#fff4dc'], 'correction' => ['#0b4f4a', '#e8f4f2']];
            [$fg, $bg] = $colors[$n->notice_type] ?? $colors['correction'];
            $text = self::localized((string) $n->body, (string) $submission->getData('locale'));
            $banner = '<div id="integrity-banner" role="alert" style="border:2px solid ' . $fg . ';background:' . $bg . ';color:' . $fg . ';border-radius:8px;padding:12px 16px;margin:0 0 18px;font-size:15px;line-height:1.7">'
                . '<strong style="font-size:17px">' . htmlspecialchars(__('plugins.generic.integrityLog.banner.' . $n->notice_type)) . '</strong>'
                . ' <span style="opacity:.8">(' . htmlspecialchars((string) $n->date_notice) . ')</span>'
                . '<div style="color:#222;margin-top:4px">' . nl2br(htmlspecialchars(mb_strimwidth(strip_tags($text), 0, 400, '…'))) . '</div>'
                . (count($notices) > 1 || mb_strlen($text) > 400 ? '<a href="#integrity-notices" style="color:' . $fg . ';font-weight:700">' . htmlspecialchars(__('plugins.generic.integrityLog.banner.more')) . '</a>' : '')
                . '</div>';
            foreach (['<article class="obj_article_details">', '<div class="page page_article">'] as $anchor) {
                $pos = strpos($output, $anchor);
                if ($pos !== false) return substr_replace($output, $anchor . $banner, $pos, strlen($anchor));
            }
            return $output;
        });
        return false;
    }

    /** قائمة الإشعارات كاملة أسفل البحث */
    public function onArticleMain(string $hookName, array $args): bool
    {
        $smarty = $args[1]; $output = &$args[2];
        $submission = $smarty->getTemplateVars('article');
        if (!$submission) return false;
        $notices = $this->noticesFor($submission);
        if (!$notices) return false;
        $items = [];
        foreach ($notices as $n) {
            $items[] = ['type' => __('plugins.generic.integrityLog.notice.' . $n->notice_type), 'date' => $n->date_notice, 'doi' => $n->notice_doi,
                'text' => self::localized((string) $n->body, (string) $submission->getData('locale'))];
        }
        $smarty->assign(['ilNotices' => $items, 'ilTitle' => __('plugins.generic.integrityLog.publicSection')]);
        $output .= $smarty->fetch($this->getTemplateResource('articleNotices.tpl'));
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('ilOpen', new RedirectAction($url), __('plugins.generic.integrityLog.open')));
        return $actions;
    }
}
