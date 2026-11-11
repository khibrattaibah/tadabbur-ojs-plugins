<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/TadabburSuitePlugin.php
 *
 * «حزمة تدبر» — إضافة واحدة تضم كل أدوات المجلة كوحدات (modules). تُرفع وتُحدَّث مرة واحدة،
 * وتتحكم في تفعيل كل وحدة، وتجمع قوائمها الجانبية تحت بند واحد، وتحفظ هوية المجلة المشتركة (التوقيع والختم والترويسة).
 */
namespace APP\plugins\generic\tadabburSuite;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use APP\plugins\generic\tadabburSuite\classes\SuiteMigration;
use APP\plugins\generic\tadabburSuite\pages\SuiteHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class TadabburSuitePlugin extends GenericPlugin
{
    public const PAGE = 'tadabburSuite';
    public const VERSION = '1.5.0';
    /** @var string[] مفاتيح عناصر القائمة قبل أن تضيف الوحدات عناصرها */
    private static array $baseMenu = [];

    public function register($category, $path, $mainContextId = null)
    {
        Modules::registerAutoloader();
        // parent::register تسجّل خطّاف Installer::postInstall الذي يشغّل getInstallMigration عند التثبيت/الترقية الرسمية
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || !$this->getEnabled($mainContextId)) return $success;

        Hook::add('LoadHandler', $this->onLoadHandler(...));
        Hook::add('TemplateManager::display', $this->registerUi(...));
        Hook::add('TemplateManager::setupBackendPage', $this->snapshotMenu(...), Hook::SEQUENCE_CORE);
        Hook::add('TemplateManager::setupBackendPage', $this->groupMenu(...), Hook::SEQUENCE_LAST);

        // لا DDL ولا كتابة إعدادات هنا: التحميل يقرأ فقط.
        // الوحدات قدرات داخلية: تُحمَّل مباشرة ولا تُسجَّل في PluginRegistry (فلا تصير منتجات منفصلة في OJS).
        $cid = $mainContextId ?? $this->getCurrentContextId();
        if ($cid) {
            foreach ($this->loadableModules((int) $cid) as $key) {
                $plugin = Modules::make($key);
                if (!$plugin) continue;
                $plugin->activate();
                if ($plugin->register('generic', $path . '/modules/' . $key, $mainContextId)) Modules::markLoaded($key, $plugin);
            }
        }
        return $success;
    }

    /**
     * الوحدات التي تُحمَّل لهذه المجلة: مفعّلة صراحة من المدير + مُصرَّح بإطلاقها + تبعياتها مفعّلة + نجح ترحيلها.
     * @return string[] مرتّبة بحيث تسبق التبعياتُ ما يعتمد عليها
     */
    public function loadableModules(int $cid): array
    {
        $ok = SuiteMigration::okModules();
        return array_values(array_filter(Modules::loadOrder($this->enabledModules($cid)), fn ($k) => in_array($k, $ok, true)));
    }

    /** يسجّل مُعدِّل الواجهة الموحّدة tdbui لقوالب الوحدات */
    public function registerUi(string $hookName, array $args): bool
    {
        $tm = $args[0];
        try {
            if (!isset($tm->registered_plugins['modifier']['tdbui'])) $tm->registerPlugin('modifier', 'tdbui', [\APP\plugins\generic\tadabburSuite\classes\Ui::class, 'modifier']);
        } catch (\Throwable $e) { error_log('[tadabburSuite] ui: ' . $e->getMessage()); }
        return false;
    }

    public function getDisplayName() { return __('plugins.generic.tadabburSuite.name'); }
    public function getDescription() { return __('plugins.generic.tadabburSuite.description'); }
    public function getInstallMigration() { return new SuiteMigration(); }

    /**
     * الوحدات التي فعّلها المدير لهذه المجلة. الافتراضي: لا شيء — لا تُفعَّل وحدة جديدة تلقائيًا بتحديث الحزمة.
     * @return string[]
     */
    public function enabledModules(int $cid): array
    {
        $v = (string) $this->getSetting($cid, 'enabledModules');
        return $v === '' ? [] : array_values(array_filter(explode(',', $v)));
    }

    public function setEnabledModules(int $cid, array $keys): void
    {
        $this->updateSetting($cid, 'enabledModules', implode(',', array_unique($keys)), 'string');
    }

    public function isManager($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        return $u && $c && ($u->hasRole([Role::ROLE_ID_MANAGER], (int) $c->getId()) || $u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID));
    }

    public function groupEnabled(int $cid): bool { return $this->getSetting($cid, 'groupMenu') !== false && $this->getSetting($cid, 'groupMenu') !== '0'; }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== self::PAGE) return false;
        $handler = &$args[3];
        $handler = new SuiteHandler($this);
        return true;
    }

    public function snapshotMenu(string $hookName, array $args = []): bool
    {
        $tm = TemplateManager::getManager(Application::get()->getRequest());
        self::$baseMenu = array_keys((array) $tm->getState('menu'));
        return false;
    }

    /** يجمع عناصر الوحدات (التي أضافتها بعد اللقطة، ورابطها يشير لصفحة إحدى الوحدات) تحت بند واحد */
    public function groupMenu(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        $c = $request->getContext(); $u = $request->getUser();
        if (!$c || !$u) return false;
        $cid = (int) $c->getId();
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $items = [];
        if ($this->isManager($request)) {
            $items['suite'] = ['name' => __('plugins.generic.tadabburSuite.menuManage'), 'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
                'isCurrent' => $request->getRequestedPage() === self::PAGE];
        }
        if ($this->groupEnabled($cid)) {
            $pages = [];
            foreach ($this->loadableModules($cid) as $key) {
                $m = Modules::all()[$key];
                if (!defined($m['class'] . '::PAGE')) continue;
                $pages[] = preg_quote(constant($m['class'] . '::PAGE'), '~');
            }
            $re = $pages ? '~(?:/|=)(?:' . implode('|', $pages) . ')(?:/|&|\?|$)~' : null;
            foreach ($menu as $k => $it) {
                if (in_array($k, self::$baseMenu, true) || str_starts_with((string) $k, 'tdbTop_') || !is_array($it) || !isset($it['url']) || !$re || !preg_match($re, (string) $it['url'])) continue;
                $items[$k] = ['name' => $it['name'], 'url' => $it['url'], 'isCurrent' => !empty($it['isCurrent'])];
                unset($menu[$k]);
            }
        }
        if (!$items) return false;
        $menu['tadabburSuite'] = ['name' => __('plugins.generic.tadabburSuite.menu'), 'icon' => 'Tools', 'submenu' => $items];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('suiteOpen', new RedirectAction($url), __('plugins.generic.tadabburSuite.openPanel')));
        return $actions;
    }
}
