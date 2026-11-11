<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/autoAnnounce/AutoAnnouncePlugin.php
 * «الإعلان الآلي» — ينشئ إعلانًا في الموقع تلقائيًا عند صدور كل عدد (بفهرس محتوياته وروابطه)، واختياريًا عند نشر كل بحث،
 * ويولّد نصوصًا جاهزة للنشر في X ولينكدإن وواتساب وتيليجرام، مع سجل يمنع التكرار.
 */
namespace APP\plugins\generic\tadabburSuite\m\autoAnnounce;

use APP\core\Application;
use APP\plugins\generic\tadabburSuite\m\autoAnnounce\classes\Announcer;
use APP\plugins\generic\tadabburSuite\m\autoAnnounce\pages\AnnounceHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;
use PKP\plugins\Hook;

class AutoAnnouncePlugin extends ModulePlugin
{
    public const PAGE = 'autoAnnounce';
    public const ICON = 'Announcement';

    public function handler(): object { return new AnnounceHandler($this); }
    public function getInstallMigration() { return new Announcer(); }

    protected function hooks(): void
    {
        Hook::add('IssueGridHandler::publishIssue', $this->onIssue(...), Hook::SEQUENCE_LAST);
        Hook::add('Publication::publish', $this->onPublication(...), Hook::SEQUENCE_LAST);
    }

    public function onIssue(string $hook, array $args): bool
    {
        try {
            $issue = $args[0]; $request = Application::get()->getRequest(); $ctx = $request->getContext();
            if ($ctx && (string) $this->cfg((int) $ctx->getId(), 'autoIssue', '1') === '1') (new Announcer())->issue($this, $ctx, $request, $issue);
        } catch (\Throwable $e) { error_log('[autoAnnounce] issue: ' . $e->getMessage()); }
        return false;
    }

    public function onPublication(string $hook, array $args): bool
    {
        try {
            $pub = $args[0]; $sub = $args[2] ?? null; $request = Application::get()->getRequest(); $ctx = $request->getContext();
            if (!$ctx || !$sub || (string) $this->cfg((int) $ctx->getId(), 'autoArticle', '0') !== '1') return false;
            // لا نعلن عن البحث منفردًا إن كان ضمن عدد يصدر الآن (يكفي إعلان العدد)
            (new Announcer())->article($this, $ctx, $request, $sub);
        } catch (\Throwable $e) { error_log('[autoAnnounce] article: ' . $e->getMessage()); }
        return false;
    }
}
