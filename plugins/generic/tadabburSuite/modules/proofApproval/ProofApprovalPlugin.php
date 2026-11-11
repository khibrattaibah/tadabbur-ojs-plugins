<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/proofApproval/ProofApprovalPlugin.php
 * «اعتماد البروفة» — يرسل المحرر البروفة النهائية للمؤلف بمهلة محددة، فيعتمدها بإقرار موثّق أو يرسل قائمة تصويبات منظمة
 * (الصفحة، السطر، الخطأ، الصواب)، مع جولات متتابعة وسجل كامل قابل للطباعة.
 */
namespace APP\plugins\generic\tadabburSuite\m\proofApproval;

use APP\plugins\generic\tadabburSuite\m\proofApproval\classes\Store;
use APP\plugins\generic\tadabburSuite\m\proofApproval\pages\ProofHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;

class ProofApprovalPlugin extends ModulePlugin
{
    public const PAGE = 'proofApproval';
    public const ICON = 'Check';

    public function handler(): object { return new ProofHandler($this); }
    public function getInstallMigration() { return new Store(); }

    protected function menuFor($request): ?array
    {
        if ($this->isEditor($request)) return ['menu', 'index'];
        return (new Store())->forAuthor((int) $request->getContext()->getId(), (int) $request->getUser()->getId()) ? ['myMenu', 'mine'] : null;
    }
}
