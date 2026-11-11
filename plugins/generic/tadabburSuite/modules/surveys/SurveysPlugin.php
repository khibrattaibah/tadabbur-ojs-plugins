<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/surveys/SurveysPlugin.php
 * «الاستبانات»: استبانات رضا الباحثين والمحكّمين والقرّاء، تُرسل تلقائيًا عند القرار النهائي واكتمال التحكيم والنشر،
 * وتُعبّأ برابط شخصي دون تسجيل دخول، مع تقارير فورية ومؤشر رضا وتصدير CSV.
 */
namespace APP\plugins\generic\tadabburSuite\m\surveys;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;
use APP\plugins\generic\tadabburSuite\m\surveys\classes\Engine;
use APP\plugins\generic\tadabburSuite\m\surveys\classes\Store;
use APP\plugins\generic\tadabburSuite\m\surveys\pages\SurveyHandler;
use PKP\plugins\Hook;

class SurveysPlugin extends ModulePlugin
{
    public const PAGE = 'surveys';
    public const ICON = 'Stats';

    public function handler(): object { return new SurveyHandler($this); }
    public function getInstallMigration() { return new Store(); }

    protected function hooks(): void
    {
        Hook::add('Decision::add', $this->onDecision(...));
        Hook::add('ReviewAssignment::edit', $this->onReview(...));
        Hook::add('Publication::publish', $this->onPublish(...), Hook::SEQUENCE_LAST);
    }

    private function ctx(int $cid) { $r = Application::get()->getRequest(); return [$r, $r->getContext() ?: Application::getContextDAO()->getById($cid)]; }

    private function inviteAuthors(int $cid, string $trigger, int $sid): void
    {
        [$request, $context] = $this->ctx($cid); if (!$context) return;
        $st = new Store();
        foreach ($st->byTrigger($cid, $trigger) as $s) foreach (Engine::authors($sid) as [$uid, $email, $name]) Engine::send($request, $context, $st, $s, $uid, $email, $name, $sid, null);
    }

    public function onDecision(string $hook, array $args): bool
    {
        try {
            $d = $args[0] ?? null; if (!$d || !in_array((int) $d->getData('decision'), [2, 17, 6, 8], true)) return false;
            $sub = Repo::submission()->get((int) $d->getData('submissionId')); if (!$sub) return false;
            $cid = (int) $sub->getData('contextId'); $sid = (int) $sub->getId();
            register_shutdown_function(function () use ($cid, $sid) {
                try { $this->inviteAuthors($cid, 'decision', $sid); } catch (\Throwable $e) { error_log('[surveys] decision (deferred): ' . $e->getMessage()); }
            });
        } catch (\Throwable $e) { error_log('[surveys] decision: ' . $e->getMessage()); }
        return false;
    }

    public function onReview(string $hook, array $args): bool
    {
        try {
            [$new, $old] = [$args[0] ?? null, $args[1] ?? null];
            if (!$new || !$new->getData('dateCompleted') || ($old && $old->getData('dateCompleted'))) return false;
            $sub = Repo::submission()->get((int) $new->getData('submissionId')); if (!$sub) return false;
            $cid = (int) $sub->getData('contextId'); [$request, $context] = $this->ctx($cid); if (!$context) return false;
            $u = Repo::user()->get((int) $new->getData('reviewerId'), true); if (!$u) return false;
            $st = new Store();
            foreach ($st->byTrigger($cid, 'review') as $s) Engine::send($request, $context, $st, $s, (int) $u->getId(), (string) $u->getEmail(), $u->getFullName(), (int) $sub->getId(), (int) $new->getId());
        } catch (\Throwable $e) { error_log('[surveys] review: ' . $e->getMessage()); }
        return false;
    }

    public function onPublish(string $hook, array $args): bool
    {
        try { $sub = $args[2] ?? null; if ($sub) $this->inviteAuthors((int) $sub->getData('contextId'), 'publish', (int) $sub->getId()); }
        catch (\Throwable $e) { error_log('[surveys] publish: ' . $e->getMessage()); }
        return false;
    }
}
