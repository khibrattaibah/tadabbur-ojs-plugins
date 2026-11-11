<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/boardVote/BoardVotePlugin.php
 * «القرار الجماعي» — تصويت هيئة التحرير على بحث أو مسألة: خيارات القرار أو خيارات مخصصة، ومهلة، ونِصاب،
 * واقتراع سري أو علني مع التعليل، ونتيجة موثّقة بمحضر قابل للطباعة.
 */
namespace APP\plugins\generic\tadabburSuite\m\boardVote;

use APP\plugins\generic\tadabburSuite\m\boardVote\classes\Store;
use APP\plugins\generic\tadabburSuite\m\boardVote\pages\VoteHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class BoardVotePlugin extends ModulePlugin
{
    public const PAGE = 'boardVote';
    public const ICON = 'Check';

    public function handler(): object { return new VoteHandler($this); }
    public function getInstallMigration() { return new Store(); }

    protected function menuFor($request): ?array
    {
        if ($this->isEditor($request)) return ['menu', 'index'];
        return (new Store())->forVoter((int) $request->getContext()->getId(), (int) $request->getUser()->getId()) ? ['myMenu', 'index'] : null;
    }
}
