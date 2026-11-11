<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/themes/tadabbur/TadabburThemePlugin.php
 *
 * قالب «تدبر» لنظام OJS 3.5 — قالب فرعي من القالب الافتراضي (Default) بهوية مجلة تدبر ومكتب خبرات طيبة.
 *  - الواجهة العامة: ترويسة بشعارَي المجلة والناشر وISSN، صفحة رئيسة بمعلومات الفهرسة والسياسات،
 *    صفحة بحث مع الملخص باللغة الأخرى وتاريخ البحث (الاستلام/القبول/النشر) ونسخ DOI، تذييل بالسياسات والفهارس.
 *  - بيانات منظَّمة JSON-LD (Periodical / ScholarlyArticle) لمحركات البحث.
 *  - لوحة التحكم (الإدارة/المحرر/المحكّم/المؤلف): ألوان الهوية وخط IBM Plex Sans Arabic، تُولَّد آليًا من
 *    ملف أنماط OJS المثبت لديكم فتبقى متوافقة مع تحديثاته الصغرى.
 */
namespace APP\plugins\themes\tadabbur;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\plugins\Hook;
use PKP\plugins\ThemePlugin;

class TadabburThemePlugin extends ThemePlugin
{
    public const DEFAULTS = [
        'primaryColour' => '#43260f',
        'accentColour' => '#c5a158',
        'secondaryColour' => '#3d4170',
    ];

    public function getDisplayName() { return 'قالب تدبر — Tadabbur Theme'; }

    public function getDescription()
    {
        return 'قالب بهوية مجلة تدبر ومكتب خبرات طيبة للواجهة العامة ولوحة التحكم، متجاوب ومهيأ لمتطلبات الفهارس الدولية (Scopus/WoS/DOAJ). — تطوير: مصطفى محمود — مكتب خبرات طيبة للبحوث والدراسات (Moustafa Mahmoud · github.com/mostafamr2011 · khibrattaibah.com)';
    }

    public function isActive()
    {
        if (\PKP\core\PKPSessionGuard::isSessionDisable()) return false;
        return parent::isActive();
    }

    public function init()
    {
        $this->setParent('defaultthemeplugin');
        // خيارات القالب الأب التي يحل محلها هذا القالب (الخط واللون وملخص الصفحة الرئيسة)
        foreach (['typography', 'baseColour', 'showDescriptionInJournalIndex', 'useHomepageImageAsHeader'] as $o) $this->removeOption($o);

        // ---------- خيارات القالب (الإعدادات ← الموقع ← المظهر) ----------
        $this->addOption('primaryColour', 'FieldColor', ['label' => 'اللون الأساسي (بني تدبر)', 'default' => self::DEFAULTS['primaryColour']]);
        $this->addOption('accentColour', 'FieldColor', ['label' => 'لون التمييز (ذهبي)', 'default' => self::DEFAULTS['accentColour']]);
        $this->addOption('secondaryColour', 'FieldColor', ['label' => 'اللون الثانوي (كحلي خبرات طيبة)', 'default' => self::DEFAULTS['secondaryColour']]);
        $this->addOption('tagline', 'FieldText', ['label' => 'سطر تعريفي تحت اسم المجلة', 'isMultilingual' => true,
            'description' => 'مثال: مجلة علمية محكّمة في الدراسات القرآنية']);
        $this->addOption('publisherName', 'FieldText', ['label' => 'اسم الناشر', 'isMultilingual' => true]);
        $this->addOption('publisherUrl', 'FieldText', ['label' => 'رابط موقع الناشر', 'default' => 'https://khibrattaibah.com']);
        $this->addOption('frequency', 'FieldText', ['label' => 'دورية الصدور', 'isMultilingual' => true, 'description' => 'مثال: نصف سنوية (يونيو وديسمبر)']);
        $this->addOption('reviewType', 'FieldText', ['label' => 'نوع التحكيم', 'isMultilingual' => true, 'description' => 'مثال: تحكيم مزدوج التعمية (Double-blind)']);
        $this->addOption('apc', 'FieldText', ['label' => 'رسوم النشر (APC)', 'isMultilingual' => true, 'description' => 'اتركه فارغًا لإخفائه. مثال: لا توجد رسوم تقديم أو نشر']);
        $this->addOption('foundedYear', 'FieldText', ['label' => 'سنة التأسيس']);
        $this->addOption('policyLinks', 'FieldTextarea', ['label' => 'روابط السياسات (سطر لكل رابط: المفتاح|الرابط)',
            'description' => 'المفاتيح: aims, board, review, ethics, openaccess, guidelines, apc, archiving, privacy. الرابط الفارغ يُحوَّل لصفحات OJS المناسبة.']);
        $this->addOption('indexing', 'FieldTextarea', ['label' => 'قواعد البيانات والفهارس (سطر لكل جهة: الاسم|الرابط)',
            'description' => 'لا تُدرج إلا جهة مفهرسة فعلًا، فالادعاء غير الصحيح يضر بتقييم المجلة.']);
        $this->addOption('bilingualAbstract', 'FieldOptions', ['label' => 'صفحة البحث', 'options' => [['value' => true, 'label' => 'عرض العنوان والملخص والكلمات المفتاحية باللغة الأخرى']], 'default' => true]);
        $this->addOption('backendBranding', 'FieldOptions', ['label' => 'لوحة التحكم', 'options' => [['value' => true, 'label' => 'تطبيق ألوان الهوية وخطها على لوحة التحكم']], 'default' => true]);

        // ---------- الأنماط ----------
        $p = $this->colour('primaryColour'); $a = $this->colour('accentColour'); $s = $this->colour('secondaryColour');
        $this->modifyStyle('stylesheet', [
            'addLess' => ['styles/tadabbur.less'],
            'addLessVariables' => "@tdb-primary: $p;\n@tdb-accent: $a;\n@tdb-secondary: $s;\n@bg-base: $p;\n@primary: mix($p, $s, 70%);\n"
                . "@font: \"IBM Plex Sans Arabic\", -apple-system, BlinkMacSystemFont, \"Segoe UI\", Tahoma, sans-serif;\n@font-heading: @font;\n@radius: 6px;\n@rem: 15px;",
        ]);
        $this->removeStyle('font');
        $this->addStyle('tdbFont', 'styles/fonts.less');
        $this->addScript('tdb', 'js/tadabbur.js');

        // ---------- الخطافات ----------
        Hook::add('TemplateManager::display', $this->onDisplay(...));
        Hook::add('Templates::Article::Main', $this->articleMain(...));
        Hook::add('Templates::Article::Details', $this->articleDetails(...));
        Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
    }

    // ------------------------------------------------------------------ أدوات
    private function colour(string $name): string
    {
        $v = (string) $this->getOption($name);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : self::DEFAULTS[$name];
    }

    /** قيمة خيار متعدد اللغات باللغة الحالية، ثم العربية، ثم أول قيمة */
    public function localized(string $name): string
    {
        $v = $this->getOption($name);
        if (!is_array($v)) return trim((string) $v);
        $l = Locale::getLocale();
        foreach ([$l, 'ar', 'en'] as $k) if (!empty($v[$k])) return trim((string) $v[$k]);
        foreach ($v as $x) if (trim((string) $x) !== '') return trim((string) $x);
        return '';
    }

    /** الكلمات المفتاحية في 3.5 مصفوفات ['name' => ...] */
    public static function kwNames(array $kw): array
    {
        return array_values(array_filter(array_map(fn ($k) => trim(strip_tags(is_array($k) ? (string) ($k['name'] ?? reset($k)) : (string) $k)), $kw)));
    }

    private function lines(string $name): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->getOption($name)) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] === '') continue;
            $out[] = ['key' => $parts[0], 'url' => $parts[1] ?? ''];
        }
        return $out;
    }

    private function policyLinks($request): array
    {
        $u = fn ($page, $op = null, $path = null) => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $page, $op, $path);
        $ar = Locale::getLocale() === 'ar';
        $links = [
            'aims' => [$ar ? 'الأهداف والنطاق' : 'Aims & Scope', $u('about'), 'target'],
            'board' => [$ar ? 'هيئة التحرير' : 'Editorial Board', $u('about', 'editorialMasthead'), 'users'],
            'review' => [$ar ? 'سياسة التحكيم' : 'Peer Review Policy', $u('about') . '#peerReview', 'review'],
            'ethics' => [$ar ? 'أخلاقيات النشر' : 'Publication Ethics', $u('about') . '#ethics', 'shield'],
            'openaccess' => [$ar ? 'الوصول المفتوح والترخيص' : 'Open Access & Licensing', $u('about') . '#openAccess', 'unlock'],
            'guidelines' => [$ar ? 'دليل المؤلفين' : 'Author Guidelines', $u('about', 'submissions'), 'pen'],
            'apc' => [$ar ? 'رسوم النشر' : 'Publication Fees', $u('about', 'submissions') . '#authorFees', 'coin'],
            'archiving' => [$ar ? 'الأرشفة والحفظ' : 'Archiving', $u('about') . '#archiving', 'archive'],
            'privacy' => [$ar ? 'بيان الخصوصية' : 'Privacy Statement', $u('about', 'privacy'), 'lock'],
        ];
        foreach ($this->lines('policyLinks') as $l) {
            if (isset($links[$l['key']]) && $l['url'] !== '') $links[$l['key']][1] = $l['url'];
        }
        return $links;
    }

    // ------------------------------------------------------------------ الواجهة العامة
    public function onDisplay(string $hookName, array $args): bool
    {
        $tm = $args[0];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $tm->assign([
            'tdbTheme' => $this,
            'tdbTagline' => $this->localized('tagline'),
            'tdbPublisher' => $this->localized('publisherName') ?: ($context ? (string) $context->getData('publisherInstitution') : ''),
            'tdbPublisherUrl' => (string) $this->getOption('publisherUrl'),
            'tdbFrequency' => $this->localized('frequency'),
            'tdbReviewType' => $this->localized('reviewType'),
            'tdbApc' => $this->localized('apc'),
            'tdbFounded' => (string) $this->getOption('foundedYear'),
            'tdbIndexing' => array_map(fn ($l) => ['name' => $l['key'], 'url' => $l['url']], $this->lines('indexing')),
            'tdbPolicies' => $this->policyLinks($request),
            'tdbLogo' => $request->getBaseUrl() . '/' . $this->getPluginPath() . '/images/logo-tadabbur.png',
            'tdbPublisherLogo' => $request->getBaseUrl() . '/' . $this->getPluginPath() . '/images/logo-khibrat.png',
            'tdbIssnOnline' => $context ? (string) $context->getData('onlineIssn') : '',
            'tdbIssnPrint' => $context ? (string) $context->getData('printIssn') : '',
            'tdbLicenseUrl' => $context ? (string) $context->getData('licenseUrl') : '',
        ]);
        if ($context) {
            $tm->assign('tdbLocales', Locale::getFormattedDisplayNames($context->getSupportedLocales(), Locale::getLocales(), \PKP\i18n\LocaleMetadata::LANGUAGE_LOCALE_ONLY));
            $this->jsonLd($tm, $request, $context);
            if (in_array($request->getRequestedPage(), [null, '', 'index'], true)) $tm->assign('tdbStats', $this->homeStats((int) $context->getId()));
        }
        return false;
    }

    /** مؤشرات حيّة للصفحة الرئيسة (تُحسب من بيانات OJS وتُخزَّن ساعة) */
    private function homeStats(int $cid): array
    {
        $key = 'homeStats_' . $cid;
        $cached = json_decode((string) $this->getSetting($cid, $key), true);
        if (is_array($cached) && ($cached['t'] ?? 0) > time() - 3600) $s = $cached['s'];
        else {
            $s = [];
            try {
                $s['articles'] = DB::table('submissions')->where('context_id', $cid)->where('status', 3)->count();
                $s['issues'] = DB::table('issues')->where('journal_id', $cid)->where('published', 1)->count();
                $since = date('Y-m-d', strtotime('-3 years'));
                $dec = DB::table('edit_decisions as d')->join('submissions as s', 's.submission_id', '=', 'd.submission_id')->where('s.context_id', $cid)->where('d.date_decided', '>=', $since)
                    ->select('d.submission_id', 'd.decision', 'd.date_decided', 's.date_submitted')->orderBy('d.date_decided')->get();
                $acc = []; $rej = []; $first = [];
                foreach ($dec as $d) {
                    $sid = (int) $d->submission_id;
                    if (in_array((int) $d->decision, [2, 17], true)) $acc[$sid] = 1;
                    if (in_array((int) $d->decision, [6, 8], true)) $rej[$sid] = 1;
                    if (!isset($first[$sid]) && $d->date_submitted) $first[$sid] = max(0, (int) round((strtotime((string) $d->date_decided) - strtotime((string) $d->date_submitted)) / 86400));
                }
                $done = count($acc) + count(array_diff_key($rej, $acc));
                $s['acceptance'] = $done >= 5 ? (int) round(100 * count($acc) / $done) : null;
                sort($first);
                $s['decision'] = count($first) >= 5 ? $first[intdiv(count($first), 2)] : null;
            } catch (\Throwable $e) { error_log('[tadabbur theme] stats: ' . $e->getMessage()); }
            $this->updateSetting($cid, $key, json_encode(['t' => time(), 's' => $s]), 'string');
        }
        $out = [];
        if (!empty($s['articles'])) $out[] = ['value' => (string) $s['articles'], 'label' => __('plugins.themes.tadabbur.stArticles')];
        if (!empty($s['issues'])) $out[] = ['value' => (string) $s['issues'], 'label' => __('plugins.themes.tadabbur.stIssues')];
        if (isset($s['acceptance']) && $s['acceptance'] !== null) $out[] = ['value' => $s['acceptance'] . '%', 'label' => __('plugins.themes.tadabbur.stAcceptance')];
        if (isset($s['decision']) && $s['decision'] !== null) $out[] = ['value' => (string) $s['decision'], 'label' => __('plugins.themes.tadabbur.stDecision')];
        return $out;
    }

    private function jsonLd($tm, $request, $context): void
    {
        $journalUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'index');
        $periodical = array_filter([
            '@type' => 'Periodical', 'name' => (string) $context->getLocalizedName(), 'url' => $journalUrl,
            'issn' => array_values(array_filter([(string) $context->getData('onlineIssn'), (string) $context->getData('printIssn')])) ?: null,
            'publisher' => ($pub = $this->localized('publisherName') ?: (string) $context->getData('publisherInstitution')) ? ['@type' => 'Organization', 'name' => $pub] : null,
            'inLanguage' => $context->getSupportedLocales(),
        ]);
        $data = ['@context' => 'https://schema.org'] + $periodical;
        $publication = $tm->getTemplateVars('publication');
        $article = $tm->getTemplateVars('article');
        if ($request->getRequestedPage() === 'article' && $publication && $article) {
            $authors = [];
            foreach ($publication->getData('authors') ?? [] as $au) {
                $authors[] = array_filter(['@type' => 'Person', 'name' => $au->getFullName(), 'sameAs' => $au->getData('orcid') ?: null]);
            }
            $doi = $publication->getDoi();
            $data = array_filter([
                '@context' => 'https://schema.org', '@type' => 'ScholarlyArticle',
                'headline' => strip_tags((string) $publication->getLocalizedFullTitle()),
                'name' => strip_tags((string) $publication->getLocalizedFullTitle()),
                'author' => $authors ?: null,
                'datePublished' => $publication->getData('datePublished') ?: null,
                'inLanguage' => $publication->getData('locale') ?: null,
                'identifier' => $doi ? 'https://doi.org/' . $doi : null,
                'sameAs' => $doi ? 'https://doi.org/' . $doi : null,
                'license' => $publication->getData('licenseUrl') ?: null,
                'keywords' => ($kw = self::kwNames((array) $publication->getLocalizedData('keywords'))) ? implode(', ', $kw) : null,
                'pagination' => $publication->getData('pages') ?: null,
                'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$article->getBestId()]),
                'isPartOf' => $periodical,
            ]);
        } elseif (!in_array($request->getRequestedPage(), [null, '', 'index'], true)) {
            return;
        }
        $tm->addHeader('tdbJsonLd', '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>', ['contexts' => 'frontend']);
    }

    /** ملخص البحث باللغات الأخرى (متطلب Scopus/WoS: عنوان وملخص إنجليزيان) */
    public function articleMain(string $hookName, array $args): bool
    {
        if (!$this->getOption('bilingualAbstract')) return false;
        $smarty = $args[1]; $output = &$args[2];
        $publication = $smarty->getTemplateVars('publication');
        if (!$publication) return false;
        $current = Locale::getLocale();
        $blocks = [];
        foreach ((array) $publication->getData('title') as $locale => $title) {
            if ($locale === $current || trim(strip_tags((string) $title)) === '') continue;
            $abstract = (string) ($publication->getData('abstract')[$locale] ?? '');
            $keywords = self::kwNames((array) ($publication->getData('keywords')[$locale] ?? []));
            $blocks[] = [
                'locale' => $locale, 'dir' => Locale::getMetadata($locale)?->isRightToLeft() ? 'rtl' : 'ltr',
                'langName' => Locale::getMetadata($locale)?->getDisplayName($current) ?? $locale,
                'title' => $publication->getLocalizedFullTitle($locale), 'abstract' => $abstract, 'keywords' => $keywords,
            ];
        }
        if (!$blocks) return false;
        $smarty->assign('tdbOtherLangs', $blocks);
        $output .= $smarty->fetch($this->getTemplateResource('tdb/article_other_languages.tpl'));
        return false;
    }

    /** تاريخ البحث + نسخ DOI في عمود التفاصيل */
    public function articleDetails(string $hookName, array $args): bool
    {
        $smarty = $args[1]; $output = &$args[2];
        $article = $smarty->getTemplateVars('article');
        $publication = $smarty->getTemplateVars('publication');
        if (!$article || !$publication) return false;
        $accepted = null;
        try {
            $accepted = DB::table('edit_decisions')->where('submission_id', $article->getId())
                ->where('decision', \PKP\decision\Decision::ACCEPT)->max('date_decided');
        } catch (\Throwable $e) {
        }
        $smarty->assign([
            'tdbReceived' => $article->getData('dateSubmitted'),
            'tdbAccepted' => $accepted,
            'tdbPublished' => $publication->getData('datePublished'),
            'tdbDoi' => $publication->getDoi(),
        ]);
        $output .= $smarty->fetch($this->getTemplateResource('tdb/article_history.tpl'));
        return false;
    }

    // ------------------------------------------------------------------ لوحة التحكم
    public function onBackendPage(string $hookName, array $args = []): bool
    {
        if (!$this->getOption('backendBranding')) return false;
        try {
            $request = Application::get()->getRequest();
            // ملف لكل مجلة: لا يحذف تحديثُ مجلةٍ نسخةَ مجلةٍ أخرى (F28)
            $cid = (int) ($request->getContext()?->getId() ?? 0);
            $prefix = 'tdb-backend-c' . $cid . '-';
            $name = $prefix . $this->backendVersion($request) . '.css';
            $file = Core::getBaseDir() . '/cache/' . $name;
            if (!is_file($file)) {
                foreach (glob(Core::getBaseDir() . '/cache/' . $prefix . '*.css') ?: [] as $old) @unlink($old);
                $css = (new classes\BackendStyleBuilder(Core::getBaseDir() . '/styles/build.css',
                    $this->colour('primaryColour'), $this->colour('accentColour'), $this->colour('secondaryColour'),
                    $request->getBaseUrl() . '/' . $this->getPluginPath(), $request->getBaseUrl() . '/styles'))->build();
                // كتابة ذرّية: ملف مؤقت ثم rename حتى لا يُخدَم ملف نصف مكتوب
                $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $css) === false || !@rename($tmp, $file)) { @unlink($tmp); return false; }
            }
            // يحل محل styles/build.css (الاسم build والأولوية نفسها) فيبقى ترتيب القواعد كما هو
            TemplateManager::getManager($request)->addStyleSheet('build', $request->getBaseUrl() . '/cache/' . $name,
                ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_CORE]);
        } catch (\Throwable $e) {
            error_log('[tadabburTheme] backend css: ' . $e->getMessage());
        }
        return false;
    }

    private function backendVersion($request): string
    {
        $css = Core::getBaseDir() . '/styles/build.css';
        return substr(md5(implode('|', [is_file($css) ? filemtime($css) : 0, $request->getBaseUrl(), $this->colour('primaryColour'),
            $this->colour('accentColour'), $this->colour('secondaryColour'), (string) @filemtime(__DIR__ . '/classes/BackendStyleBuilder.php')])), 0, 12);
    }
}
