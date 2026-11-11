<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/kit/ModuleBase.php
 *
 * أساس كل وحدات الحزمة. الوحدة قدرة داخلية للمنتج الوحيد tadabburSuite، وليست منتجًا في OJS:
 *  - لا تُسجَّل في PluginRegistry، فلا تظهر في صفحة الإضافات ولا يبحث OJS عن version.xml لها.
 *  - تفعيلها قرار الحزمة (إعداد مجلة + مرحلة الإطلاق في Manifest)، لا صف enabled في plugin_settings.
 *  - اسمها يُستعمل فقط مفتاحًا لإعداداتها في plugin_settings (tdb<module>plugin).
 */
namespace APP\plugins\generic\tadabburSuite\classes\kit;

use APP\plugins\generic\tadabburSuite\classes\Manifest;
use PKP\plugins\GenericPlugin;

abstract class ModuleBase extends GenericPlugin
{
    private bool $activated = false;

    public function getName()
    {
        $parts = explode('\\', static::class);
        return 'tdb' . strtolower(end($parts));
    }

    /** مفتاح الوحدة (اسم مجلدها) */
    public function moduleKey(): string
    {
        $parts = explode('\\', static::class);
        return lcfirst(preg_replace('/Plugin$/', '', end($parts)));
    }

    /** تستدعيها الحزمة قبل register() للوحدات التي قررت تحميلها */
    public function activate(): void { $this->activated = true; }

    public function getEnabled($contextId = null)
    {
        return $this->activated;
    }

    /** وضع القراءة فقط: لا بريد ولا تعديل لبيانات التحرير (انظر Manifest) */
    public function readOnly(): bool { return Manifest::readOnly($this->moduleKey()); }
}
