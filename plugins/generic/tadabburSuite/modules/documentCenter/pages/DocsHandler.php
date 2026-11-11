<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/documentCenter/pages/DocsHandler.php
 * صفحات المركز: الإدارة (البحوث، الإرسال الجماعي، السجل، الإعدادات)، والمحكّم (نماذج التحكيم)، والباحث (ملفاتي)، والتحقق العام.
 */
namespace APP\plugins\generic\tadabburSuite\m\documentCenter\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Catalog;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Delivery;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Render;
use APP\plugins\generic\tadabburSuite\m\documentCenter\classes\Store;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\ReviewKitRenderer;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginRegistry;

class DocsHandler extends ModuleHandler
{
    private ?Delivery $dl = null;
    private function dl($request): Delivery { return $this->dl ??= new Delivery($this->plugin, $request, $request->getContext()); }
    private function cfg($request, string $type): array { return Catalog::cfg($this->plugin, $this->cid($request), $type); }
    private function subm(int $sid, $request)
    {
        $s = $sid ? Repo::submission()->get($sid) : null;
        return $s && (int) $s->getData('contextId') === $this->cid($request) ? $s : null;
    }
    private function docName(string $type): string { return $this->t('doc.' . $type); }
    private function tabsFor($request, string $cur): string
    {
        $t = ['index' => '📄 ' . $this->t('tab.papers'), 'bulk' => '📨 ' . $this->t('tab.bulk'), 'log' => '🗂 ' . $this->t('tab.log')];
        if ($this->plugin->isManager($request)) $t['settings'] = '⚙ ' . $this->t('tab.settings');
        return $this->tabs($request, $t, $cur);
    }

    private function statusPill($sub): string
    {
        $st = (int) $sub->getData('status');
        if ($st === 3) return $this->pill('ok', $this->t('st.published'));
        if ($st === 4) return $this->pill('bad', $this->t('st.declined'));
        return $this->pill('info', $this->t('st.stage' . (int) $sub->getData('stageId')));
    }

    // ================================================================ الإدارة: البحوث
    public function index($args, $request)
    {
        $this->editor($request);
        $cid = $this->cid($request); $q = $this->v($request, 'q');
        $s = $this->dl($request)->store->stats($cid);
        $col = Repo::submission()->getCollector()->filterByContextIds([$cid])->limit(30)->orderBy(\APP\submission\Collector::ORDERBY_DATE_SUBMITTED, \APP\submission\Collector::ORDER_DIR_DESC);
        if ($q !== '') ctype_digit($q) ? $col->filterBySubmissionIds([(int) $q]) : $col->searchPhrase($q);
        $counts = DB::table(Store::T)->where('context_id', $cid)->where('status', 'valid')->groupBy('submission_id')->select('submission_id', DB::raw('count(*) as n'))->pluck('n', 'submission_id')->all();
        $rows = [];
        foreach ($col->getMany() as $sub) {
            if ((string) $sub->getData('submissionProgress') !== '') continue;
            $sid = (int) $sub->getId();
            $rows[] = ['<span class="tdb-mono">' . $sid . '</span>',
                '<b>' . self::e(Catalog::title($sub)) . '</b><div class="tdb-muted">' . self::e(strip_tags((string) $sub->getCurrentPublication()->getShortAuthorString())) . '</div>',
                $this->statusPill($sub), (string) (int) ($counts[$sid] ?? 0),
                $this->btn($this->url($request, 'sub', ['sid' => $sid]), $this->t('manage'), true, '', 'sm')];
        }
        $h = $this->head($this->t('intro'), $this->btn($this->url($request, 'bulk'), $this->t('tab.bulk')))
            . $this->stats([$this->stat((string) $s['issued'], $this->t('s.issued')), $this->stat((string) $s['sent'], $this->t('s.sent'), 'ok'),
                $this->stat((string) $s['failed'], $this->t('s.failed'), $s['failed'] ? 'bad' : ''), $this->stat((string) $s['month'], $this->t('s.month'))])
            . $this->tabsFor($request, 'index') . $this->flash($request)
            . (Render::available() ? '' : '<div class="tdb-alert tdb-alert--bad">' . $this->t('noMpdf') . '</div>')
            . $this->box($this->t('papers'), '<form method="get" class="tdb-toolbar" action="' . self::e($this->url($request, 'index')) . '"><input class="tdb-input" type="search" name="q" value="' . self::e($q) . '" placeholder="' . self::e($this->t('search')) . '"><button class="tdb-btn">' . $this->t('searchBtn') . '</button></form>'
                . $this->table(['#', $this->t('col.paper'), $this->t('col.state'), $this->t('col.docs'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    // ================================================================ الإدارة: مستندات بحث
    public function sub($args, $request)
    {
        $this->editor($request);
        $sub = $this->subm((int) $request->getUserVar('sid'), $request) ?? $this->notFound();
        $cid = $this->cid($request); $sid = (int) $sub->getId();
        $h = $this->head('<b>#' . $sid . '</b> · ' . self::e(Catalog::title($sub)) . ' ' . $this->statusPill($sub), $this->btn($this->url($request, 'index'), '← ' . $this->t('back'))) . $this->flash($request);
        $tiles = '';
        foreach (Catalog::ofAudience('author') as $type) $tiles .= $this->adminTile($request, $type, $sub, null);
        $h .= $this->box('👤 ' . $this->t('forAuthor'), '<div class="tdb-docs">' . $tiles . '</div>');
        $reviews = Catalog::reviewsOf($cid, $sid);
        if (!$reviews) $h .= $this->box('🧑‍⚖️ ' . $this->t('forReviewers'), $this->emptyState($this->t('noReviewers')));
        foreach ($reviews as $r) {
            $u = Catalog::user((int) $r->reviewer_id);
            $tiles = '';
            foreach (Catalog::ofAudience('reviewer') as $type) $tiles .= $this->adminTile($request, $type, $sub, $r);
            $h .= $this->box('🧑‍⚖️ ' . self::e($u ? $u->getFullName() : '#' . $r->reviewer_id) . ' <span class="tdb-muted">· ' . $this->t('round', ['n' => (int) $r->round]) . '</span> '
                . (Catalog::completed($r) ? $this->pill('ok', $this->t('rv.done')) : $this->pill('warn', $this->t('rv.open'))), '<div class="tdb-docs">' . $tiles . '</div>');
        }
        $hist = [];
        foreach ($this->dl($request)->store->forSubmission($cid, $sid) as $d) $hist[] = $this->logRow($request, $d);
        $h .= $this->box('🗂 ' . $this->t('history'), $this->table([$this->t('col.number'), $this->t('col.doc'), $this->t('col.date'), $this->t('col.state'), ''], $hist));
        $this->show($request, $this->t('menu'), $h);
    }

    /** بطاقة مستند للإدارة: الحالة + فتح + نموذج الإصدار والإرسال */
    private function adminTile($request, string $type, $sub, ?object $review): string
    {
        $cfg = $this->cfg($request, $type);
        $why = Catalog::why($type, $sub, $review);
        $dl = $this->dl($request);
        $cur = $dl->store->current($this->cid($request), $type, (int) $sub->getId(), $review ? (int) $review->review_id : null);
        $state = !$cfg['enabled'] ? $this->pill('muted', $this->t('disabled')) : ($why !== null ? $this->pill('muted', $this->t('why.' . $why))
            : ($cur ? ($cur->last_send_status === 'accepted' ? $this->pill('ok', $this->t('sentOn', ['d' => substr((string) $cur->last_sent_at, 0, 10)])) : ($cur->last_send_status === 'failed' ? $this->pill('bad', $this->t('sendFailed')) : ($cur->released ? $this->pill('info', $this->t('released')) : $this->pill('warn', $this->t('issuedOnly')))))
            : $this->pill('info', $this->t('ready'))));
        $h = '<article class="tdb-doc' . ($why !== null || !$cfg['enabled'] ? ' is-off' : '') . '"><div class="tdb-doc__top"><span class="tdb-doc__icon" aria-hidden="true">' . $this->icon($type) . '</span><div><h3>' . self::e($this->docName($type)) . '</h3><p>' . self::e($this->t('docd.' . $type)) . '</p></div></div>'
            . '<div class="tdb-doc__meta">' . $state . ($cur ? ' <span class="tdb-mono tdb-muted">' . self::e($cur->number) . '</span>' : '') . ($cfg['self'] ? ' ' . $this->pill('muted', $this->t('selfOn')) : '') . '</div>';
        if ($why === null && $cfg['enabled']) {
            $acts = $cur ? $this->btn($dl->openUrl($cur), '👁 ' . $this->t('open'), false, ' target="_blank" rel="noopener"', 'sm') : '';
            $sub0 = $sub; $tmp = $cur ?: (object) ['review_id' => $review?->review_id, 'user_id' => $review?->reviewer_id, 'doc_type' => $type];
            $rcpt = '';
            foreach ($dl->defaultRecipients($tmp, $sub0) as $i => $r) {
                $rcpt .= '<label class="tdb-check"><input type="checkbox" name="rcpt[]" value="' . self::e($r['email']) . '|' . (int) $r['id'] . '|' . self::e($r['name']) . '" checked> ' . self::e($r['name']) . ' <span class="tdb-muted" dir="ltr">' . self::e($r['email']) . '</span></label>';
            }
            $uid = $review ? (int) $review->reviewer_id : (Catalog::authorUsers((int) $sub->getId())[0]['id'] ?? 0);
            $pref = $uid ? $dl->store->pref($uid) : null;
            $hk = $cur ? (string) $cur->honorific : ($pref ? $pref->honorific : (($u = $uid ? Catalog::user($uid) : null) ? Catalog::guessHonorific($u) : 'none'));
            $hon = []; foreach (Catalog::honorifics()['list'] as $k => $l) $hon[$k] = $l !== '' ? $l : $this->t('noTitle');
            $form = '<form method="post" class="tdb-form" action="' . self::e($this->url($request, 'issue')) . '">' . $this->csrf($request)
                . '<input type="hidden" name="type" value="' . self::e($type) . '"><input type="hidden" name="sid" value="' . (int) $sub->getId() . '"><input type="hidden" name="rid" value="' . (int) ($review?->review_id ?? 0) . '">'
                . '<div class="tdb-grid2">' . $this->field($this->t('f.honorific'), $this->select('honorific', $hon, $hk)) . $this->field($this->t('f.lang'), $this->select('lang', ['ar' => 'العربية', 'en' => 'English'], $cur ? $cur->lang : $cfg['lang'])) . '</div>'
                . $this->field($this->t('f.recipients'), '<div class="tdb-checks">' . ($rcpt ?: '<span class="tdb-muted">' . $this->t('noRecipients') . '</span>') . '</div>')
                . $this->field($this->t('f.extra'), '<input class="tdb-input" type="email" name="extra" dir="ltr" placeholder="name@example.com">')
                . $this->field($this->t('f.note'), '<textarea class="tdb-input" name="note" rows="2">' . self::e($cur->note ?? '') . '</textarea>', $this->t('f.noteHelp'))
                . '<div class="tdb-checks">' . $this->toggle('remember', true, $this->t('f.remember')) . ($cur ? $this->toggle('fresh', false, $this->t('f.fresh')) : '') . '</div>'
                . '<div class="tdb-actions"><button class="tdb-btn tdb-btn--primary" name="act" value="send">✉ ' . $this->t('act.send') . '</button>'
                . '<button class="tdb-btn" name="act" value="release">🔓 ' . $this->t('act.release') . '</button><button class="tdb-btn tdb-btn--ghost" name="act" value="preview" formtarget="_blank">👁 ' . $this->t('act.preview') . '</button></div></form>';
            $h .= '<div class="tdb-doc__acts">' . $acts . '</div><details class="tdb-disclose"><summary>' . $this->t('issueSend') . '</summary>' . $form . '</details>';
        }
        return $h . '</article>';
    }

    private function icon(string $type): string
    {
        return ['rev_forms' => '📝', 'rev_invite' => '✉️', 'rev_certificate' => '🏅', 'rev_thanks' => '💐', 'au_receipt' => '📥', 'au_acceptance' => '✅', 'au_publication' => '📰',
            'au_apology' => '🤝', 'au_thanks' => '🎖️', 'au_reviews' => '🗒️', 'au_revision' => '🛠️', 'au_matrix' => '📊'][$type] ?? '📄';
    }

    public function issue($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        $type = $this->v($request, 'type');
        if (!Catalog::valid($type)) $this->notFound();
        $sub = $this->subm((int) $request->getUserVar('sid'), $request) ?? $this->notFound();
        $review = null;
        if (Catalog::scope($type) === 'review') { $review = Catalog::review($this->cid($request), (int) $request->getUserVar('rid')); if (!$review || (int) $review->submission_id !== (int) $sub->getId()) $this->notFound(); }
        if (!$this->cfg($request, $type)['enabled']) $this->back($request, 'sub', ['sid' => $sub->getId()], 'errDisabled');
        $act = $this->v($request, 'act');
        $dl = $this->dl($request);
        try {
            $doc = $dl->issue($type, $sub, $review, ['honorific' => $this->v($request, 'honorific'), 'lang' => $this->v($request, 'lang'), 'note' => (string) $request->getUserVar('note'),
                'release' => $act !== 'preview', 'fresh' => (bool) $request->getUserVar('fresh'), 'remember' => (bool) $request->getUserVar('remember'), 'by' => (int) $request->getUser()->getId()]);
        } catch (\Throwable $e) {
            error_log('[documentCenter] issue: ' . $e->getMessage());
            $this->back($request, 'sub', ['sid' => $sub->getId()], 'errIssue');
        }
        if ($act === 'preview') $request->redirectUrl($dl->openUrl($doc));
        if ($act === 'send') {
            $rc = [];
            foreach ((array) $request->getUserVar('rcpt') as $x) { [$em, $id, $nm] = array_pad(explode('|', (string) $x, 3), 3, ''); $rc[] = ['email' => $em, 'id' => (int) $id, 'name' => $nm]; }
            if (($ex = $this->v($request, 'extra')) !== '') $rc[] = ['email' => $ex, 'id' => 0, 'name' => ''];
            if (!$rc) $this->back($request, 'sub', ['sid' => $sub->getId()], 'errNoRecipients');
            [$ok, $errs] = $dl->send($doc, $sub, $rc);
            $this->back($request, 'sub', ['sid' => $sub->getId()], $errs ? ($ok ? 'partial' : 'errSend') : 'sent');
        }
        $this->back($request, 'sub', ['sid' => $sub->getId()], 'released');
    }

    // ================================================================ الملفات
    /** هل يحق للمستخدم الحالي فتح المستند؟ */
    private function canOpen($request, object $doc): bool
    {
        if ($this->plugin->isEditor($request)) return true;
        if ($doc->status !== 'valid') return false;
        $uid = (int) $request->getUser()->getId();
        $cfg = $this->cfg($request, $doc->doc_type);
        if (!$cfg['enabled'] || !($doc->released || $cfg['self'])) return false;
        if ($doc->review_id) { $r = Catalog::review($this->cid($request), (int) $doc->review_id); return $r && (int) $r->reviewer_id === $uid; }
        return Catalog::isAuthorOf((int) $doc->submission_id, $uid);
    }

    private function docOr404($request): object
    {
        $doc = $this->dl($request)->store->get((int) $request->getUserVar('doc'), $this->cid($request));
        if (!$doc) $this->notFound();
        if (!$this->canOpen($request, $doc)) $this->deny();
        return $doc;
    }

    public function file($args, $request)
    {
        $this->login($request);
        $doc = $this->docOr404($request);
        if (Catalog::kind($doc->doc_type) === 'kit') $request->redirectUrl($this->url($request, 'kit', ['doc' => $doc->doc_id]));
        try { $f = $this->dl($request)->pdf($doc); } catch (\Throwable $e) { http_response_code(500); echo self::e($this->t('pdfError') . ' ' . $e->getMessage()); exit; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $f[1] . '"');
        header('Content-Length: ' . strlen($f[0]));
        header('Cache-Control: private, no-store');
        echo $f[0]; exit;
    }

    public function kit($args, $request)
    {
        $this->login($request);
        $doc = $this->docOr404($request);
        if (Catalog::kind($doc->doc_type) !== 'kit') $request->redirectUrl($this->url($request, 'file', ['doc' => $doc->doc_id]));
        $rc = Modules::instance('reviewerCertificate');
        $cfg = $rc ? $rc->cfg($request) : (require Modules::root() . '/reviewerCertificate/config.php');
        $cfg['journal_name'] = $cfg['journal_name'] ?? (string) $request->getContext()->getLocalizedName();
        $kit = new ReviewKitRenderer(Modules::root() . '/reviewerCertificate', $cfg);
        $sub = $this->subm((int) $doc->submission_id, $request) ?? $this->notFound();
        $d = json_decode((string) $doc->data, true) ?: [];
        $title = Catalog::title($sub);
        $hk = (string) $doc->honorific; $hon = Catalog::honorificLabel($hk);
        $date = new \DateTimeImmutable(substr((string) $doc->issued_at, 0, 10));
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: private, no-store');
        switch ($doc->doc_type) {
            case 'rev_forms': echo $kit->forms(['reviewer' => trim($hon . ' ' . ($d['name'] ?? '')), 'title' => $title, 'docId' => 'forms-' . (int) $doc->review_id]); break;
            case 'rev_invite':
                $r = Catalog::review($this->cid($request), (int) $doc->review_id);
                $days = (int) ($cfg['default_review_days'] ?? 10);
                if ($r && $r->date_due && $r->date_assigned) $days = max(1, (int) round((strtotime((string) $r->date_due) - strtotime((string) $r->date_assigned)) / 86400));
                echo $kit->invitation(['ref' => 'R' . (int) $doc->review_id, 'date' => $date, 'honorificKey' => $hk, 'honorific' => $hon, 'reviewer' => $d['name'] ?? '', 'title' => $title, 'days' => $days]); break;
            case 'au_revision': echo $kit->revisionLetter(['date' => $date, 'honorificKey' => $hk, 'honorific' => $hon, 'author' => $d['name'] ?? '', 'title' => $title]); break;
            case 'au_matrix':
                $n = DB::table('review_assignments')->where('submission_id', $sub->getId())->where('cancelled', 0)->where('declined', 0)->whereNotNull('date_completed')->max('round');
                $c = DB::table('review_assignments')->where('submission_id', $sub->getId())->where('cancelled', 0)->where('declined', 0)->where('round', (int) $n)->count();
                echo $kit->matrix(['title' => $title, 'date' => $date, 'docId' => 'matrix-' . (int) $sub->getId(), 'reviewers' => array_fill(0, max(2, min(4, $c)), [])]); break;
        }
        exit;
    }

    // ================================================================ المستلم: استخراج ذاتي
    public function make($args, $request)
    {
        $this->login($request); $this->postOk($request);
        $type = $this->v($request, 'type');
        if (!Catalog::valid($type)) $this->notFound();
        $cfg = $this->cfg($request, $type);
        if (!$cfg['enabled'] || !$cfg['self']) $this->deny();
        $uid = (int) $request->getUser()->getId(); $cid = $this->cid($request);
        $review = null;
        if (Catalog::scope($type) === 'review') {
            $review = Catalog::review($cid, (int) $request->getUserVar('rid'));
            if (!$review || (int) $review->reviewer_id !== $uid || (int) $review->declined) $this->deny();
            $sub = $this->subm((int) $review->submission_id, $request);
        } else {
            $sub = $this->subm((int) $request->getUserVar('sid'), $request);
            if (!$sub || !Catalog::isAuthorOf((int) $sub->getId(), $uid)) $this->deny();
        }
        if (!$sub || Catalog::why($type, $sub, $review) !== null) $this->deny();
        $lang = $this->v($request, 'lang') === 'en' ? 'en' : $cfg['lang'];
        try { $doc = $this->dl($request)->issue($type, $sub, $review, ['lang' => $lang, 'release' => true, 'by' => $uid]); }
        catch (\Throwable $e) { http_response_code(500); echo self::e($e->getMessage()); exit; }
        $request->redirectUrl($this->dl($request)->openUrl($doc));
    }

    /** بطاقة مستند للمستلم */
    private function ownTile($request, string $type, $sub, ?object $review): string
    {
        $acts = $this->ownActs($request, $type, $sub, $review);
        if ($acts === null) return '';
        return '<article class="tdb-doc"><div class="tdb-doc__top"><span class="tdb-doc__icon" aria-hidden="true">' . $this->icon($type) . '</span><div><h3>' . self::e($this->docName($type)) . '</h3><p>' . self::e($this->t('docd.' . $type)) . '</p></div></div>'
            . '<div class="tdb-doc__acts">' . $acts . '</div></article>';
    }

    /** أزرار المستند للمستلم، أو null إن لم يكن متاحًا له */
    private function ownActs($request, string $type, $sub, ?object $review): ?string
    {
        $cfg = $this->cfg($request, $type);
        if (!$cfg['enabled']) return null;
        $cid = $this->cid($request); $dl = $this->dl($request);
        $released = DB::table(Store::T)->where('context_id', $cid)->where('doc_type', $type)->where('status', 'valid')->where('released', 1)
            ->where($review ? 'review_id' : 'submission_id', $review ? (int) $review->review_id : (int) $sub->getId())->orderByDesc('doc_id')->get()->all();
        $why = Catalog::why($type, $sub, $review);
        if (!$released && !($cfg['self'] && $why === null)) return null;
        $acts = '';
        foreach ($released as $d) $acts .= $this->btn($dl->openUrl($d), (Catalog::kind($type) === 'kit' ? '📂 ' . $this->t('openForm') : '⬇ PDF') . ' <small>' . strtoupper(self::e($d->lang)) . '</small>', true, ' target="_blank" rel="noopener"', 'sm');
        if (!$released && $cfg['self'] && $why === null) {
            $f = ['type' => $type] + ($review ? ['rid' => (int) $review->review_id] : ['sid' => (int) $sub->getId()]);
            $acts .= $this->postBtn($request, 'make', $f + ['lang' => 'ar'], '⬇ ' . (Catalog::kind($type) === 'kit' ? $this->t('openForm') : $this->t('get')) . ' <small>AR</small>', true, '', 'sm');
            if (Catalog::kind($type) !== 'kit') $acts .= $this->postBtn($request, 'make', $f + ['lang' => 'en'], '⬇ <small>EN</small>', false, '', 'sm');
        }
        return $acts;
    }

    // ================================================================ المحكّم
    public function mine($args, $request)
    {
        $this->login($request);
        $cid = $this->cid($request); $uid = (int) $request->getUser()->getId();
        $h = $this->head($this->t('mineIntro'));
        $open = ''; $rows = [];
        foreach (Catalog::reviewsByReviewer($cid, $uid) as $r) {
            $sub = $this->subm((int) $r->submission_id, $request);
            if (!$sub) continue;
            if (!Catalog::completed($r)) {
                $tiles = '';
                foreach (Catalog::ofAudience('reviewer') as $type) $tiles .= $this->ownTile($request, $type, $sub, $r);
                $open .= $this->box(self::e(Catalog::title($sub)) . ' ' . $this->pill('warn', $this->t('rv.open') . ($r->date_due ? ' · ' . $this->t('due', ['d' => substr((string) $r->date_due, 0, 10)]) : '')),
                    $tiles !== '' ? '<div class="tdb-docs">' . $tiles . '</div>' : $this->emptyState($this->t('nothingYet')));
                continue;
            }
            $cells = '';
            foreach (Catalog::ofAudience('reviewer') as $type) {
                $a = $this->ownActs($request, $type, $sub, $r);
                if ($a !== null) $cells .= '<div class="tdb-docline"><span>' . $this->icon($type) . ' ' . self::e($this->docName($type)) . '</span><span>' . $a . '</span></div>';
            }
            $rows[] = ['<b>' . self::e(Catalog::title($sub)) . '</b><div class="tdb-muted">' . $this->t('completedOn', ['d' => substr((string) $r->date_completed, 0, 10)]) . '</div>', $cells !== '' ? $cells : '<span class="tdb-muted">' . $this->t('nothingYet') . '</span>'];
        }
        $h .= '<h2 class="tdb-section">' . $this->t('currentReviews') . '</h2>' . ($open !== '' ? $open : $this->box('', $this->emptyState($this->t('noCurrent'))));
        if ($rows) $h .= '<h2 class="tdb-section">' . $this->t('pastReviews') . ' <span class="tdb-badge tdb-badge--muted">' . count($rows) . '</span></h2>'
            . $this->box('', '<input class="tdb-input tdb-filterbox" type="search" placeholder="' . self::e($this->t('filter')) . '" oninput="var q=this.value.trim();this.closest(\'.tdb-card\').querySelectorAll(\'tbody tr\').forEach(function(t){t.hidden=q&&t.textContent.indexOf(q)<0})">'
                . $this->table([$this->t('col.paper'), $this->t('col.docs')], $rows));
        $h .= '<p class="tdb-muted">' . $this->t('mineNote') . '</p>';
        $this->show($request, $this->t('menuReviewer'), $h);
    }

    // ================================================================ الباحث
    public function author($args, $request)
    {
        $this->login($request);
        $cid = $this->cid($request); $uid = (int) $request->getUser()->getId();
        $h = $this->head($this->t('authorIntro'));
        $any = false;
        foreach (Catalog::authorSubmissions($cid, $uid) as $sid) {
            $sub = $this->subm($sid, $request);
            if (!$sub || (string) $sub->getData('submissionProgress') !== '') continue;
            $tiles = '';
            foreach (Catalog::ofAudience('author') as $type) $tiles .= $this->ownTile($request, $type, $sub, null);
            $any = true;
            $h .= $this->box('<span class="tdb-mono">#' . $sid . '</span> ' . self::e(Catalog::title($sub)) . ' ' . $this->statusPill($sub), $tiles !== '' ? '<div class="tdb-docs">' . $tiles . '</div>' : $this->emptyState($this->t('nothingYet')));
        }
        if (!$any) $h .= $this->box('', $this->emptyState($this->t('noSubmissions')));
        $this->show($request, $this->t('menuAuthor'), $h);
    }

    // ================================================================ الإرسال الجماعي
    public function bulk($args, $request)
    {
        $this->editor($request);
        $cid = $this->cid($request);
        $type = $this->v($request, 'type'); if (!Catalog::valid($type)) $type = 'rev_thanks';
        $opts = []; foreach (array_keys(Catalog::TYPES) as $t) $opts[$t] = $this->docName($t);
        $dl = $this->dl($request);
        $rows = [];
        if (Catalog::scope($type) === 'review') {
            $list = DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')->where('s.context_id', $cid)->where('ra.cancelled', 0)->where('ra.declined', 0)->select('ra.*')->orderByDesc('ra.review_id')->limit(150)->get();
            foreach ($list as $r) {
                $sub = $this->subm((int) $r->submission_id, $request); if (!$sub || Catalog::why($type, $sub, $r) !== null) continue;
                $cur = $dl->store->current($cid, $type, (int) $sub->getId(), (int) $r->review_id); $u = Catalog::user((int) $r->reviewer_id);
                $rows[] = ['<input type="checkbox" name="ids[]" value="' . (int) $r->review_id . '"' . ($cur && $cur->last_send_status === 'accepted' ? '' : ' checked') . '>', self::e($u ? $u->getFullName() : ''), self::e(mb_substr(Catalog::title($sub), 0, 90)),
                    $cur ? ($cur->last_send_status === 'accepted' ? $this->pill('ok', $this->t('sentOn', ['d' => substr((string) $cur->last_sent_at, 0, 10)])) : $this->pill('warn', $this->t('issuedOnly'))) : $this->pill('info', $this->t('ready'))];
            }
        } else {
            foreach (Repo::submission()->getCollector()->filterByContextIds([$cid])->limit(150)->getMany() as $sub) {
                if ((string) $sub->getData('submissionProgress') !== '' || Catalog::why($type, $sub) !== null) continue;
                $cur = $dl->store->current($cid, $type, (int) $sub->getId(), null);
                $rows[] = ['<input type="checkbox" name="ids[]" value="' . (int) $sub->getId() . '"' . ($cur && $cur->last_send_status === 'accepted' ? '' : ' checked') . '>', self::e(Catalog::primaryAuthor($sub)['name']), self::e(mb_substr(Catalog::title($sub), 0, 90)),
                    $cur ? ($cur->last_send_status === 'accepted' ? $this->pill('ok', $this->t('sentOn', ['d' => substr((string) $cur->last_sent_at, 0, 10)])) : $this->pill('warn', $this->t('issuedOnly'))) : $this->pill('info', $this->t('ready'))];
            }
        }
        $h = $this->head($this->t('bulkIntro')) . $this->tabsFor($request, 'bulk') . $this->flash($request)
            . $this->box($this->t('tab.bulk'), '<form method="get" class="tdb-toolbar" action="' . self::e($this->url($request, 'bulk')) . '">' . $this->select('type', $opts, $type, ' onchange="this.form.submit()"') . '<noscript><button class="tdb-btn">' . $this->t('show') . '</button></noscript></form>'
                . '<form method="post" action="' . self::e($this->url($request, 'bulkSend')) . '">' . $this->csrf($request) . '<input type="hidden" name="type" value="' . self::e($type) . '">'
                . $this->table(['', $this->t('col.recipient'), $this->t('col.paper'), $this->t('col.state')], $rows, $this->t('bulkNone'))
                . ($rows ? '<div class="tdb-grid2">' . $this->field($this->t('f.honorific'), $this->select('honorific', ['auto' => $this->t('f.honAuto')] + array_map(fn ($l) => $l !== '' ? $l : $this->t('noTitle'), Catalog::honorifics()['list']), 'auto'))
                    . $this->field($this->t('f.lang'), $this->select('lang', ['ar' => 'العربية', 'en' => 'English'], $this->cfg($request, $type)['lang'])) . '</div>'
                    . $this->field($this->t('f.note'), '<textarea class="tdb-input" name="note" rows="2"></textarea>')
                    . '<div class="tdb-actions"><button class="tdb-btn tdb-btn--primary" onclick="return confirm(' . self::e(json_encode($this->t('bulkConfirm'), JSON_UNESCAPED_UNICODE)) . ')">✉ ' . $this->t('act.bulk') . '</button></div>' : '') . '</form>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function bulkSend($args, $request)
    {
        $this->editor($request); $this->postOk($request);
        $type = $this->v($request, 'type'); if (!Catalog::valid($type) || !$this->cfg($request, $type)['enabled']) $this->notFound();
        $cid = $this->cid($request); $dl = $this->dl($request);
        $ok = 0; $bad = 0;
        foreach (array_slice(array_unique(array_map('intval', (array) $request->getUserVar('ids'))), 0, 60) as $id) {
            try {
                $review = null;
                if (Catalog::scope($type) === 'review') { $review = Catalog::review($cid, $id); $sub = $review ? $this->subm((int) $review->submission_id, $request) : null; }
                else $sub = $this->subm($id, $request);
                if (!$sub) { $bad++; continue; }
                $doc = $dl->issue($type, $sub, $review, ['honorific' => $this->v($request, 'honorific'), 'lang' => $this->v($request, 'lang'), 'note' => (string) $request->getUserVar('note'), 'release' => true, 'by' => (int) $request->getUser()->getId()]);
                [$n, $errs] = $dl->send($doc, $sub, $dl->defaultRecipients($doc, $sub));
                $n && !$errs ? $ok++ : $bad++;
            } catch (\Throwable $e) { $bad++; error_log('[documentCenter] bulk: ' . $e->getMessage()); }
        }
        $this->back($request, 'bulk', ['type' => $type, 'ok' => $ok, 'bad' => $bad], $bad ? ($ok ? 'partial' : 'errSend') : 'sent');
    }

    // ================================================================ السجل
    private function logRow($request, object $d): array
    {
        $state = $d->status !== 'valid' ? $this->pill('bad', $this->t('revoked')) : ($d->last_send_status === 'accepted' ? $this->pill('ok', $this->t('sentTo', ['e' => (string) $d->email])) : ($d->last_send_status === 'failed' ? $this->pill('bad', $this->t('sendFailed')) : ($d->released ? $this->pill('info', $this->t('released')) : $this->pill('warn', $this->t('issuedOnly')))));
        $acts = $this->btn($this->dl($request)->openUrl($d), $this->t('open'), false, ' target="_blank" rel="noopener"', 'sm');
        if ($d->status === 'valid' && $this->plugin->isManager($request)) $acts .= $this->postBtn($request, 'revoke', ['doc' => $d->doc_id], $this->t('revoke'), false, $this->t('revokeConfirm'), 'danger');
        return ['<span class="tdb-mono">' . self::e($d->number) . '</span>', self::e($this->docName($d->doc_type)) . ' <small class="tdb-muted">' . strtoupper(self::e($d->lang)) . ' · #' . (int) $d->submission_id . '</small>', self::e(substr((string) $d->issued_at, 0, 16)), $state, $acts];
    }

    public function log($args, $request)
    {
        $this->editor($request);
        $type = $this->v($request, 'type'); if (!Catalog::valid($type)) $type = null;
        $rows = array_map(fn ($d) => $this->logRow($request, $d), $this->dl($request)->store->recent($this->cid($request), 80, $type));
        $opts = ['' => $this->t('allTypes')]; foreach (array_keys(Catalog::TYPES) as $t) $opts[$t] = $this->docName($t);
        $h = $this->head($this->t('logIntro')) . $this->tabsFor($request, 'log') . $this->flash($request)
            . $this->box($this->t('tab.log'), '<form method="get" class="tdb-toolbar" action="' . self::e($this->url($request, 'log')) . '">' . $this->select('type', $opts, (string) $type, ' onchange="this.form.submit()"') . '</form>'
                . $this->table([$this->t('col.number'), $this->t('col.doc'), $this->t('col.date'), $this->t('col.state'), ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    public function revoke($args, $request)
    {
        $this->manager($request);
        $this->dl($request)->store->revoke((int) $request->getUserVar('doc'), $this->cid($request), 'revoked by ' . $request->getUser()->getUsername());
        $this->back($request, 'log', [], 'revokedMsg');
    }

    // ================================================================ الإعدادات
    public function settings($args, $request)
    {
        $this->manager($request, false);
        $cid = $this->cid($request); $g = Catalog::general($this->plugin, $cid);
        $rows = '';
        foreach (['reviewer', 'author'] as $aud) {
            $rows .= '<tr class="tdb-table__group"><th colspan="5">' . $this->t('aud.' . $aud) . '</th></tr>';
            foreach (Catalog::ofAudience($aud) as $type) {
                $c = Catalog::cfg($this->plugin, $cid, $type);
                $rows .= '<tr><td data-label=""><b>' . $this->icon($type) . ' ' . self::e($this->docName($type)) . '</b><div class="tdb-muted">' . self::e($this->t('docd.' . $type)) . '</div>'
                    . '<details class="tdb-disclose"><summary>' . $this->t('emailTpl') . '</summary>' . $this->field($this->t('f.subject'), '<input class="tdb-input" name="c[' . $type . '][subject]" value="' . self::e($c['subject']) . '" placeholder="' . self::e($this->t('mail.subject', ['doc' => $this->docName($type), 'journal' => '{journal}'])) . '">')
                    . $this->field($this->t('f.body'), '<textarea class="tdb-input" rows="3" name="c[' . $type . '][body]" placeholder="' . self::e($this->t('mail.body.' . $aud, ['doc' => '{doc}', 'journal' => '{journal}', 'title' => '{title}'])) . '">' . self::e($c['body']) . '</textarea>', $this->t('f.placeholders')) . '</details></td>'
                    . '<td data-label="' . self::e($this->t('c.enabled')) . '">' . $this->toggle('c[' . $type . '][enabled]', $c['enabled'], '') . '</td>'
                    . '<td data-label="' . self::e($this->t('c.self')) . '">' . $this->toggle('c[' . $type . '][self]', $c['self'], '') . '</td>'
                    . '<td data-label="' . self::e($this->t('c.auto')) . '">' . (in_array($type, ['rev_thanks', 'rev_certificate', 'au_apology', 'au_acceptance', 'au_thanks'], true) ? $this->toggle('c[' . $type . '][auto]', $c['auto'], '') : '<span class="tdb-muted">—</span>') . '</td>'
                    . '<td data-label="' . self::e($this->t('f.lang')) . '">' . $this->select('c[' . $type . '][lang]', ['ar' => 'AR', 'en' => 'EN'], $c['lang']) . '</td></tr>';
            }
        }
        $h = $this->head($this->t('settingsIntro')) . $this->tabsFor($request, 'settings') . $this->flash($request)
            . '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">' . $this->csrf($request)
            . $this->box($this->t('matrix'), '<div class="tdb-table-wrap"><table class="tdb-table tdb-table--settings"><thead><tr><th>' . $this->t('col.doc') . '</th><th>' . $this->t('c.enabled') . '</th><th>' . $this->t('c.self') . '</th><th>' . $this->t('c.auto') . '</th><th>' . $this->t('f.lang') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
                . '<p class="tdb-muted">' . $this->t('matrixHelp') . '</p>')
            . $this->box($this->t('general'), '<div class="tdb-checks">' . $this->toggle('signature', $g['signature'], $this->t('g.signature')) . $this->toggle('attach', $g['attach'], $this->t('g.attach')) . '</div>'
                . '<div class="tdb-grid2">' . $this->field($this->t('g.prefix'), '<input class="tdb-input" name="prefix" dir="ltr" value="' . self::e($g['prefix']) . '" maxlength="16">') . $this->field($this->t('g.cc'), '<input class="tdb-input" type="email" name="cc" dir="ltr" value="' . self::e($g['cc']) . '">', $this->t('g.ccHelp')) . '</div>'
                . '<p class="tdb-muted">' . $this->t('g.assets') . '</p>')
            . '<div class="tdb-actions tdb-sticky"><button class="tdb-btn tdb-btn--primary">' . $this->t('save') . '</button></div></form>';
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveSettings($args, $request)
    {
        $this->manager($request);
        $cid = $this->cid($request); $all = (array) $request->getUserVar('c');
        foreach (array_keys(Catalog::TYPES) as $type) {
            $v = (array) ($all[$type] ?? []);
            Catalog::saveCfg($this->plugin, $cid, $type, ['enabled' => !empty($v['enabled']), 'self' => !empty($v['self']), 'auto' => !empty($v['auto']),
                'lang' => ($v['lang'] ?? 'ar') === 'en' ? 'en' : 'ar', 'subject' => mb_substr(trim((string) ($v['subject'] ?? '')), 0, 250), 'body' => mb_substr(trim((string) ($v['body'] ?? '')), 0, 3000)]);
        }
        $this->plugin->updateSetting($cid, 'signature', (bool) $request->getUserVar('signature'), 'bool');
        $this->plugin->updateSetting($cid, 'attach', (bool) $request->getUserVar('attach'), 'bool');
        $this->plugin->updateSetting($cid, 'prefix', substr(preg_replace('/[^A-Za-z0-9]/', '', $this->v($request, 'prefix')) ?: 'TDB', 0, 16), 'string');
        $cc = $this->v($request, 'cc');
        $this->plugin->updateSetting($cid, 'cc', filter_var($cc, FILTER_VALIDATE_EMAIL) ? $cc : '', 'string');
        $this->back($request, 'settings');
    }

    // ================================================================ التحقق العام
    public function verify($args, $request)
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (($args[0] ?? '') ?: $request->getUserVar('code'))));
        $d = $code !== '' ? $this->dl($request)->store->byCode($code) : null;
        if ($d && (int) $d->context_id !== $this->cid($request)) $d = null;
        header('X-Robots-Tag: noindex');
        if ($code !== '' && (!$d || $d->status !== 'valid')) http_response_code(404);
        $h = '<div class="tdb tdb-verify">';
        if ($code === '') $h .= '';
        elseif (!$d) $h .= '<div class="tdb-alert tdb-alert--bad"><b>✖ ' . $this->t('v.notFound') . '</b></div>';
        else {
            $ok = $d->status === 'valid';
            $h .= '<div class="tdb-alert tdb-alert--' . ($ok ? 'ok' : 'bad') . '"><b>' . ($ok ? '✔ ' . $this->t('v.valid') : '✖ ' . $this->t('v.revoked')) . '</b></div>'
                . '<table class="tdb-table"><tr><th>' . $this->t('col.doc') . '</th><td>' . self::e($this->docName($d->doc_type)) . '</td></tr><tr><th>' . $this->t('col.number') . '</th><td dir="ltr">' . self::e($d->number) . '</td></tr>'
                . '<tr><th>' . $this->t('col.date') . '</th><td>' . self::e(substr((string) $d->issued_at, 0, 10)) . '</td></tr><tr><th>' . $this->t('v.issuer') . '</th><td>' . self::e($request->getContext()->getLocalizedName()) . '</td></tr></table>';
        }
        $h .= '<form method="get" class="tdb-toolbar" action="' . self::e($this->url($request, 'verify')) . '"><input class="tdb-input" name="code" dir="ltr" value="' . self::e($code) . '" placeholder="XXXXX-XXXXX-XXXXX-XXXXX" required><button class="tdb-btn tdb-btn--primary">' . $this->t('v.check') . '</button></form>'
            . '<p class="tdb-muted">' . $this->t('v.note') . '</p></div>';
        $this->showPublic($request, $this->t('v.title'), $h);
    }
}
