<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tasksBoard/TasksBoardPlugin.php
 * «المهام والتكليفات» — لوحة مهام لفريق التحرير (للعمل/قيد التنفيذ/منجز): مكلَّف وموعد وأولوية وربط اختياري ببحث،
 * وتعليقات، وإشعار المكلَّف بالبريد، وإبراز المتأخر.
 */
namespace APP\plugins\generic\tadabburSuite\m\tasksBoard;

use APP\plugins\generic\tadabburSuite\m\tasksBoard\classes\Store;
use APP\plugins\generic\tadabburSuite\m\tasksBoard\pages\TasksHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class TasksBoardPlugin extends ModulePlugin
{
    public const PAGE = 'tasksBoard';
    public const ICON = 'Checklist';

    public function handler(): object { return new TasksHandler($this); }
    public function getInstallMigration() { return new Store(); }

    protected function menuFor($request): ?array
    {
        if (!$this->isEditor($request)) return null;
        return ['menu', 'index'];
    }
}
