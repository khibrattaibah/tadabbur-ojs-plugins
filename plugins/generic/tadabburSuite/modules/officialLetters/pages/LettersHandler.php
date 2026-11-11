<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/officialLetters/pages/LettersHandler.php
 * للمحررين: /officialLetters، /submission?submissionId=، و POST: issue، revoke، saveSettings
 * للمؤلف: /mine، و POST: selfIssue
 * للجميع: /download?letterId= (المحرر أو مؤلف البحث)، /verify/{code} (عامة)
 */
namespace APP\plugins\generic\tadabburSuite\m\officialLetters\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Letters;
use APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Store;
use APP\plugins\generic\tadabburSuite\m\officialLetters\OfficialLettersPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Role;
use PKP\security\Validation;

class LettersHandler extends Handler
{
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:8px;padding:14px 16px;margin:0 0 16px;background:#fff;';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff;';
    private const S_TH = 'background:#f1f3f5;border:1px solid #d5dbe1;padding:7px;text-align:start;vertical-align:bottom;white-space:nowrap;';
    private const S_TD = 'border:1px solid #d5dbe1;padding:7px;vertical-align:top;';
    private const S_BTN = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:4px 11px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:12px;cursor:pointer;text-decoration:none;line-height:1.5;';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px;max-width:100%;';

    private Store $store;

    public function __construct(private OfficialLettersPlugin $plugin) { parent::__construct(); $this->store = new Store(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.officialLetters.' . $k, $p); }
    private function url($request, string $op, array $params = [], ?array $path = null): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, OfficialLettersPlugin::PAGE, $op, $path, $params);
    }
    private function cid($request): int { return (int) $request->getContext()->getId(); }
    private function csrf($request): string { return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">'; }
    private function login($request): void { if (!$request->getUser()) { Validation::redirectLogin(); exit; } }
    private function deny(): void { http_response_code(403); echo self::e($this->t('forbidden')); exit; }
    private function postOk($request): void { if (!$request->isPost() || !$request->checkCSRF()) { http_response_code(400); echo 'Bad request'; exit; } }
    private function show($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'pluginHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    /** هل المستخدم مؤلف في هذا البحث؟ */
    private function isAuthorOf($request, $submission): bool
    {
        $u = $request->getUser(); if (!$u) return false;
        if (DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->where('sa.submission_id', $submission->getId())
            ->where('sa.user_id', $u->getId())->where('ug.role_id', Role::ROLE_ID_AUTHOR)->exists()) return true;
        foreach ($submission->getCurrentPublication()->getData('authors') ?? [] as $a) if (mb_strtolower((string) $a->getData('email')) === mb_strtolower((string) $u->getEmail())) return true;
        return false;
    }

    private function issueFor($request, $submission, string $type, string $lang, bool $force = false): object
    {
        $cid = $this->cid($request);
        if (!$force && ($cur = $this->store->current($cid, (int) $submission->getId(), $type, $lang))) return $cur;
        $journalUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$submission->getBestId()]);
        $data = (new Letters($this->plugin->getPluginPath() === '' ? '' : Core::getBaseDir() . '/' . $this->plugin->getPluginPath()))->snapshot($submission, $request->getContext(), $lang, $journalUrl);
        return $this->store->add($cid, (int) $submission->getId(), $type, $lang, $data, (int) $request->getUser()->getId());
    }

    private function lettersPanel($request, $sub, bool $editor): string
    {
        $cid = $this->cid($request); $sid = (int) $sub->getId();
        $types = Letters::eligible($sub);
        $h = '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
        foreach (['receipt', 'acceptance', 'publication'] as $type) {
            $ok = in_array($type, $types, true);
            $h .= '<div style="border:1px solid #e1e4e8;border-radius:6px;padding:6px 8px;min-width:150px;' . ($ok ? '' : 'opacity:.45') . '"><div style="font-weight:700;font-size:12px">' . $this->t('type.' . $type) . '</div>';
            if ($ok) foreach (['ar' => 'عربي', 'en' => 'English'] as $lang => $ll) {
                $cur = $this->store->current($cid, $sid, $type, $lang);
                $h .= '<form method="post" action="' . self::e($this->url($request, $editor ? 'issue' : 'selfIssue')) . '" style="display:inline;margin:0">' . $this->csrf($request)
                    . '<input type="hidden" name="submissionId" value="' . $sid . '"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="lang" value="' . $lang . '">'
                    . '<button style="' . ($cur ? self::S_BTN : self::S_BTNP) . '" title="' . self::e($cur ? $cur->number : '') . '">⬇ ' . $ll . '</button></form>';
            } else $h .= '<div style="font-size:11px;color:#777">' . $this->t('notYet.' . $type) . '</div>';
            $h .= '</div>';
        }
        return $h . '</div>';
    }

    // ================================================================ المحرر
    public function index($args, $request)
    {
        $this->login($request); if (!$this->plugin->isEditor($request)) $this->deny();
        $cid = $this->cid($request);
        $q = trim((string) $request->getUserVar('q'));
        $col = Repo::submission()->getCollector()->filterByContextIds([$cid])->limit(25)->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        if ($q !== '') ctype_digit($q) ? $col->filterBySubmissionIds([(int) $q]) : $col->searchPhrase($q);
        $h = '';
        if (!Letters::pdfAvailable()) $h .= '<div style="' . self::S_BOX . 'border-color:#b02a2a;background:#fdf1f1">' . $this->t('noMpdf') . '</div>';
        $h .= '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p><div style="' . self::S_BOX . '"><form method="get" action="' . self::e($this->url($request, 'index')) . '" style="display:flex;gap:6px;margin-bottom:10px">'
            . '<input name="q" value="' . self::e($q) . '" placeholder="' . self::e($this->t('search')) . '" style="' . self::S_INP . 'width:280px"><button style="' . self::S_BTNP . '">' . $this->t('searchBtn') . '</button></form>'
            . '<div style="overflow-x:auto"><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.title') . '</th><th style="' . self::S_TH . '">' . $this->t('col.letters') . '</th></tr></thead><tbody>';
        foreach ($col->getMany() as $s) {
            if ((string) $s->getData('submissionProgress') !== '') continue;
            $h .= '<tr><td style="' . self::S_TD . '">' . (int) $s->getId() . '</td><td style="' . self::S_TD . 'min-width:220px"><b>' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</b><div style="font-size:11px;color:#777">' . self::e(strip_tags((string) $s->getCurrentPublication()->getShortAuthorString())) . '</div>'
                . '<a style="font-size:12px" href="' . self::e($this->url($request, 'submission', ['submissionId' => $s->getId()])) . '">' . $this->t('history') . '</a></td><td style="' . self::S_TD . '">' . $this->lettersPanel($request, $s, true) . '</td></tr>';
        }
        $h .= '</tbody></table></div></div>';
        // آخر الصادر
        $rows = $this->store->recent($cid, 15);
        if ($rows) {
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('recent') . '</h2><table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">' . $this->t('col.number') . '</th><th style="' . self::S_TH . '">' . $this->t('col.type') . '</th><th style="' . self::S_TH . '">#</th><th style="' . self::S_TH . '">' . $this->t('col.date') . '</th><th style="' . self::S_TH . '">' . $this->t('col.status') . '</th><th style="' . self::S_TH . '"></th></tr></thead><tbody>';
            foreach ($rows as $r) $h .= $this->letterRow($request, $r);
            $h .= '</tbody></table></div>';
        }
        if ($this->plugin->isManager($request)) $h .= $this->settingsForm($request);
        $this->show($request, $this->t('menu'), $h);
    }

    private function letterRow($request, object $r): string
    {
        $rev = $r->revoked_at ? '<span style="color:#b02a2a;font-weight:700">' . $this->t('revoked') . '</span><div style="font-size:11px">' . self::e($r->revoke_reason) . '</div>' : '<span style="color:#1e7b4a;font-weight:700">' . $this->t('valid') . '</span>';
        $act = '<a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'download', ['letterId' => $r->letter_id])) . '">PDF</a><a style="' . self::S_BTN . '" target="_blank" href="' . self::e($this->url($request, 'verify', [], [$r->code])) . '">' . $this->t('verifyPage') . '</a>';
        if (!$r->revoked_at && $this->plugin->isManager($request)) {
            $act .= '<form method="post" action="' . self::e($this->url($request, 'revoke')) . '" style="display:inline-flex;gap:4px;margin:0">' . $this->csrf($request) . '<input type="hidden" name="letterId" value="' . (int) $r->letter_id . '">'
                . '<input name="reason" required placeholder="' . self::e($this->t('reason')) . '" style="' . self::S_INP . 'width:120px;min-height:24px"><button style="' . self::S_BTN . 'border-color:#b02a2a;color:#b02a2a">' . $this->t('revoke') . '</button></form>';
        }
        return '<tr><td style="' . self::S_TD . 'white-space:nowrap" dir="ltr">' . self::e($r->number) . '</td><td style="' . self::S_TD . '">' . $this->t('type.' . $r->letter_type) . ' (' . strtoupper(self::e($r->lang)) . ')</td><td style="' . self::S_TD . '">' . (int) $r->submission_id . '</td>'
            . '<td style="' . self::S_TD . 'white-space:nowrap">' . self::e(substr((string) $r->issued_at, 0, 16)) . '</td><td style="' . self::S_TD . '">' . $rev . '</td><td style="' . self::S_TD . 'white-space:nowrap">' . $act . '</td></tr>';
    }

    public function submission($args, $request)
    {
        $this->login($request); if (!$this->plugin->isEditor($request)) $this->deny();
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $this->cid($request)) { http_response_code(404); exit; }
        $h = '<div style="margin-bottom:12px"><a style="' . self::S_BTN . '" href="' . self::e($this->url($request, 'index')) . '">← ' . $this->t('back') . '</a></div><div style="' . self::S_BOX . '"><b>#' . (int) $sub->getId() . ' ' . self::e(strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle())) . '</b><div style="margin-top:10px">' . $this->lettersPanel($request, $sub, true) . '</div>'
            . '<form method="post" action="' . self::e($this->url($request, 'issue')) . '" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">' . $this->csrf($request) . '<input type="hidden" name="submissionId" value="' . (int) $sub->getId() . '"><input type="hidden" name="force" value="1">'
            . '<span style="font-size:12px">' . $this->t('reissueLabel') . '</span><select name="type" style="' . self::S_INP . '">' . implode('', array_map(fn ($t) => '<option value="' . $t . '">' . $this->t('type.' . $t) . '</option>', Letters::eligible($sub))) . '</select>'
            . '<select name="lang" style="' . self::S_INP . '"><option value="ar">عربي</option><option value="en">English</option></select><button style="' . self::S_BTN . '">' . $this->t('reissue') . '</button></form></div>';
        $rows = $this->store->forSubmission($this->cid($request), (int) $sub->getId());
        $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:16px">' . $this->t('history') . '</h2>' . ($rows ? '<table style="' . self::S_TBL . '"><tbody>' . implode('', array_map(fn ($r) => $this->letterRow($request, $r), $rows)) . '</tbody></table>' : '<p>' . $this->t('noLetters') . '</p>') . '</div>';
        $this->show($request, $this->t('menu'), $h);
    }

    public function issue($args, $request)
    {
        $this->login($request); if (!$this->plugin->isEditor($request)) $this->deny(); $this->postOk($request);
        $this->doIssue($request, true);
    }

    public function selfIssue($args, $request)
    {
        $this->login($request); $this->postOk($request);
        if (!$this->plugin->cfg($this->cid($request))['allowAuthors'] && !$this->plugin->isEditor($request)) $this->deny();
        $this->doIssue($request, false);
    }

    private function doIssue($request, bool $editor): void
    {
        $sub = Repo::submission()->get((int) $request->getUserVar('submissionId'));
        if (!$sub || (int) $sub->getData('contextId') !== $this->cid($request)) { http_response_code(404); exit; }
        if (!$editor && !$this->isAuthorOf($request, $sub)) $this->deny();
        $type = (string) $request->getUserVar('type'); $lang = $request->getUserVar('lang') === 'en' ? 'en' : 'ar';
        if (!in_array($type, Letters::eligible($sub), true)) $this->deny();
        $letter = $this->issueFor($request, $sub, $type, $lang, $editor && (bool) $request->getUserVar('force'));
        $request->redirectUrl($this->url($request, 'download', ['letterId' => $letter->letter_id]));
    }

    public function download($args, $request)
    {
        $this->login($request);
        $r = DB::table(Store::TABLE)->where('letter_id', (int) $request->getUserVar('letterId'))->where('context_id', $this->cid($request))->first();
        if (!$r) { http_response_code(404); exit; }
        $sub = Repo::submission()->get((int) $r->submission_id);
        if (!$this->plugin->isEditor($request) && !($sub && $this->isAuthorOf($request, $sub))) $this->deny();
        if ($r->revoked_at && !$this->plugin->isEditor($request)) $this->deny();
        $dir = Core::getBaseDir() . '/' . $this->plugin->getPluginPath();
        try {
            $pdf = (new Letters($dir))->pdf($r, $this->plugin->cfg($this->cid($request)), $this->url($request, 'verify', [], [$r->code]), Core::getBaseDir() . '/cache/mpdf-letters');
        } catch (\Throwable $e) {
            http_response_code(500); echo self::e($this->t('pdfError') . ' ' . $e->getMessage()); exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . str_replace('/', '-', $r->number) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; exit;
    }

    public function revoke($args, $request)
    {
        $this->login($request); if (!$this->plugin->isManager($request)) $this->deny(); $this->postOk($request);
        $this->store->revoke((int) $request->getUserVar('letterId'), $this->cid($request), trim((string) $request->getUserVar('reason')));
        $request->redirectUrl($this->url($request, 'index'));
    }

    // ================================================================ المؤلف
    public function mine($args, $request)
    {
        $this->login($request);
        $cid = $this->cid($request); $u = $request->getUser();
        $ids = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
            ->where('s.context_id', $cid)->where('sa.user_id', $u->getId())->where('ug.role_id', Role::ROLE_ID_AUTHOR)->pluck('s.submission_id')->all();
        $ids = array_unique(array_merge($ids, DB::table('authors as a')->join('publications as p', 'p.publication_id', '=', 'a.publication_id')->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $cid)->whereRaw('LOWER(a.email) = ?', [mb_strtolower((string) $u->getEmail())])->pluck('s.submission_id')->all()));
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p><div style="' . self::S_BOX . '">';
        if (!$this->plugin->cfg($cid)['allowAuthors']) $h .= '<p>' . $this->t('authorsDisabled') . '</p>';
        elseif (!$ids) $h .= '<p>' . $this->t('noSubmissions') . '</p>';
        else {
            $h .= '<table style="' . self::S_TBL . '"><tbody>';
            foreach ($ids as $id) {
                $s = Repo::submission()->get((int) $id);
                if (!$s || (string) $s->getData('submissionProgress') !== '') continue;
                $h .= '<tr><td style="' . self::S_TD . 'width:45%"><b>' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</b><div style="font-size:11px;color:#777">#' . (int) $id . ' · ' . self::e(substr((string) $s->getData('dateSubmitted'), 0, 10)) . '</div></td><td style="' . self::S_TD . '">' . $this->lettersPanel($request, $s, false) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '<div style="font-size:12px;color:#555;margin-top:8px">' . $this->t('mineNote') . '</div></div>';
        $this->show($request, $this->t('myMenu'), $h);
    }

    // ================================================================ التحقق العام
    public function verify($args, $request)
    {
        $code = (string) ($args[0] ?? $request->getUserVar('code') ?? '');
        $r = $code !== '' ? $this->store->byCode($code) : null;
        $context = $request->getContext();
        if ($r && (int) $r->context_id !== (int) $context->getId()) $r = null;
        $h = '<div style="max-width:760px">';
        if (!$r) {
            $h .= '<div style="border:2px solid #b02a2a;border-radius:10px;padding:16px;background:#fdf1f1"><h2 style="margin:0;color:#b02a2a">✖ ' . $this->t('v.notFound') . '</h2><p>' . $this->t('v.notFoundHelp') . '</p></div>';
        } else {
            $d = json_decode((string) $r->data, true) ?: [];
            $ok = !$r->revoked_at;
            $row = fn ($l, $v) => $v === '' || $v === null ? '' : '<tr><th style="text-align:start;padding:8px;background:#f6f1e7;border:1px solid #e1d9cc;width:34%">' . $l . '</th><td style="padding:8px;border:1px solid #e1d9cc">' . $v . '</td></tr>';
            $h .= '<div style="border:2px solid ' . ($ok ? '#1e7b4a' : '#b02a2a') . ';border-radius:10px;padding:16px;background:' . ($ok ? '#eef8f2' : '#fdf1f1') . ';margin-bottom:14px"><h2 style="margin:0;color:' . ($ok ? '#1e7b4a' : '#b02a2a') . '">' . ($ok ? '✔ ' . $this->t('v.valid') : '✖ ' . $this->t('v.revoked')) . '</h2>'
                . ($ok ? '' : '<p>' . self::e($this->t('v.revokedOn', ['d' => substr((string) $r->revoked_at, 0, 10)])) . ' ' . self::e($r->revoke_reason) . '</p>') . '</div>'
                . '<table style="width:100%;border-collapse:collapse">' . $row($this->t('col.type'), $this->t('type.' . $r->letter_type)) . $row($this->t('col.number'), '<span dir="ltr">' . self::e($r->number) . '</span>')
                . $row($this->t('col.date'), self::e(substr((string) $r->issued_at, 0, 10))) . $row($this->t('f.title', []), self::e($d['title'] ?? '')) . $row($this->t('f.authors', []), self::e(implode('، ', $d['authors'] ?? [])))
                . $row($this->t('f.submitted', []), self::e($d['submitted'] ?? '')) . ($r->letter_type !== 'receipt' ? $row($this->t('f.accepted', []), self::e((string) ($d['accepted'] ?? ''))) : '')
                . ($r->letter_type === 'publication' ? $row($this->t('f.issue', []), self::e($d['issue'] ?? '')) . (!empty($d['doi']) ? $row('DOI', '<a dir="ltr" href="https://doi.org/' . self::e($d['doi']) . '">https://doi.org/' . self::e($d['doi']) . '</a>') : '') : '')
                . '</table><p style="font-size:.85rem;color:#6b625a;margin-top:12px">' . $this->t('v.note') . '</p>';
        }
        $h .= '</div>';
        header('X-Robots-Tag: noindex');
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['olTitle' => $this->t('v.title'), 'olHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('verify.tpl'));
    }

    // ================================================================ الإعدادات
    private function settingsForm($request): string
    {
        $c = $this->plugin->cfg($this->cid($request));
        $cb = fn ($n, $l) => '<label style="display:block;margin:5px 0"><input type="checkbox" name="' . $n . '" value="1"' . ($c[$n] ? ' checked' : '') . '> ' . $l . '</label>';
        return '<details style="' . self::S_BOX . '"><summary style="cursor:pointer;font-weight:700">' . $this->t('settings') . '</summary><form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '" style="margin-top:8px">' . $this->csrf($request)
            . $cb('allowAuthors', $this->t('set.allowAuthors')) . $cb('signature', $this->t('set.signature'))
            . '<label style="display:block;margin:6px 0">' . $this->t('set.noteAr') . '<textarea name="note_ar" rows="2" style="' . self::S_INP . 'width:100%">' . self::e($c['note_ar']) . '</textarea></label>'
            . '<label style="display:block;margin:6px 0">' . $this->t('set.noteEn') . '<textarea name="note_en" dir="ltr" rows="2" style="' . self::S_INP . 'width:100%">' . self::e($c['note_en']) . '</textarea></label>'
            . '<div style="font-size:12px;color:#555;margin-bottom:6px">' . $this->t('set.assets') . '</div><button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form></details>';
    }

    public function saveSettings($args, $request)
    {
        $this->login($request); if (!$this->plugin->isManager($request)) $this->deny(); $this->postOk($request);
        $cid = $this->cid($request);
        $this->plugin->updateSetting($cid, 'allowAuthors', (bool) $request->getUserVar('allowAuthors'), 'bool');
        $this->plugin->updateSetting($cid, 'signature', (bool) $request->getUserVar('signature'), 'bool');
        $this->plugin->updateSetting($cid, 'note_ar', mb_substr(trim((string) $request->getUserVar('note_ar')), 0, 1000), 'string');
        $this->plugin->updateSetting($cid, 'note_en', mb_substr(trim((string) $request->getUserVar('note_en')), 0, 1000), 'string');
        $request->redirectUrl($this->url($request, 'index'));
    }
}
