<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/scholarBoost/ScholarBoostPlugin.php
 * «محسّن Google Scholar» — يكمّل وسوم الفهرسة في صفحة البحث (الناشر، اللغة الثانية، ORCID، علامة النص المجاني،
 * وبيانات schema.org بصيغة JSON-LD)، ويفحص كل بحث وفق إرشادات Google Scholar للإدراج، ويفحص robots.txt وخريطة الموقع.
 */
namespace APP\plugins\generic\tadabburSuite\m\scholarBoost;

use APP\plugins\generic\tadabburSuite\m\scholarBoost\classes\Tags;
use APP\plugins\generic\tadabburSuite\m\scholarBoost\pages\ScholarHandler;
use APP\plugins\generic\tadabburSuite\classes\kit\ModulePlugin;
use APP\template\TemplateManager;
use PKP\plugins\Hook;

class ScholarBoostPlugin extends ModulePlugin
{
    public const PAGE = 'scholarBoost';
    public const ICON = 'Search';

    public function handler(): object { return new ScholarHandler($this); }

    protected function hooks(): void { Hook::add('ArticleHandler::view', $this->onView(...), Hook::SEQUENCE_LATE); }

    public function opts(int $cid): array
    {
        return ['tags' => (string) $this->cfg($cid, 'tags', '1') === '1', 'jsonld' => (string) $this->cfg($cid, 'jsonld', self::themeJsonLd() ? '0' : '1') === '1'];
    }

    /** هل يضيف القالب النشط بيانات JSON-LD بنفسه؟ (تجنّبًا للتكرار) */
    public static function themeJsonLd(): bool
    {
        foreach (\PKP\plugins\PluginRegistry::getPlugins('themes') ?? [] as $t) {
            if (!$t->getEnabled()) continue;
            $f = (new \ReflectionClass($t))->getFileName();
            if ($f && str_contains((string) @file_get_contents($f), 'ld+json')) return true;
        }
        return false;
    }

    public function onView(string $hookName, array $args): bool
    {
        try {
            $request = $args[0]; $submission = $args[2] ?? null; $ctx = $request->getContext();
            if (!$submission || !$ctx) return false;
            $ra = $request->getRequestedArgs();
            if (count($ra) > 1 && $ra[1] === 'version') return false;
            $o = $this->opts((int) $ctx->getId());
            $tm = TemplateManager::getManager($request);
            foreach (Tags::headers($submission, $ctx, $request, $o) as $k => $html) $tm->addHeader(str_starts_with($k, 'googleScholar') ? $k : 'scholarBoost' . $k, $html);
        } catch (\Throwable $e) {
            error_log('[scholarBoost] ' . $e->getMessage());
        }
        return false;
    }
}
