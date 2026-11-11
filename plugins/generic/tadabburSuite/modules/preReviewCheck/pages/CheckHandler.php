<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/preReviewCheck/pages/CheckHandler.php
 * /preReviewCheck (القائمة)، /report?checkId=، و POST: run، send، saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\preReviewCheck\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\preReviewCheck\classes\Checker;
use APP\plugins\generic\tadabburSuite\m\preReviewCheck\classes\Store;
use APP\plugins\generic\tadabburSuite\m\preReviewCheck\PreReviewCheckPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPApplication;
use PKP\log\SubmissionEmailLogEventType;
use PKP\mail\Mailable;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class CheckHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:8px;text-align:start;vertical-align:bottom;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';

    private Store $store;

    public function __construct(private PreReviewCheckPlugin $plugin) { parent::__construct(); $this->store = new Store(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = [], ?string $locale = null): string { return __('plugins.generic.preReviewCheck.' . $k, $p, $locale); }
    private function url($request, string $op, array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, PreReviewCheckPlugin::PAGE, $op, null, $params);
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
    private function cid($request): int { return (int) $request->getContext()->getId(); }
    private function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    private function badge(int $req, int $rec): string
    {
        if ($req) return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#b02a2a;color:#fff;font-weight:700;font-size:12px">✖ ' . $this->t('badge.blocking', ['n' => $req]) . '</span>';
        if ($rec) return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#a86a00;color:#fff;font-weight:700;font-size:12px">▲ ' . $this->t('badge.warnings', ['n' => $rec]) . '</span>';
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#1e7b4a;color:#fff;font-weight:700;font-size:12px">✔ ' . $this->t('badge.ready') . '</span>';
    }

    // ================================================================ القائمة
    public function index($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request);
        $col = Repo::submission()->getCollector()->filterByContextIds([$cid])->filterByStageIds([WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW])
            ->filterByStatus([\PKP\submission\PKPSubmission::STATUS_QUEUED])->limit(50)->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        $subs = array_values(array_filter(iterator_to_array($col->getMany()), fn ($s) => (string) $s->getData('submissionProgress') === ''));
        $latest = $this->store->latest($cid, array_map(fn ($s) => (int) $s->getId(), $subs));
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>';
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 10px;font-size:17px">' . $this->t('queue') . '</h2>';
        if (!$subs) $h .= '<p>' . $this->t('none') . '</p>';
        else {
            $h .= '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . 'width:45px">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th><th style="' . self::S_TH . 'width:100px">' . $this->t('col.stage') . '</th><th style="' . self::S_TH . 'width:230px">' . $this->t('col.result') . '</th><th style="' . self::S_TH . 'width:110px"></th></tr></thead><tbody>';
            foreach ($subs as $s) {
                $sid = (int) $s->getId(); $pub = $s->getCurrentPublication(); $l = $latest[$sid] ?? null;
                $res = $l ? $this->badge((int) $l->failed_req, (int) $l->failed_rec) . '<div style="font-size:12px;color:#666;margin-top:3px">' . self::e(substr((string) $l->created_at, 0, 16)) . ((int) $l->auto ? ' · ' . $this->t('auto') : '') . ($l->sent_at ? ' · ' . $this->t('sent') : '') . '</div><a style="font-size:12px" href="' . self::e($this->url($request, 'report', ['checkId' => $l->check_id])) . '">' . $this->t('open') . '</a>'
                    : '<span style="color:#888">' . $this->t('notChecked') . '</span>';
                $h .= '<tr><td style="' . self::S_TD . '">' . $sid . '</td><td style="' . self::S_TD . '"><b>' . self::e(strip_tags((string) $pub?->getLocalizedFullTitle())) . '</b><div style="font-size:12px;color:#666">' . self::e(strip_tags((string) $pub?->getShortAuthorString())) . ' · ' . self::e(substr((string) $s->getData('dateSubmitted'), 0, 10)) . '</div></td>'
                    . '<td style="' . self::S_TD . '">' . self::e($this->t('stage.' . (int) $s->getData('stageId'))) . '</td><td style="' . self::S_TD . '">' . $res . '</td>'
                    . '<td style="' . self::S_TD . '"><form method="post" action="' . self::e($this->url($request, 'run')) . '" style="margin:0">' . $this->csrf($request) . '<input type="hidden" name="submissionId" value="' . $sid . '"><button style="' . self::S_BTNP . '">' . $this->t($l ? 'rerun' : 'run') . '</button></form></td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '</div>';
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request);
        $this->show($request, $this->t('menu'), $h);
    }

    private function settingsForm($request): string
    {
        $c = $this->plugin->cfg($this->cid($request));
        $num = fn ($n, $l) => '<label style="display:inline-block;margin:4px 14px 4px 0">' . $l . '<br><input type="number" min="0" name="' . $n . '" value="' . (int) $c[$n] . '" style="' . self::S_INP . 'width:100px"></label>';
        $cb = fn ($n, $l) => '<label style="display:block;margin:4px 0"><input type="checkbox" name="' . $n . '" value="1"' . ($c[$n] ? ' checked' : '') . '> ' . $l . '</label>';
        $orcid = '<select name="orcid" style="' . self::S_INP . '">';
        foreach (['corresponding', 'all', 'none'] as $o) $orcid .= '<option value="' . $o . '"' . ($c['orcid'] === $o ? ' selected' : '') . '>' . $this->t('orcid.' . $o) . '</option>';
        $orcid .= '</select>';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary><form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:10px">' . $this->csrf($request)
            . $cb('autoRun', $this->t('set.autoRun')) . $cb('blind', $this->t('set.blind')) . $cb('checkDeclarations', $this->t('set.declarations')) . $cb('romanRefs', $this->t('set.romanRefs'))
            . '<div style="margin-top:6px">' . $num('wordsMin', $this->t('set.wordsMin')) . $num('wordsMax', $this->t('set.wordsMax')) . $num('refsMin', $this->t('set.refsMin')) . '</div>'
            . '<div>' . $num('absMin', $this->t('set.absMin')) . $num('absMax', $this->t('set.absMax')) . $num('kwMin', $this->t('set.kwMin')) . $num('kwMax', $this->t('set.kwMax')) . $num('titleMax', $this->t('set.titleMax')) . '</div>'
            . '<label style="display:inline-block;margin:4px 14px 4px 0">' . $this->t('set.locales') . '<br><input name="locales" value="' . self::e(implode(',', $c['locales'])) . '" dir="ltr" style="' . self::S_INP . 'width:120px"></label>'
            . '<label style="display:inline-block;margin:4px 14px 4px 0">' . $this->t('set.fileTypes') . '<br><input name="fileTypes" value="' . self::e(implode(',', $c['fileTypes'])) . '" dir="ltr" style="' . self::S_INP . 'width:120px"></label>'
            . '<label style="display:inline-block;margin:4px 14px 4px 0">' . $this->t('set.orcid') . '<br>' . $orcid . '</label>'
            . '<label style="display:block;margin:6px 0">' . $this->t('set.headings') . '<br><textarea name="headings" rows="4" style="' . self::S_INP . 'width:320px">' . self::e($c['headingsRaw']) . '</textarea></label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->post($request, true);
        $cid = $this->cid($request);
        foreach (['autoRun', 'blind', 'checkDeclarations', 'romanRefs'] as $k) $this->plugin->updateSetting($cid, $k, (bool) $request->getUserVar($k), 'bool');
        foreach (['wordsMin', 'wordsMax', 'refsMin', 'absMin', 'absMax', 'kwMin', 'kwMax', 'titleMax'] as $k) $this->plugin->updateSetting($cid, $k, max(0, (int) $request->getUserVar($k)), 'int');
        $this->plugin->updateSetting($cid, 'locales', array_values(array_filter(array_map('trim', explode(',', (string) $request->getUserVar('locales'))), fn ($l) => preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $l))), 'object');
        $this->plugin->updateSetting($cid, 'fileTypes', implode(',', array_filter(array_map(fn ($x) => strtolower(trim($x)), explode(',', (string) $request->getUserVar('fileTypes'))), fn ($x) => preg_match('/^[a-z0-9]{2,5}$/', $x))), 'string');
        $this->plugin->updateSetting($cid, 'orcid', in_array($request->getUserVar('orcid'), ['corresponding', 'all', 'none'], true) ? $request->getUserVar('orcid') : 'corresponding', 'string');
        $this->plugin->updateSetting($cid, 'headings', mb_substr((string) $request->getUserVar('headings'), 0, 2000), 'string');
        $request->redirectUrl($this->url($request, 'index'));
    }

    public function run($args, $request)
    {
        $this->post($request);
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $this->cid($request)) { http_response_code(404); exit; }
        $r = $this->plugin->runFor($sub, null, false);
        $request->redirectUrl($this->url($request, 'report', ['checkId' => $r['id']]));
    }

    // ================================================================ التقرير
    private function line(array $c, string $locale): string
    {
        $p = $c['p'] ?? [];
        if (isset($p['lang'])) $p['lang'] = $this->t('lang.' . substr($p['lang'], 0, 2), [], $locale);
        if (isset($p['scope'])) $p['scope'] = $this->t('orcid.' . $p['scope'], [], $locale);
        $p['v'] = $c['detail'];
        return $this->t('msg.' . $c['id'], $p, $locale);
    }

    public function report($args, $request)
    {
        $this->gate($request);
        $cid = $this->cid($request);
        $row = $this->store->get((int) $request->getUserVar('checkId'), $cid);
        if (!$row) { http_response_code(404); echo self::e($this->t('notFound')); exit; }
        $res = json_decode((string) $row->result, true) ?: ['checks' => [], 'stats' => []];
        $sub = Repo::submission()->get((int) $row->submission_id); $pub = $sub?->getCurrentPublication();
        $h = '<div style="margin-bottom:12px"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a></div>';
        if ($request->getUserVar('sent')) $h .= '<div style="' . self::S_BOX . 'border-color:#1e7b4a;background:#eef8f2"><b>' . $this->t('sentMsg') . '</b></div>';
        if ($request->getUserVar('mailerr')) $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1"><b>' . $this->t('mailError') . '</b> ' . self::e((string) $request->getUserVar('mailerr')) . '</div>';
        $st = $res['stats'];
        $wf = $sub ? $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $sub->getId()]) : '#';
        $h .= '<div style="' . self::S_BOX . 'display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><a href="' . self::e($wf) . '"><b>' . self::e(strip_tags((string) $pub?->getLocalizedFullTitle())) . '</b></a><div style="font-size:12px;color:#666">'
            . self::e($this->t('meta', ['file' => $row->file_name ?: '—', 'date' => substr((string) $row->created_at, 0, 16)])) . '</div></div><div>' . $this->badge((int) $row->failed_req, (int) $row->failed_rec) . '</div></div>';
        if (isset($st['words']) && $st['words'] !== null) {
            $h .= '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">';
            foreach (['words' => 'stat.words', 'footnotes' => 'stat.footnotes', 'refs' => 'stat.refs'] as $k => $l) $h .= '<div style="flex:1;min-width:140px;' . self::S_BOX . 'margin:0"><div style="font-size:22px;font-weight:700;color:#0b4f4a">' . number_format((int) $st[$k]) . '</div><div style="font-size:13px">' . $this->t($l) . '</div></div>';
            $h .= '</div>';
        }
        $rows = '';
        $order = fn ($c) => ($c['ok'] ? 2 : 0) + ($c['level'] === Checker::REQ ? 0 : 1);
        $checks = $res['checks']; usort($checks, fn ($a, $b) => $order($a) <=> $order($b));
        foreach ($checks as $c) {
            $icon = $c['ok'] ? '<span style="color:#1e7b4a;font-weight:700">✔</span>' : ($c['level'] === Checker::REQ ? '<span style="color:#b02a2a;font-weight:700">✖</span>' : '<span style="color:#a86a00;font-weight:700">▲</span>');
            $extra = $c['id'] === 'quran' && !empty($c['p']['reportId']) ? ' <a href="' . self::e($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'quranVerifier', 'report', null, ['reportId' => $c['p']['reportId']])) . '">' . $this->t('openQuran') . '</a>' : '';
            $rows .= '<tr><td style="' . self::S_TD . 'text-align:center;width:40px">' . $icon . '</td><td style="' . self::S_TD . '"><b>' . self::e($this->t('check.' . $c['id'])) . '</b>' . (isset($c['p']['lang']) ? ' <span style="font-size:11px;color:#666">(' . self::e($this->t('lang.' . substr($c['p']['lang'], 0, 2))) . ')</span>' : '') . '</td>'
                . '<td style="' . self::S_TD . '">' . ($c['ok'] ? '<span style="color:#666">' . self::e($c['detail']) . '</span>' : self::e($this->line($c, \PKP\facades\Locale::getLocale()))) . $extra . '</td>'
                . '<td style="' . self::S_TD . 'width:90px;font-size:12px">' . $this->t('lvl.' . $c['level']) . '</td></tr>';
        }
        $h .= '<div style="' . self::S_BOX . '"><div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '"></th><th style="' . self::S_TH . '">' . $this->t('col.check') . '</th><th style="' . self::S_TH . '">' . $this->t('col.detail') . '</th><th style="' . self::S_TH . '">' . $this->t('col.level') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';

        // ---- رسالة المؤلف
        $failed = array_values(array_filter($res['checks'], fn ($c) => !$c['ok']));
        if ($failed && $sub && $pub) {
            $author = null;
            foreach ($pub->getData('authors') ?? [] as $a) if ((int) $a->getId() === (int) $pub->getData('primaryContactId')) $author = $a;
            $name = $author ? $author->getFullName() : '';
            $body = $this->t('mail.greeting', ['name' => $name], 'ar') . "\n\n" . $this->t('mail.intro', ['title' => strip_tags((string) $pub->getLocalizedFullTitle('ar')) ?: strip_tags((string) $pub->getLocalizedFullTitle())], 'ar') . "\n";
            foreach ($failed as $c) $body .= '• ' . $this->line($c, 'ar') . ($c['level'] === Checker::REQ ? '' : ' ' . $this->t('mail.recommended', [], 'ar')) . "\n";
            $body .= "\n" . $this->t('mail.outro', [], 'ar') . "\n\n— — —\n\n" . $this->t('mail.greeting', ['name' => $author ? $author->getFullName(true, false, 'en') : ''], 'en') . "\n\n" . $this->t('mail.intro', ['title' => strip_tags((string) $pub->getLocalizedFullTitle('en'))], 'en') . "\n";
            foreach ($failed as $c) $body .= '• ' . $this->line($c, 'en') . ($c['level'] === Checker::REQ ? '' : ' ' . $this->t('mail.recommended', [], 'en')) . "\n";
            $body .= "\n" . $this->t('mail.outro', [], 'en') . "\n\n" . $request->getContext()->getLocalizedName();
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:17px">' . $this->t('mail.title') . '</h2>'
                . '<form method="post" action="' . self::e($this->url($request, 'send')) . '">' . $this->csrf($request) . '<input type="hidden" name="checkId" value="' . (int) $row->check_id . '">'
                . '<div style="margin-bottom:6px">' . $this->t('mail.to') . ': <b>' . self::e($name) . '</b> <span dir="ltr">&lt;' . self::e($author?->getData('email')) . '&gt;</span></div>'
                . '<input name="subject" value="' . self::e($this->t('mail.subject', ['id' => $sub->getId()], 'ar') . ' | ' . $this->t('mail.subject', ['id' => $sub->getId()], 'en')) . '" style="' . self::S_INP . 'width:100%;margin-bottom:6px">'
                . '<textarea name="body" rows="16" style="' . self::S_INP . 'width:100%;font-size:14px;line-height:1.7">' . self::e($body) . '</textarea>'
                . '<div style="margin-top:8px"><button style="' . self::S_BTNP . '"' . ($author && $author->getData('email') ? '' : ' disabled') . '>✉ ' . $this->t('mail.send') . '</button> <span style="font-size:12px;color:#555">' . $this->t('mail.note') . '</span></div></form></div>';
        }
        $this->show($request, $this->t('reportTitle'), $h);
    }

    public function send($args, $request)
    {
        $this->post($request);
        $cid = $this->cid($request); $context = $request->getContext();
        $row = $this->store->get((int) $request->getUserVar('checkId'), $cid);
        if (!$row) { http_response_code(404); exit; }
        $sub = Repo::submission()->get((int) $row->submission_id); $pub = $sub->getCurrentPublication();
        $author = null;
        foreach ($pub->getData('authors') ?? [] as $a) if ((int) $a->getId() === (int) $pub->getData('primaryContactId')) $author = $a;
        if (!$author || !$author->getData('email')) { $request->redirectUrl($this->url($request, 'report', ['checkId' => $row->check_id])); return; }
        $subject = mb_substr(trim((string) $request->getUserVar('subject')), 0, 250);
        $body = trim((string) $request->getUserVar('body'));
        $html = '<div dir="auto">' . nl2br(self::e($body)) . '</div>';
        try {
            $mailable = new Mailable([$context, $sub]);
            $mailable->from((string) $context->getData('contactEmail'), (string) $context->getData('contactName'))
                ->to((string) $author->getData('email'), (string) $author->getFullName())
                ->subject($subject)->body($html);
            Mail::send($mailable);
            Repo::emailLogEntry()->logMailable(SubmissionEmailLogEventType::EDITOR_NOTIFY_AUTHOR, $mailable, $sub, $request->getUser());
            $this->store->markSent((int) $row->check_id, (int) $request->getUser()->getId());
        } catch (\Throwable $e) {
            $request->redirectUrl($this->url($request, 'report', ['checkId' => $row->check_id, 'mailerr' => mb_substr($e->getMessage(), 0, 200)])); return;
        }
        $request->redirectUrl($this->url($request, 'report', ['checkId' => $row->check_id, 'sent' => 1]));
    }
}
