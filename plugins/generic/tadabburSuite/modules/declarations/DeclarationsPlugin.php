<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/declarations/DeclarationsPlugin.php
 *
 * «إقرارات الشفافية والأخلاقيات» — Research Declarations for OJS 3.5
 *  إقرارات منظّمة يملؤها المؤلف عند التقديم (بأسلوب Nature وSpringer وElsevier):
 *   تضارب المصالح · التمويل · الموافقة الأخلاقية · استخدام الذكاء الاصطناعي التوليدي · الشكر والتقدير
 *  - تظهر في خطوة «للمحررين» من معالج التقديم وفي تبويب البيانات الوصفية للنشر.
 *  - يمكن جعلها إلزامية قبل إرسال البحث.
 *  - تُعرض في صفحة البحث المنشور، وتُضاف إلى JATS XML (fn-group) لتقرأها الفهارس.
 *  - سجل للمحررين يبين اكتمال الإقرارات في البحوث المنشورة وتصدير CSV.
 *  تكمّل إضافة CRediT المدمجة في OJS (أدوار المؤلفين) وحقل «إتاحة البيانات» الأصلي، ولا تكررهما.
 */
namespace APP\plugins\generic\tadabburSuite\m\declarations;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\declarations\pages\DeclarationsHandler;
use APP\template\TemplateManager;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldRadioInput;
use PKP\components\forms\FieldTextarea;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class DeclarationsPlugin extends \APP\plugins\generic\tadabburSuite\classes\kit\ModuleBase
{
    public const PAGE = 'declarations';
    /** البند => [القيمة «لا يوجد»، القيمة «يوجد»] */
    public const ITEMS = ['coi' => ['none', 'declared'], 'funding' => ['none', 'funded'], 'ethics' => ['na', 'approved'], 'ai' => ['none', 'used']];

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('Schema::get::publication', $this->onSchema(...));
            Hook::add('Form::config::before', $this->onFormConfig(...));
            Hook::add('Submission::validateSubmit', $this->onValidateSubmit(...));
            Hook::add('Templates::Article::Main', $this->onArticleMain(...));
            Hook::add('JatsTemplatePlugin::jats', $this->onJats(...));
            Hook::add('LoadHandler', $this->onLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->onBackendPage(...));
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.declarations.name'); }
    public function getDescription() { return __('plugins.generic.declarations.description'); }

    public static function field(string $item): string { return 'decl' . ucfirst($item); }
    public static function textField(string $item): string { return 'decl' . ucfirst($item) . 'Text'; }

    public function cfg(int $cid): array
    {
        $items = $this->getSetting($cid, 'items');
        $req = $this->getSetting($cid, 'required');
        $show = $this->getSetting($cid, 'showOnArticle');
        return [
            'items' => is_array($items) ? array_values(array_intersect(array_keys(self::ITEMS), $items)) : array_keys(self::ITEMS),
            'required' => $req === null ? true : (bool) $req,
            'showOnArticle' => $show === null ? true : (bool) $show,
        ];
    }

    // ================================================================ المخطط
    public function onSchema(string $hookName, array $args): bool
    {
        $schema = &$args[0];
        foreach (self::ITEMS as $item => $vals) {
            $schema->properties->{self::field($item)} = (object) ['type' => 'string', 'apiSummary' => true, 'validation' => ['nullable', 'in:' . implode(',', $vals)]];
            $schema->properties->{self::textField($item)} = (object) ['type' => 'string', 'multilingual' => true, 'apiSummary' => true, 'validation' => ['nullable']];
        }
        $schema->properties->declAck = (object) ['type' => 'string', 'multilingual' => true, 'apiSummary' => true, 'validation' => ['nullable']];
        return Hook::CONTINUE;
    }

    // ================================================================ النماذج
    public function onFormConfig(string $hookName, $form): bool
    {
        if (!in_array($form->id ?? '', ['forTheEditors', 'metadata'], true) || !isset($form->publication)) return Hook::CONTINUE;
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return Hook::CONTINUE;
        $cfg = $this->cfg((int) $context->getId());
        $pub = $form->publication;
        $form->addField(new FieldHTML('declHeader', [
            'label' => __('plugins.generic.declarations.formTitle'),
            'description' => __('plugins.generic.declarations.formIntro'),
        ]));
        foreach ($cfg['items'] as $item) {
            [$no, $yes] = self::ITEMS[$item];
            $form->addField(new FieldRadioInput(self::field($item), [
                'label' => __("plugins.generic.declarations.$item.label"),
                'description' => __("plugins.generic.declarations.$item.help"),
                'options' => [
                    ['value' => $no, 'label' => __("plugins.generic.declarations.$item.$no")],
                    ['value' => $yes, 'label' => __("plugins.generic.declarations.$item.$yes")],
                ],
                'value' => $pub->getData(self::field($item)),
                'isRequired' => $cfg['required'] && ($form->id === 'forTheEditors'),
            ]));
            $form->addField(new FieldTextarea(self::textField($item), [
                'label' => __("plugins.generic.declarations.$item.textLabel"),
                'description' => __("plugins.generic.declarations.$item.textHelp"),
                'isMultilingual' => true,
                'size' => 'small',
                'showWhen' => [self::field($item), $yes],
                'value' => $pub->getData(self::textField($item)),
            ]));
        }
        $form->addField(new FieldTextarea('declAck', [
            'label' => __('plugins.generic.declarations.ack.label'),
            'description' => __('plugins.generic.declarations.ack.help'),
            'isMultilingual' => true,
            'size' => 'small',
            'value' => $pub->getData('declAck'),
        ]));
        return Hook::CONTINUE;
    }

    // ================================================================ الإلزام عند التقديم
    public function onValidateSubmit(string $hookName, array $args): bool
    {
        $errors = &$args[0]; $submission = $args[1]; $context = $args[2];
        $cfg = $this->cfg((int) $context->getId());
        if (!$cfg['required']) return Hook::CONTINUE;
        $pub = $submission->getCurrentPublication();
        if (!$pub) return Hook::CONTINUE;
        $pub = Repo::publication()->get((int) $pub->getId()) ?? $pub;
        foreach ($cfg['items'] as $item) {
            $v = (string) $pub->getData(self::field($item));
            [$no, $yes] = self::ITEMS[$item];
            if ($v === '') { $errors[self::field($item)] = [__('plugins.generic.declarations.error.missing', ['item' => __("plugins.generic.declarations.$item.label")])]; continue; }
            if ($v === $yes) {
                $txt = array_filter(array_map(fn ($t) => trim(strip_tags((string) $t)), (array) $pub->getData(self::textField($item))));
                if (!$txt) $errors[self::textField($item)] = [__('plugins.generic.declarations.error.text', ['item' => __("plugins.generic.declarations.$item.label")])];
            }
        }
        return Hook::CONTINUE;
    }

    // ================================================================ صفحة البحث
    /** @return array<int, array{item:string, label:string, text:string}> */
    public function statements($publication, string $locale, string $fallback): array
    {
        $out = [];
        foreach (array_merge(array_keys(self::ITEMS), ['ack']) as $item) {
            if ($item === 'ack') {
                $t = $this->pick((array) $publication->getData('declAck'), $locale, $fallback);
                if ($t !== '') $out[] = ['item' => 'ack', 'label' => __('plugins.generic.declarations.ack.title', [], $locale), 'text' => $t];
                continue;
            }
            $v = (string) $publication->getData(self::field($item));
            if ($v === '') continue;
            [$no, $yes] = self::ITEMS[$item];
            $text = $v === $yes ? $this->pick((array) $publication->getData(self::textField($item)), $locale, $fallback) : __("plugins.generic.declarations.$item.$no.statement", [], $locale);
            if ($text === '') continue;
            $out[] = ['item' => $item, 'label' => __("plugins.generic.declarations.$item.title", [], $locale), 'text' => $text];
        }
        return $out;
    }

    private function pick(array $vals, string $locale, string $fallback): string
    {
        foreach ([$locale, $fallback] as $l) if (trim((string) ($vals[$l] ?? '')) !== '') return trim((string) $vals[$l]);
        foreach ($vals as $v) if (trim((string) $v) !== '') return trim((string) $v);
        return '';
    }

    public function onArticleMain(string $hookName, array $args): bool
    {
        $smarty = $args[1]; $output = &$args[2];
        $publication = $smarty->getTemplateVars('publication');
        $context = Application::get()->getRequest()->getContext();
        if (!$publication || !$context || !$this->cfg((int) $context->getId())['showOnArticle']) return false;
        $st = $this->statements($publication, Locale::getLocale(), (string) $publication->getData('locale'));
        if (!$st) return false;
        $smarty->assign(['declStatements' => $st, 'declTitle' => __('plugins.generic.declarations.publicTitle')]);
        $output .= $smarty->fetch($this->getTemplateResource('article.tpl'));
        return false;
    }

    // ================================================================ JATS
    public function onJats(string $hookName, $record, \DOMDocument $doc): bool
    {
        try {
            $submission = $record->getData('article');
            $publication = $submission->getCurrentPublication();
            $st = $this->statements($publication, 'en', (string) $publication->getData('locale'));
            if (!$st) return Hook::CONTINUE;
            $xp = new \DOMXPath($doc);
            $meta = $xp->query('//article/front/article-meta')->item(0);
            if (!$meta) return Hook::CONTINUE;
            $types = ['coi' => 'coi-statement', 'funding' => 'financial-disclosure', 'ethics' => 'other', 'ai' => 'other', 'ack' => 'other'];
            $notes = $xp->query('//article/front/notes')->item(0) ?? null;
            $group = $doc->createElement('fn-group');
            $group->setAttribute('content-type', 'declarations');
            foreach ($st as $s) {
                $fn = $doc->createElement('fn');
                $fn->setAttribute('fn-type', $types[$s['item']]);
                $fn->setAttribute('id', 'decl-' . $s['item']);
                $p = $doc->createElement('p');
                $bold = $doc->createElement('bold'); $bold->appendChild($doc->createTextNode($s['label'] . ': '));
                $p->appendChild($bold); $p->appendChild($doc->createTextNode(strip_tags($s['text'])));
                $fn->appendChild($p); $group->appendChild($fn);
            }
            $back = $xp->query('//article/back')->item(0);
            if (!$back) { $back = $doc->createElement('back'); $xp->query('//article')->item(0)->appendChild($back); }
            $back->insertBefore($group, $back->firstChild);
        } catch (\Throwable $e) {
            error_log('[declarations] JATS: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    // ================================================================ صفحة الإدارة
    public function canView($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        if (!$u || !$c) return false;
        return $u->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], (int) $c->getId())
            || $u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID);
    }

    public function isManager($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        return $u && $c && ($u->hasRole([Role::ROLE_ID_MANAGER], (int) $c->getId()) || $u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID));
    }

    public function onLoadHandler(string $hookName, array $args): bool
    {
        if (($args[0] ?? '') !== self::PAGE) return false;
        $handler = &$args[3];
        $handler = new DeclarationsHandler($this);
        return true;
    }

    public function onBackendPage(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$this->canView($request)) return false;
        $tm = TemplateManager::getManager($request);
        $menu = (array) $tm->getState('menu');
        $menu['declarations'] = [
            'name' => __('plugins.generic.declarations.menu'),
            'url' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index'),
            'icon' => 'Checkbox',
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $tm->setState(['menu' => $menu]);
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled() || !$request->getContext()) return $actions;
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, self::PAGE, 'index');
        array_unshift($actions, new LinkAction('declOpen', new RedirectAction($url), __('plugins.generic.declarations.open')));
        return $actions;
    }
}
