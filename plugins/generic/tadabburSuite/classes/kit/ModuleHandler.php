<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/kit/ModuleHandler.php
 * أساس مشترك لصفحات الوحدات: الحماية (CSRF/الأدوار)، وعناصر الواجهة الموحدة، والعرض داخل لوحة OJS.
 */
namespace APP\plugins\generic\tadabburSuite\classes\kit;

use APP\handler\Handler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

abstract class ModuleHandler extends Handler
{
    public const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    public const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    public const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;';
    public const S_TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    public const S_BTN = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    public const S_BTNP = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    public const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';

    public function __construct(protected ModulePlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    protected function t(string $k, array $p = []): string { return __($this->plugin->prefix() . '.' . $k, $p); }
    protected function url($request, string $op, array $params = [], ?array $path = null): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $this->plugin::PAGE, $op, $path, $params);
    }
    protected function cid($request): int { return (int) $request->getContext()->getId(); }
    protected function csrf($request): string { return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'; }
    protected function login($request): void { if (!$request->getUser()) { Validation::redirectLogin(); exit; } }
    protected function deny(): void { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    protected function notFound(): void { http_response_code(404); echo 'Not found'; exit; }
    protected function postOk($request): void { if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; } }
    protected function editor($request): void { $this->login($request); if (!$this->plugin->isEditor($request)) $this->deny(); }
    protected function manager($request, bool $post = true): void { $this->login($request); if (!$this->plugin->isManager($request)) $this->deny(); if ($post) $this->postOk($request); }
    protected function v($request, string $k): string { return trim((string) $request->getUserVar($k)); }
    protected function back($request, string $op, array $p = [], string $msg = 'saved'): void { $request->redirectUrl($this->url($request, $op, $p + ['m' => $msg])); }

    protected function flash($request): string
    {
        $m = (string) $request->getUserVar('m');
        if ($m === '' || !preg_match('/^[a-zA-Z]+$/', $m)) return '';
        $bad = str_starts_with($m, 'err');
        return '<div class="tdb-alert tdb-alert--' . ($bad ? 'bad' : 'ok') . '" role="status">' . self::e($this->t('msg.' . $m)) . '</div>';
    }

    // ================================================================ مكوّنات الواجهة (UI kit 2)
    /** رأس الصفحة: عنوان فرعي وأزرار */
    protected function head(string $lead, string $actions = ''): string
    {
        return '<div class="tdb-head"><p class="tdb-lead">' . $lead . '</p>' . ($actions !== '' ? '<div class="tdb-actions">' . $actions . '</div>' : '') . '</div>';
    }

    protected function box(string $title, string $inner, string $extra = '', string $actions = ''): string
    {
        return '<section class="tdb-card"' . ($extra !== '' ? ' style="' . $extra . '"' : '') . '>'
            . ($title !== '' || $actions !== '' ? '<header class="tdb-card__head"><h2>' . $title . '</h2>' . ($actions !== '' ? '<div class="tdb-actions">' . $actions . '</div>' : '') . '</header>' : '')
            . '<div class="tdb-card__body">' . $inner . '</div></section>';
    }

    /** جدول: رؤوس نصية (تُهرَّب) وصفوف HTML جاهزة */
    protected function table(array $heads, array $rows, string $empty = ''): string
    {
        $h = '<div class="tdb-table-wrap"><table class="tdb-table"><thead><tr>';
        foreach ($heads as $x) $h .= '<th scope="col">' . self::e($x) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) { $h .= '<tr>'; $i = 0; foreach ($r as $c) $h .= '<td data-label="' . self::e($heads[$i++] ?? '') . '">' . $c . '</td>'; $h .= '</tr>'; }
        if (!$rows) $h .= '<tr><td colspan="' . max(1, count($heads)) . '">' . $this->emptyState($empty !== '' ? $empty : $this->t('none')) . '</td></tr>';
        return $h . '</tbody></table></div>';
    }

    protected function emptyState(string $text): string { return '<div class="tdb-empty">' . self::e($text) . '</div>'; }

    protected function btn(string $href, string $label, bool $primary = false, string $attrs = '', string $variant = ''): string
    {
        return '<a class="tdb-btn' . ($primary ? ' tdb-btn--primary' : '') . ($variant !== '' ? ' tdb-btn--' . $variant : '') . '" href="' . self::e($href) . '"' . $attrs . '>' . $label . '</a>';
    }

    protected function postBtn($request, string $op, array $fields, string $label, bool $primary = false, string $confirm = '', string $variant = ''): string
    {
        $h = '<form method="post" class="tdb-inline" action="' . self::e($this->url($request, $op)) . '"' . ($confirm !== '' ? ' onsubmit="return confirm(' . self::e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . $this->csrf($request);
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
        return $h . '<button class="tdb-btn' . ($primary ? ' tdb-btn--primary' : '') . ($variant !== '' ? ' tdb-btn--' . $variant : '') . '">' . $label . '</button></form>';
    }

    protected function tabs($request, array $tabs, string $cur): string
    {
        $h = '<nav class="tdb-tabs" role="tablist">';
        foreach ($tabs as $op => $label) {
            [$o, $q] = array_pad(explode('?', (string) $op, 2), 2, '');
            parse_str($q, $params);
            $h .= '<a role="tab" class="tdb-tab' . ($op === $cur ? ' is-active' : '') . '"' . ($op === $cur ? ' aria-selected="true"' : '') . ' href="' . self::e($this->url($request, $o, $params)) . '">' . $label . '</a>';
        }
        return $h . '</nav>';
    }

    protected function badge(bool $ok, string $label = ''): string
    {
        return $this->pill($ok ? 'ok' : 'bad', ($ok ? '✔' : '✖') . ($label !== '' ? ' ' . $label : ''));
    }

    /** شارة حالة: ok | warn | bad | info | muted */
    protected function pill(string $kind, string $label): string { return '<span class="tdb-badge tdb-badge--' . self::e($kind) . '">' . $label . '</span>'; }

    protected function stat(string $value, string $label, string $kind = ''): string
    {
        return '<div class="tdb-stat' . ($kind !== '' ? ' tdb-stat--' . $kind : '') . '"><b>' . $value . '</b><span>' . self::e($label) . '</span></div>';
    }

    protected function stats(array $items): string { return '<div class="tdb-stats">' . implode('', $items) . '</div>'; }

    /** حقل نموذج: تسمية + عنصر + مساعدة */
    protected function field(string $label, string $control, string $help = ''): string
    {
        return '<label class="tdb-field"><span class="tdb-field__label">' . $label . '</span>' . $control . ($help !== '' ? '<small class="tdb-field__help">' . $help . '</small>' : '') . '</label>';
    }

    protected function select(string $name, array $options, ?string $selected = '', string $attrs = ''): string
    {
        $h = '<select class="tdb-input" name="' . self::e($name) . '"' . $attrs . '>';
        foreach ($options as $v => $l) $h .= '<option value="' . self::e($v) . '"' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . self::e($l) . '</option>';
        return $h . '</select>';
    }

    protected function toggle(string $name, bool $on, string $label): string
    {
        return '<label class="tdb-switch"><input type="checkbox" name="' . self::e($name) . '" value="1"' . ($on ? ' checked' : '') . '><span class="tdb-switch__ui" aria-hidden="true"></span><span>' . $label . '</span></label>';
    }

    protected function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    /** صفحة عامة داخل قالب الواجهة الأمامية */
    protected function showPublic($request, string $title, string $html): void
    {
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['bpTitle' => $title, 'bpHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('public.tpl'));
    }

    protected function download(string $name, string $type, string $body): void
    {
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
        header('Content-Length: ' . strlen($body));
        echo $body; exit;
    }
}
