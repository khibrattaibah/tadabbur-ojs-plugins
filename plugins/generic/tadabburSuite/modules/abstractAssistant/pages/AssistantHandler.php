<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/abstractAssistant/pages/AssistantHandler.php
 * /abstractAssistant (القائمة)، /review?draftId=، و POST: generate، apply، discard، saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\abstractAssistant\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\abstractAssistant\AbstractAssistantPlugin;
use APP\plugins\generic\tadabburSuite\m\abstractAssistant\classes\Drafts;
use APP\plugins\generic\tadabburSuite\m\abstractAssistant\classes\Translator;
use PKP\submission\PKPSubmission;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class AssistantHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';
    private const FIELDS = ['title', 'subtitle', 'abstract', 'keywords'];

    private Drafts $drafts;

    public function __construct(private AbstractAssistantPlugin $plugin) { parent::__construct(); $this->drafts = new Drafts(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.abstractAssistant.' . $k, $p); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, AbstractAssistantPlugin::PAGE, $op, null, $params);
    }
    private function gate($request, bool $manager = false): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
        if (!($manager ? $this->plugin->isManager($request) : $this->plugin->canUse($request))) { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    }
    private function post($request, bool $manager = false): void
    {
        $this->gate($request, $manager);
        if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; }
    }
    private function csrf($request): string { return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'; }
    private function lang(string $l): string
    {
        $k = 'plugins.generic.abstractAssistant.lang.' . substr($l, 0, 2);
        $v = __($k);
        return str_contains($v, 'lang.') ? Translator::langName($l) : $v;
    }
    private function cid($request): int { return (int) $request->getContext()->getId(); }

    private function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    private static function plain(?string $html): string
    {
        $t = preg_replace('/<\s*(br|\/p|\/div|\/li)[^>]*>/i', "\n", (string) $html);
        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')));
    }

    private static function kw($pub, string $locale): array
    {
        $out = [];
        foreach ((array) (($pub->getData('keywords') ?? [])[$locale] ?? []) as $k) $out[] = is_array($k) ? (string) ($k['name'] ?? '') : (string) $k;
        return array_values(array_filter(array_map('trim', $out)));
    }

    /** بيانات لغة معينة من البحث */
    private static function meta($pub, string $locale): array
    {
        return ['title' => self::plain($pub->getData('title', $locale)), 'subtitle' => self::plain($pub->getData('subtitle', $locale)),
            'abstract' => self::plain($pub->getData('abstract', $locale)), 'keywords' => self::kw($pub, $locale)];
    }

    private function completeness($pub, string $locale): string
    {
        $m = self::meta($pub, $locale);
        $chk = fn ($ok, $l) => '<span style="display:inline-block;margin:1px;padding:0 6px;border-radius:9px;font-size:11px;font-weight:700;background:' . ($ok ? '#e6f4ea;color:#1e7b4a' : '#fde8e8;color:#b02a2a') . '">' . ($ok ? '✔' : '✖') . ' ' . $l . '</span>';
        return '<b style="font-size:12px">' . strtoupper(substr($locale, 0, 2)) . '</b> ' . $chk($m['title'] !== '', $this->t('f.title')) . $chk(mb_strlen($m['abstract']) >= 100, $this->t('f.abstract')) . $chk(count($m['keywords']) >= 3, $this->t('f.keywords'));
    }

    // ================================================================ القائمة
    public function index($args, $request)
    {
        $this->gate($request);
        $context = $request->getContext(); $cid = $this->cid($request); $cfg = $this->plugin->cfg($cid);
        $q = trim((string) $request->getUserVar('q'));
        $h = '';
        if ($request->getUserVar('err')) $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1"><b>' . $this->t('error') . '</b> ' . self::e(mb_substr((string) $request->getUserVar('err'), 0, 300)) . '</div>';
        if ($request->getUserVar('saved')) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t('saved') . '</b></div>';
        $h .= '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>';
        if ($cfg['apiKey'] === '') $h .= '<div style="' . self::S_BOX . 'border-color:#a86a00;background:#fff8e6">' . $this->t('noKey') . '</div>';

        $locales = array_values(array_unique(array_merge(['ar', 'en'], (array) $context->getSupportedSubmissionMetadataLocales())));
        $col = Repo::submission()->getCollector()->filterByContextIds([$cid])->limit(40)->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        if ($q !== '') $col->searchPhrase($q);
        $subs = array_values(array_filter(iterator_to_array($col->getMany()), fn ($s) => (string) $s->getData('submissionProgress') === ''));
        $latest = $this->drafts->latest($cid, array_map(fn ($s) => (int) $s->getId(), $subs));

        $h .= '<div style="' . self::S_BOX . '"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px"><h2 style="margin:0;font-size:17px">' . $this->t('submissions') . '</h2>'
            . '<form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:flex;gap:6px"><input name="q" value="' . self::e($q) . '" placeholder="' . self::e($this->t('search')) . '" style="' . self::S_INP . 'width:220px"><button style="' . self::S_BTN . '">' . $this->t('searchBtn') . '</button></form></div>';
        $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:45px">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th><th style="' . self::S_TH . 'width:260px">' . $this->t('col.metadata') . '</th><th style="' . self::S_TH . 'width:330px">' . $this->t('col.action') . '</th></tr></thead><tbody>';
        foreach ($subs as $s) {
            $pub = $s->getCurrentPublication(); if (!$pub) continue;
            $sid = (int) $s->getId(); $primary = (string) $s->getData('locale');
            $target = $primary === 'en' ? 'ar' : 'en';
            $hasTarget = self::meta($pub, $target)['abstract'] !== '';
            $comp = '';
            foreach (array_unique([$primary, $target]) as $l) $comp .= '<div>' . $this->completeness($pub, $l) . '</div>';
            $opts = '';
            foreach (array_unique([$primary, $target, 'ar', 'en']) as $from) foreach (array_unique([$target, $primary, 'en', 'ar']) as $to) {
                if ($from === $to || !in_array($from, $locales, true) || !in_array($to, $locales, true)) continue;
                $v = 'translate:' . $from . ':' . $to;
                $opts .= '<option value="' . $v . '"' . (!$hasTarget && $from === $primary && $to === $target ? ' selected' : '') . '>' . self::e($this->t('mode.translate', ['from' => $this->lang($from), 'to' => $this->lang($to)])) . '</option>';
            }
            foreach (array_unique([$target, $primary]) as $to) {
                $from = $to === $primary ? $target : $primary;
                if (self::meta($pub, $to)['abstract'] === '') continue;
                $opts .= '<option value="polish:' . $from . ':' . $to . '"' . ($hasTarget && $to === $target ? ' selected' : '') . '>' . self::e($this->t('mode.polish', ['to' => $this->lang($to)])) . '</option>';
            }
            $last = $latest[$sid] ?? null;
            $lastHtml = $last ? '<div style="font-size:12px;margin-top:4px"><a href="' . self::e($this->url($request, 'review', ['draftId' => $last->draft_id])) . '">' . $this->t('lastDraft', ['s' => $this->t('status.' . $last->status), 'd' => substr((string) $last->created_at, 0, 10)]) . '</a></div>' : '';
            $h .= '<tr><td style="' . self::S_TD . '">' . $sid . '</td><td style="' . self::S_TD . '"><b>' . self::e(strip_tags((string) $pub->getLocalizedFullTitle())) . '</b><div style="font-size:12px;color:#666">' . self::e(strip_tags((string) $pub->getShortAuthorString())) . ' · ' . self::e($this->t('pubStatus.' . ((int) $pub->getData('status') === PKPSubmission::STATUS_PUBLISHED ? 'published' : 'draft'))) . '</div></td>'
                . '<td style="' . self::S_TD . '">' . $comp . '</td><td style="' . self::S_TD . '"><form method="post" action="' . self::e($this->url($request, 'generate')) . '" style="display:flex;gap:4px;flex-wrap:wrap;margin:0">' . $this->csrf($request)
                . '<input type="hidden" name="submissionId" value="' . $sid . '"><select name="task" style="' . self::S_INP . 'width:210px">' . $opts . '</select><button style="' . self::S_BTNP . '"' . ($cfg['apiKey'] === '' ? ' disabled' : '') . '>' . $this->t('generate') . '</button></form>' . $lastHtml . '</td></tr>';
        }
        $h .= '</tbody></table></div><div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('privacy') . '</div></div>';
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request, $cfg);
        $this->show($request, $this->t('menu'), $h);
    }

    private function settingsForm($request, array $cfg): string
    {
        $u = $this->drafts->usage($this->cid($request));
        return '<details style="' . self::S_BOX . '"' . ($cfg['apiKey'] === '' ? ' open' : '') . '><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary>'
            . '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:10px;display:grid;gap:10px;max-width:720px">' . $this->csrf($request)
            . '<label>' . $this->t('set.apiKey') . '<br><input type="password" name="apiKey" value="' . ($cfg['apiKey'] !== '' ? '••••••••' : '') . '" autocomplete="new-password" dir="ltr" style="' . self::S_INP . 'width:100%"><div style="font-size:12px;color:#555">' . $this->t('set.apiKeyHelp') . '</div></label>'
            . '<label>' . $this->t('set.model') . '<br><input name="model" value="' . self::e($cfg['model']) . '" dir="ltr" style="' . self::S_INP . 'width:260px"></label>'
            . '<label>' . $this->t('set.endpoint') . '<br><input name="endpoint" value="' . self::e($cfg['endpoint']) . '" dir="ltr" style="' . self::S_INP . 'width:100%"></label>'
            . '<label>' . $this->t('set.field') . '<br><input name="field" value="' . self::e($cfg['field']) . '" dir="ltr" style="' . self::S_INP . 'width:100%"></label>'
            . '<label>' . $this->t('set.glossary') . '<br><textarea name="glossary" rows="7" style="' . self::S_INP . 'width:100%" placeholder="التدبر = tadabbur (reflective contemplation)&#10;علوم القرآن = Qur’anic sciences">' . self::e($cfg['glossaryRaw']) . '</textarea><div style="font-size:12px;color:#555">' . $this->t('set.glossaryHelp') . '</div></label>'
            . '<div><button style="' . self::S_BTNP . '">' . $this->t('save') . '</button> <span style="font-size:12px;color:#555">' . $this->t('usage', ['n' => $u['n'], 'i' => number_format($u['in']), 'o' => number_format($u['out'])]) . '</span></div></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->post($request, true);
        $cid = $this->cid($request);
        $key = trim((string) $request->getUserVar('apiKey'));
        if ($key !== '••••••••') $this->plugin->updateSetting($cid, 'apiKey', $key, 'string');
        $this->plugin->updateSetting($cid, 'model', preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $request->getUserVar('model')) ?: 'claude-sonnet-5-5', 'string');
        $ep = trim((string) $request->getUserVar('endpoint'));
        $this->plugin->updateSetting($cid, 'endpoint', preg_match('#^https?://#', $ep) ? $ep : 'https://api.anthropic.com/v1/messages', 'string');
        $this->plugin->updateSetting($cid, 'field', mb_substr(trim((string) $request->getUserVar('field')), 0, 200), 'string');
        $this->plugin->updateSetting($cid, 'glossary', mb_substr((string) $request->getUserVar('glossary'), 0, 20000), 'string');
        $request->redirectUrl($this->url($request, 'index', ['saved' => 1]));
    }

    // ================================================================ التوليد
    public function generate($args, $request)
    {
        $this->post($request);
        $cid = $this->cid($request); $cfg = $this->plugin->cfg($cid); $context = $request->getContext();
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $cid) { http_response_code(404); exit; }
        [$mode, $from, $to] = array_pad(explode(':', (string) $request->getUserVar('task')), 3, '');
        if (!in_array($mode, ['translate', 'polish'], true) || !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $from) || !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $to) || $from === $to) { $request->redirectUrl($this->url($request, 'index')); return; }
        $pub = $sub->getCurrentPublication();
        $src = self::meta($pub, $from);
        if ($src['title'] === '' && $src['abstract'] === '') { $request->redirectUrl($this->url($request, 'index', ['err' => $this->t('emptySource')])); return; }
        @set_time_limit(180);
        try {
            $tr = new Translator($cfg['apiKey'], $cfg['model'], $cfg['endpoint'], (string) $context->getLocalizedName(), $cfg['field'], $cfg['glossary']);
            $r = $tr->run($mode, $from, $to, $src, $mode === 'polish' ? self::meta($pub, $to) : null);
        } catch (\Throwable $e) {
            $request->redirectUrl($this->url($request, 'index', ['err' => $e->getMessage()])); return;
        }
        $id = $this->drafts->add(['context_id' => $cid, 'submission_id' => (int) $sub->getId(), 'publication_id' => (int) $pub->getId(), 'mode' => $mode, 'source_locale' => $from, 'target_locale' => $to,
            'source' => json_encode($src, JSON_UNESCAPED_UNICODE), 'draft' => json_encode($r['data'], JSON_UNESCAPED_UNICODE), 'model' => mb_substr($r['model'], 0, 80),
            'created_by' => (int) $request->getUser()->getId(), 'tokens_in' => $r['in'], 'tokens_out' => $r['out']]);
        $request->redirectUrl($this->url($request, 'review', ['draftId' => $id]));
    }

    // ================================================================ المراجعة
    public function review($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request);
        $d = $this->drafts->get((int) $request->getUserVar('draftId'), $cid);
        if (!$d) { http_response_code(404); echo self::e($this->t('notFound')); exit; }
        $sub = Repo::submission()->get((int) $d->submission_id);
        $pub = Repo::publication()->get((int) $d->publication_id);
        $src = json_decode((string) $d->source, true) ?: []; $dr = json_decode((string) $d->draft, true) ?: [];
        $cur = $pub ? self::meta($pub, (string) $d->target_locale) : [];
        $published = $pub && (int) $pub->getData('status') === PKPSubmission::STATUS_PUBLISHED;
        $rtl = fn ($l) => in_array(substr($l, 0, 2), ['ar', 'fa', 'ur'], true) ? 'rtl' : 'ltr';
        $dirS = $rtl((string) $d->source_locale); $dirT = $rtl((string) $d->target_locale);

        $h = '<div style="margin-bottom:12px"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a></div>';
        if ($request->getUserVar('applied')) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t('appliedMsg') . '</b></div>';
        $h .= '<div style="' . self::S_BOX . '"><b>' . self::e($pub ? strip_tags((string) $pub->getLocalizedFullTitle()) : '') . '</b><div style="font-size:12px;color:#666">'
            . self::e($this->t($d->mode === 'polish' ? 'mode.polish' : 'mode.translate', ['from' => $this->lang((string) $d->source_locale), 'to' => $this->lang((string) $d->target_locale)]))
            . ' · ' . self::e($d->model) . ' · ' . self::e(substr((string) $d->created_at, 0, 16)) . ' · ' . $this->t('status.' . $d->status) . '</div></div>';
        if (!empty($dr['notes'])) {
            $h .= '<div style="' . self::S_BOX . 'border-color:#a86a00;background:#fffaf0"><b>' . $this->t('notes') . '</b><ul style="margin:6px 0">';
            foreach ($dr['notes'] as $n) $h .= '<li>' . self::e($n) . '</li>';
            $h .= '</ul></div>';
        }
        if ($published) $h .= '<div style="' . self::S_BOX . 'border-color:#33658a;background:#eef4fa">' . $this->t('publishedNote') . '</div>';

        $editable = $d->status === 'draft' && !$published;
        $h .= '<form method="post" action="' . self::e($this->url($request, 'apply')) . '">' . $this->csrf($request) . '<input type="hidden" name="draftId" value="' . (int) $d->draft_id . '">'
            . '<div style="' . self::S_BOX . '"><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:110px">' . $this->t('col.field') . '</th><th style="' . self::S_TH . '">' . $this->t('col.source', ['l' => $this->lang((string) $d->source_locale)]) . '</th>'
            . '<th style="' . self::S_TH . '">' . $this->t('col.current', ['l' => $this->lang((string) $d->target_locale)]) . '</th><th style="' . self::S_TH . 'width:38%">' . $this->t('col.draft') . '</th></tr></thead><tbody>';
        foreach (self::FIELDS as $f) {
            $sv = $f === 'keywords' ? implode('؛ ', (array) ($src[$f] ?? [])) : (string) ($src[$f] ?? '');
            $cv = $f === 'keywords' ? implode('؛ ', (array) ($cur[$f] ?? [])) : (string) ($cur[$f] ?? '');
            $dv = isset($dr['final'][$f]) ? (string) $dr['final'][$f] : ($f === 'keywords' ? implode('; ', (array) ($dr[$f] ?? [])) : (string) ($dr[$f] ?? ''));
            if ($sv === '' && $dv === '' && $cv === '') continue;
            $rows = $f === 'abstract' ? 12 : 2;
            $cell = $editable
                ? '<label style="display:flex;gap:6px;align-items:center;font-size:12px;margin-bottom:4px"><input type="checkbox" name="use[]" value="' . $f . '"' . ($dv !== '' && ($cv === '' || $d->mode === 'polish') ? ' checked' : '') . '> ' . $this->t('applyField') . '</label><textarea name="v[' . $f . ']" rows="' . $rows . '" dir="' . $dirT . '" style="' . self::S_INP . 'width:100%;font-size:14px;line-height:1.6">' . self::e($dv) . '</textarea>'
                : '<div dir="' . $dirT . '" style="white-space:pre-wrap">' . self::e($dv) . '</div>';
            $h .= '<tr><td style="' . self::S_TD . '"><b>' . $this->t('f.' . $f) . '</b>' . ($f === 'abstract' ? '<div style="font-size:11px;color:#666">' . $this->t('words', ['s' => self::words($sv), 'd' => self::words($dv)]) . '</div>' : '') . '</td>'
                . '<td style="' . self::S_TD . '"><div dir="' . $dirS . '" style="white-space:pre-wrap;color:#333">' . self::e($sv) . '</div></td><td style="' . self::S_TD . '"><div dir="' . $dirT . '" style="white-space:pre-wrap;color:#666">' . ($cv !== '' ? self::e($cv) : '<em>' . $this->t('empty') . '</em>') . '</div></td><td style="' . self::S_TD . '">' . $cell . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
        if ($editable) {
            $h .= '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center"><button style="' . self::S_BTNP . '">✔ ' . $this->t('apply') . '</button><span style="font-size:12px;color:#555">' . $this->t('applyNote') . '</span></div>';
        }
        $h .= '</div></form>';
        if ($d->status === 'draft') {
            $h .= '<form method="post" action="' . self::e($this->url($request, 'discard')) . '">' . $this->csrf($request) . '<input type="hidden" name="draftId" value="' . (int) $d->draft_id . '"><button style="' . self::S_BTN . 'border-color:#b02a2a;color:#b02a2a">' . $this->t('discard') . '</button></form>';
        }
        // المسودات السابقة
        $all = $sub ? $this->drafts->forSubmission($cid, (int) $sub->getId()) : [];
        if (count($all) > 1) {
            $h .= '<div style="' . self::S_BOX . 'margin-top:16px"><b>' . $this->t('history') . '</b><ul style="margin:6px 0">';
            foreach ($all as $o) $h .= '<li><a href="' . self::e($this->url($request, 'review', ['draftId' => $o->draft_id])) . '">' . self::e(substr((string) $o->created_at, 0, 16) . ' — ' . $o->source_locale . '→' . $o->target_locale . ' — ' . $this->t('status.' . $o->status)) . '</a></li>';
            $h .= '</ul></div>';
        }
        $this->show($request, $this->t('reviewTitle'), $h);
    }

    private static function words(string $s): int
    {
        return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY));
    }

    public function apply($args, $request)
    {
        $this->post($request);
        $cid = $this->cid($request);
        $d = $this->drafts->get((int) $request->getUserVar('draftId'), $cid);
        if (!$d || $d->status !== 'draft') { http_response_code(404); exit; }
        $pub = Repo::publication()->get((int) $d->publication_id);
        if (!$pub || (int) $pub->getData('status') === PKPSubmission::STATUS_PUBLISHED) { $request->redirectUrl($this->url($request, 'review', ['draftId' => $d->draft_id])); return; }
        $to = (string) $d->target_locale;
        $use = array_intersect(self::FIELDS, (array) $request->getUserVar('use'));
        $v = (array) $request->getUserVar('v');
        $params = [];
        foreach ($use as $f) {
            $val = trim((string) ($v[$f] ?? ''));
            if ($f === 'keywords') {
                $list = array_values(array_unique(array_filter(array_map(fn ($k) => trim($k, " \t.،,"), preg_split('/[;؛\n]+/u', $val)))));
                $all = (array) ($pub->getData('keywords') ?? []);
                $all[$to] = $list;                // تُحفظ كلمات اللغات الأخرى كما هي
                $params['keywords'] = $all;
            } elseif ($f === 'abstract') {
                $paras = array_filter(array_map('trim', preg_split("/\n\s*\n|\n/u", $val)));
                $params['abstract'] = array_merge((array) $pub->getData('abstract'), [$to => $paras ? '<p>' . implode('</p><p>', array_map(fn ($p) => htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), $paras)) . '</p>' : '']);
            } else {
                $params[$f] = array_merge((array) $pub->getData($f), [$to => $val]);
            }
        }
        if ($params) {
            Repo::publication()->edit($pub, $params);
            $edited = json_decode((string) $d->draft, true) ?: [];
            foreach ($use as $f) $edited['final'][$f] = $v[$f] ?? '';
            $this->drafts->update((int) $d->draft_id, $cid, ['status' => 'applied', 'applied_by' => (int) $request->getUser()->getId(), 'applied_at' => date('Y-m-d H:i:s'), 'draft' => json_encode($edited, JSON_UNESCAPED_UNICODE)]);
        }
        $request->redirectUrl($this->url($request, 'review', ['draftId' => $d->draft_id, 'applied' => 1]));
    }

    public function discard($args, $request)
    {
        $this->post($request);
        $cid = $this->cid($request);
        $d = $this->drafts->get((int) $request->getUserVar('draftId'), $cid);
        if ($d && $d->status === 'draft') $this->drafts->update((int) $d->draft_id, $cid, ['status' => 'discarded']);
        $request->redirectUrl($this->url($request, 'index'));
    }
}
