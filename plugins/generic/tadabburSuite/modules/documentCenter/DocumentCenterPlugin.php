<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/documentCenter/DocumentCenterPlugin.php
 *
 * «مركز المستندات»: مكان واحد لكل ما تصدره المجلة لمحكّميها وباحثيها — شهادات المحكّمين، وخطابات الشكر،
 * ونماذج التحكيم، وخطابات الاستلام والقبول والنشر والاعتذار، وشهادات شكر الباحثين، ونسخ تقارير التحكيم —
 * تُصدر وتُرسل بالبريد مع اختيار اللقب، وتظهر للمحكّم في «نماذج التحكيم» وللباحث في «ملفاتي» بحسب ما تقرره الإدارة.
 */
namespace APP\plugins\generic\tadabburSuite\m\documentCenter;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Catalog;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Delivery;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Store;
use APP\plugins\generic\tadabburSuite\m\documentCenter\pages\DocsHandler;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\plugins\Hook;
use PKP\security\Role;

class DocumentCenterPlugin extends ModulePlugin
{
    public const PAGE = 'documents';
    public const ICON = 'FileText';

    public function prefix(): string { return 'plugins.generic.documentCenter'; }
    public function handler(): object { return new DocsHandler($this); }
    public function getInstallMigration() { return new Store(); }

    protected function hooks(): void
    {
        Hook::add('Decision::add', $this->onDecision(...));
        Hook::add('ReviewAssignment::edit', $this->onReviewEdit(...));
    }

    public function isReviewer($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        return $u && $c && DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $c->getId())->where('ra.reviewer_id', $u->getId())->where('ra.cancelled', 0)->exists();
    }

    public function isAuthor($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        return $u && $c && (bool) Catalog::authorSubmissions((int) $c->getId(), (int) $u->getId());
    }

    /** ثلاثة بنود: المحرر (المركز)، والمحكّم (نماذج التحكيم)، والباحث (ملفاتي) — الأخيران خارج مجموعة «أدوات تدبر» ليظهرا مباشرة */
    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$request->getUser()) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $url = fn ($op) => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, $op);
        $here = $request->getRequestedPage() === self::PAGE; $op = $request->getRequestedOp();
        if ($this->isEditor($request)) $menu[self::PAGE] = ['name' => __('plugins.generic.documentCenter.menu'), 'url' => $url('index'), 'icon' => self::ICON, 'isCurrent' => $here && !in_array($op, ['mine', 'author'], true)];
        if ($this->isReviewer($request)) $menu['tdbTop_reviewerDocs'] = ['name' => __('plugins.generic.documentCenter.menuReviewer'), 'url' => $url('mine'), 'icon' => 'FileText', 'isCurrent' => $here && $op === 'mine'];
        if ($this->isAuthor($request)) $menu['tdbTop_authorDocs'] = ['name' => __('plugins.generic.documentCenter.menuAuthor'), 'url' => $url('author'), 'icon' => 'FileText', 'isCurrent' => $here && $op === 'author'];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    // ---------------------------------------------------------------- الإرسال التلقائي
    private function auto(int $cid, string $type, $sub, ?object $review): void
    {
        $cfg = Catalog::cfg($this, $cid, $type);
        if (!$cfg['enabled'] || !$cfg['auto'] || Catalog::why($type, $sub, $review) !== null) return;
        $request = Application::get()->getRequest();
        $context = $request->getContext() ?: Application::getContextDAO()->getById($cid);
        if (!$context) return;
        $dl = new Delivery($this, $request, $context);
        if ($dl->store->current($cid, $type, (int) $sub->getId(), $review ? (int) $review->review_id : null)) return;   // صدر من قبل
        $doc = $dl->issue($type, $sub, $review, ['lang' => $cfg['lang'], 'release' => true, 'by' => (int) ($request->getUser()?->getId() ?? 0)]);
        $dl->send($doc, $sub, $dl->defaultRecipients($doc, $sub));
    }

    public function onDecision(string $hookName, array $args): bool
    {
        try {
            $dec = $args[0] ?? null;
            if (!$dec) return false;
            $sub = Repo::submission()->get((int) $dec->getData('submissionId'));
            if (!$sub) return false;
            $cid = (int) $sub->getData('contextId'); $d = (int) $dec->getData('decision'); $sid = (int) $sub->getId();
            if (!in_array($d, [2, 17, 6, 8], true)) return false;
            // الخطاف يُستدعى قبل أن يحدّث OJS حالة البحث؛ فنؤجل التنفيذ إلى نهاية الطلب
            register_shutdown_function(function () use ($cid, $d, $sid) {
                try {
                    $fresh = Repo::submission()->get($sid);
                    if (!$fresh) return;
                    if (in_array($d, [6, 8], true)) $this->auto($cid, 'au_apology', $fresh, null);
                    else { $this->auto($cid, 'au_acceptance', $fresh, null); $this->auto($cid, 'au_thanks', $fresh, null); }
                } catch (\Throwable $e) { error_log('[documentCenter] auto decision (deferred): ' . $e->getMessage()); }
            });
        } catch (\Throwable $e) { error_log('[documentCenter] auto decision: ' . $e->getMessage()); }
        return false;
    }

    public function onReviewEdit(string $hookName, array $args): bool
    {
        try {
            [$new, $old] = [$args[0] ?? null, $args[1] ?? null];
            if (!$new || !$new->getData('dateCompleted') || ($old && $old->getData('dateCompleted'))) return false;
            $sub = Repo::submission()->get((int) $new->getData('submissionId'));
            if (!$sub) return false;
            $cid = (int) $sub->getData('contextId');
            $row = Catalog::review($cid, (int) $new->getId());
            if (!$row) return false;
            foreach (['rev_thanks', 'rev_certificate'] as $t) $this->auto($cid, $t, $sub, $row);
        } catch (\Throwable $e) { error_log('[documentCenter] auto review: ' . $e->getMessage()); }
        return false;
    }
}
