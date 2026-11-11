<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialCommand/classes/Digest.php
 * ملخص يومي لكل محرر بما ينتظر قراره، ولمديري المجلة بكل المتأخرات.
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialCommand\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPApplication;
use PKP\mail\Mailable;
use PKP\security\Role;

class Digest
{
    public function __construct(private $plugin) {}

    /** @return int عدد الرسائل المرسلة */
    public function send($context): int
    {
        $cid = (int) $context->getId(); $cfg = $this->plugin->cfg($cid);
        $locale = (string) $context->getPrimaryLocale();
        $rows = $this->plugin->board($cid, $locale);
        if (!$rows) return 0;
        $byEditor = [];
        foreach ($rows as $r) if ($r['party'] === 'editor') foreach ($r['editorIds'] as $e) $byEditor[$e][] = $r;
        $sent = 0;
        $url = fn ($id) => Application::get()->getDispatcher()->url(Application::get()->getRequest(), PKPApplication::ROUTE_PAGE, $context->getPath(), 'dashboard', 'editorial', null, ['workflowSubmissionId' => $id]);
        $board = Application::get()->getDispatcher()->url(Application::get()->getRequest(), PKPApplication::ROUTE_PAGE, $context->getPath(), 'editorialCommand', 'index');
        $fmt = function (array $list) use ($url, $locale) {
            $h = '<ul>';
            foreach ($list as $r) {
                $h .= '<li>' . ($r['overdue'] ? '⚠ ' : '') . '<a href="' . htmlspecialchars($url($r['id'])) . '">#' . $r['id'] . ' ' . htmlspecialchars(mb_strimwidth($r['title'], 0, 90, '…')) . '</a> — '
                    . htmlspecialchars(__('plugins.generic.editorialCommand.wait.' . $r['waiting'], [], $locale)) . ' (' . __('plugins.generic.editorialCommand.daysN', ['n' => $r['waitDays']], $locale) . ')</li>';
            }
            return $h . '</ul>';
        };
        foreach ($byEditor as $uid => $list) {
            $user = Repo::user()->get((int) $uid);
            if (!$user || !$user->getEmail()) continue;
            $late = count(array_filter($list, fn ($r) => $r['overdue']));
            $this->mail($context, $user->getEmail(), $user->getFullName(), __('plugins.generic.editorialCommand.digest.subject', ['n' => count($list), 'late' => $late], $locale),
                '<p>' . __('plugins.generic.editorialCommand.digest.intro', ['name' => htmlspecialchars($user->getFullName())], $locale) . '</p>' . $fmt($list) . '<p><a href="' . htmlspecialchars($board) . '">' . __('plugins.generic.editorialCommand.digest.open', [], $locale) . '</a></p>');
            $sent++;
        }
        if ($cfg['digestManagers']) {
            $late = array_values(array_filter($rows, fn ($r) => $r['overdue']));
            if ($late) {
                $managers = Repo::user()->getCollector()->filterByContextIds([$cid])->filterByRoleIds([Role::ROLE_ID_MANAGER])->getMany();
                foreach ($managers as $m) {
                    if (!$m->getEmail()) continue;
                    $this->mail($context, $m->getEmail(), $m->getFullName(), __('plugins.generic.editorialCommand.digest.mgrSubject', ['n' => count($late)], $locale),
                        '<p>' . __('plugins.generic.editorialCommand.digest.mgrIntro', [], $locale) . '</p>' . $fmt($late) . '<p><a href="' . htmlspecialchars($board) . '">' . __('plugins.generic.editorialCommand.digest.open', [], $locale) . '</a></p>');
                    $sent++;
                }
            }
        }
        return $sent;
    }

    private function mail($context, string $to, string $name, string $subject, string $html): void
    {
        $m = new Mailable([$context]);
        $m->from((string) $context->getData('contactEmail'), (string) $context->getData('contactName'))->to($to, $name)->subject($subject)
            ->body('<div dir="auto" style="font-family:Tahoma,Arial,sans-serif;line-height:1.7">' . $html . '</div>');
        Mail::send($m);
    }
}
