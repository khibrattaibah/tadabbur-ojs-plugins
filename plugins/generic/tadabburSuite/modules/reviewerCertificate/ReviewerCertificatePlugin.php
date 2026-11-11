<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerCertificate/ReviewerCertificatePlugin.php
 *
 * إضافة عامة (Generic) لنظام OJS 3.5: شهادات المحكّمين الموثّقة + حزمة ملفات التحكيم.
 *  - إدارة: بند «شهادات المحكّمين والتحكيم» في القائمة الجانبية لمدير المجلة.
 *  - المحكّم: بند «شهاداتي وملفات التحكيم».
 *  - عام: صفحة التحقق /reviewerCertificate/verify/{code} التي يفتحها رمز QR.
 * سبب كونها Generic: إضافات Import/Export لا تُحمَّل في الصفحات العامة، فتفشل روابط QR (404).
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerCertificate;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\CertificateMigration;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\pages\CertificateHandler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class ReviewerCertificatePlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'reviewerCertificate';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName()
    {
        return 'شهادات المحكّمين وحزمة التحكيم — Reviewer Certificates & Review Kit';
    }

    public function getDescription()
    {
        return 'شهادات شكر للمحكّمين برقم فريد ورمز QR وصفحة تحقق عامة وملف PDF يُرسل للبريد المسجل، '
            . 'مع خطابات ونماذج التحكيم (الدعوة، النتيجة، التقويم، طلب التعديل، مصفوفة استجابة الباحث). — تطوير: مصطفى محمود — مكتب خبرات طيبة للبحوث والدراسات (Moustafa Mahmoud · github.com/mostafamr2011 · khibrattaibah.com)';
    }

    public function getInstallMigration()
    {
        return new CertificateMigration();
    }

    /** الإعدادات بعد استكمال القيم الفارغة من بيانات المجلة */
    public function cfg($request = null): array
    {
        $cfg = require __DIR__ . '/config.php';
        $request ??= Application::get()->getRequest();
        $context = $request?->getContext();
        if ($context) {
            if (empty($cfg['journal_name'])) $cfg['journal_name'] = (string) ($context->getName('ar') ?: $context->getLocalizedName());
            if (empty($cfg['platform_url'])) $cfg['platform_url'] = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $context->getPath());
            if (empty($cfg['journal_prefix'])) $cfg['journal_prefix'] = $context->getPath();
        }
        return $cfg;
    }

    public function isManager($request): bool
    {
        $user = $request->getUser(); $context = $request->getContext();
        if (!$user || !$context) return false;
        return $user->hasRole([Role::ROLE_ID_MANAGER], (int) $context->getId())
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID);
    }

    public function isReviewer($request): bool
    {
        $user = $request->getUser(); $context = $request->getContext();
        return $user && $context && $user->hasRole([Role::ROLE_ID_REVIEWER], (int) $context->getId());
    }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== self::PAGE) return false;
        $handler = &$args[3];
        $handler = new CertificateHandler($this);
        return true;
    }

    /** بنود القائمة الجانبية في لوحة OJS */
    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$request->getUser()) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $page = $request->getRequestedPage(); $op = $request->getRequestedOp();
        $url = fn (string $o) => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, $o);
        if ($this->isManager($request)) {
            $menu['reviewerCertificates'] = [
                'name' => 'شهادات المحكّمين والتحكيم', 'url' => $url('index'), 'icon' => 'Complete',
                'isCurrent' => $page === self::PAGE && $op !== 'mine',
            ];
        }
        if ($this->isReviewer($request)) {
            $menu['myReviewerCertificates'] = [
                'name' => 'شهاداتي وملفات التحكيم', 'url' => $url('mine'), 'icon' => 'Complete',
                'isCurrent' => $page === self::PAGE && $op === 'mine',
            ];
        }
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('rcManage', new RedirectAction($url), 'فتح لوحة الشهادات'));
        return $actions;
    }
}
