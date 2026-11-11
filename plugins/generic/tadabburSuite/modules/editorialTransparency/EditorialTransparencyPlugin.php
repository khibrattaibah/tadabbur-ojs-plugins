<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialTransparency/EditorialTransparencyPlugin.php
 *
 * «الشفافية التحريرية والإحصاءات» — Editorial Transparency for OJS 3.5
 * صفحة عامة ثنائية اللغة (/transparency) تعرض مؤشرات النشر والتحكيم المحسوبة آليًا من OJS:
 * معدلات القبول والرفض، أزمنة القرار والتحكيم والنشر، الإنتاج السنوي، تنوع المؤلفين الجغرافي، القراءات والتنزيلات،
 * مع بيان منهجي وواجهة JSON مفتوحة. تدعم مبادئ الشفافية لـ COPE/DOAJ/OASPA/WAME ومعايير Scopus وWoS.
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialTransparency;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\editorialTransparency\pages\TransparencyHandler;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class EditorialTransparencyPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'transparency';

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->onLoadHandler(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.editorialTransparency.name'); }
    public function getDescription() { return __('plugins.generic.editorialTransparency.description'); }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== self::PAGE) return false;
        $handler = &$args[3];
        $handler = new TransparencyHandler($this);
        return true;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index', null, ['refresh' => 1]);
        array_unshift($actions, new LinkAction('etpOpen', new RedirectAction($url), __('plugins.generic.editorialTransparency.open')));
        return $actions;
    }
}
