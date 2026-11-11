<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/documentCenter/classes/Delivery.php
 * إصدار المستند (بحسب صنفه)، وتوليد ملفه، وإرساله بالبريد مع المرفق.
 */
namespace APP\plugins\generic\tadabburSuite\m\documentCenter\classes;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Letters;
use APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Store as LetterStore;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\CertificateRenderer;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\CertificateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PKP\config\Config;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\mail\Mailable;
use PKP\plugins\PluginRegistry;

class Delivery
{
    public const LETTER = ['au_receipt' => 'receipt', 'au_acceptance' => 'acceptance', 'au_publication' => 'publication'];
    public Store $store;

    public function __construct(private $plugin, private $request, private $context)
    {
        $this->store = new Store();
    }

    private function cid(): int { return (int) $this->context->getId(); }
    private function url(string $page, string $op, array $params = [], ?array $path = null): string
    {
        return $this->request->getDispatcher()->url($this->request, PKPApplication::ROUTE_PAGE, $this->context->getPath(), $page, $op, $path, $params);
    }
    public function tempDir(): string
    {
        $base = (string) Config::getVar('files', 'files_dir');
        $d = ($base !== '' && is_dir($base) && is_writable($base)) ? rtrim($base, '/') . '/temp/tdbDocuments' : sys_get_temp_dir() . '/tdbDocuments';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }
    private function t(string $k, array $p = [], ?string $lang = null): string { return __('plugins.generic.documentCenter.' . $k, $p, $lang); }
    public function journal(string $lang): string { return (string) ($this->context->getName($lang) ?: $this->context->getLocalizedName()); }

    private function module(string $key) { return Modules::instance($key); }

    // ================================================================ الإصدار
    /**
     * يصدر المستند أو يعيد الصالح القائم من النوع واللغة نفسيهما (ما لم يُطلب إصدار جديد).
     * @param array{honorific?:string,lang?:string,note?:string,release?:bool,fresh?:bool,by?:int} $opt
     */
    public function issue(string $type, $sub, ?object $review, array $opt = []): object
    {
        $cid = $this->cid(); $lang = ($opt['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $sid = (int) $sub->getId(); $rid = $review ? (int) $review->review_id : null;
        if (($why = Catalog::why($type, $sub, $review)) !== null) throw new \RuntimeException($this->t('why.' . $why));
        $cur = $this->store->current($cid, $type, $sid, $rid, $lang);
        $note = trim((string) ($opt['note'] ?? ''));
        if ($cur && empty($opt['fresh']) && ($note === '' || $note === (string) $cur->note)) {
            if (!empty($opt['release']) && !$cur->released) { $this->store->release((int) $cur->doc_id); $cur->released = 1; }
            return $cur;
        }
        if ($cur && !empty($opt['fresh'])) $this->store->revoke((int) $cur->doc_id, $cid, 'replaced');

        // المخاطَب واللقب
        $uid = null; $name = ''; $u = null;
        if ($review) { $u = Catalog::user((int) $review->reviewer_id); $uid = $u ? (int) $u->getId() : null; $name = $u ? $u->getFullName(true, false, $lang) : ''; }
        else {
            $pa = Catalog::primaryAuthor($sub); $name = $lang === 'en' && $pa['name_en'] !== '' ? $pa['name_en'] : $pa['name'];
            $au = Catalog::authorUsers($sid); $uid = $au[0]['id'] ?? null; $u = $uid ? Catalog::user($uid) : null;
        }
        $hk = (string) ($opt['honorific'] ?? '');
        if ($hk === '' || $hk === 'auto') $hk = ($uid && ($p = $this->store->pref($uid))) ? $p->honorific : ($u ? Catalog::guessHonorific($u) : 'none');
        if (!array_key_exists($hk, Catalog::honorifics()['list'])) $hk = 'none';
        if ($uid && !empty($opt['remember'])) $this->store->setPref($uid, $hk, $lang);

        $data = ['journal' => $this->journal($lang), 'title' => Catalog::title($sub, $lang) ?: Catalog::title($sub), 'sid' => $sid, 'name' => $name,
            'honorific' => $lang === 'ar' ? Catalog::honorificLabel($hk) : '', 'completed' => $review && $review->date_completed ? substr((string) $review->date_completed, 0, 10) : ''];
        $kind = Catalog::kind($type);
        if ($kind === 'cert') {
            $rc = $this->module('reviewerCertificate'); $rcfg = $rc ? $rc->cfg($this->request) : ['journal_prefix' => 'TDB', 'date_format' => 'd-m-Y'];
            $certDate = date($rcfg['date_format'] ?? 'd-m-Y', strtotime((string) ($review->date_completed ?: 'now')));
            $repo = new CertificateRepository();
            $cert = $repo->issue($cid, $review, $u ? $u->getFullName(true, false, 'ar') : $name, Catalog::honorificLabel($hk), (int) ($opt['by'] ?? 0), (string) ($rcfg['journal_prefix'] ?? 'TDB'), $certDate);
            $data['certCode'] = $cert->code;
        } elseif ($kind === 'letter') {
            $ls = new LetterStore();
            $letter = (!empty($opt['fresh']) ? null : $ls->current($cid, $sid, self::LETTER[$type], $lang));
            if (!$letter) {
                $journalUrl = $this->url('article', 'view', [], [$sub->getBestId()]);
                $snap = (new Letters(Modules::root() . '/officialLetters'))->snapshot($sub, $this->context, $lang, $journalUrl);
                $letter = $ls->add($cid, $sid, self::LETTER[$type], $lang, $snap, (int) ($opt['by'] ?? 0));
            }
            $data['letterId'] = (int) $letter->letter_id;
        } elseif ($type === 'au_reviews') {
            $h = ''; $i = 0;
            foreach (DB::table('review_assignments')->where('submission_id', $sid)->where('cancelled', 0)->where('declined', 0)->whereNotNull('date_completed')->orderBy('round')->orderBy('review_id')->get() as $ra) {
                $c = Catalog::reviewComments((int) $ra->review_id);
                if (!$c) continue;
                $i++;
                $h .= '<div style="margin:0 0 4mm;page-break-inside:auto"><div style="font-weight:bold;font-size:12.5pt;color:#43260f;border-bottom:0.3mm solid #c5a158;margin-bottom:2mm">'
                    . Render::e($this->t('tx.reviewer', ['n' => $i, 'r' => (int) $ra->round], $lang)) . '</div><div style="font-size:11.5pt;line-height:1.8">'
                    . implode('<br>', array_map(fn ($x) => nl2br(Render::e(\APP\plugins\generic\tadabburSuite\classes\kit\Meta::plain($x))), $c)) . '</div></div>';
            }
            $data['reviewsHtml'] = $h;
        }
        $g = Catalog::general($this->plugin, $cid);
        return $this->store->add(['context_id' => $cid, 'doc_type' => $type, 'submission_id' => $sid, 'review_id' => $rid, 'user_id' => $uid,
            'honorific' => $hk, 'lang' => $lang, 'number' => $this->store->nextNumber($cid, $type, $g['prefix'], Catalog::abbr($type)),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'note' => $note !== '' ? mb_substr($note, 0, 3000) : null,
            'released' => !empty($opt['release']) ? 1 : 0, 'issued_by' => $opt['by'] ?? null]);
    }

    // ================================================================ الملف
    /** @return array{0:string,1:string}|null [المحتوى، اسم الملف] أو null للنماذج التفاعلية */
    public function pdf(object $doc): ?array
    {
        $d = json_decode((string) $doc->data, true) ?: [];
        $type = $doc->doc_type; $lang = $doc->lang === 'en' ? 'en' : 'ar';
        $kind = Catalog::kind($type);
        $file = preg_replace('/[^A-Za-z0-9-]/', '-', (string) $doc->number) . '.pdf';
        if ($kind === 'kit') return null;
        if ($kind === 'cert') {
            $rec = (new CertificateRepository())->byCode($this->cid(), (string) ($d['certCode'] ?? ''));
            if (!$rec) throw new \RuntimeException('certificate missing');
            $verify = $this->url('reviewerCertificate', 'verify', [], [$rec->code]);
            $bytes = (new CertificateRenderer(Modules::root() . '/reviewerCertificate'))->pdf([['honorific' => (string) $rec->honorific, 'name' => (string) $rec->reviewer_name,
                'date' => (string) ($rec->cert_date ?: substr((string) $rec->issued_at, 0, 10)), 'code' => (string) $rec->code, 'verifyUrl' => $verify]], $this->tempDir());
            return [$bytes, 'certificate-' . substr($rec->code, -8) . '.pdf'];
        }
        if ($kind === 'letter') {
            $l = DB::table(LetterStore::TABLE)->where('letter_id', (int) ($d['letterId'] ?? 0))->first();
            if (!$l) throw new \RuntimeException('letter missing');
            $ol = $this->module('officialLetters');
            $cfg = $ol ? $ol->cfg($this->cid()) : ['signature' => true, 'note_ar' => '', 'note_en' => ''];
            $bytes = (new Letters(Modules::root() . '/officialLetters'))->pdf($l, $cfg, $this->url('officialLetters', 'verify', [], [$l->code]), $this->tempDir());
            return [$bytes, str_replace('/', '-', (string) $l->number) . '.pdf'];
        }
        $r = new Render($this->tempDir());
        $g = Catalog::general($this->plugin, $this->cid());
        $verify = $this->url('documents', 'verify', [], [$doc->code]);
        $d['note'] = (string) $doc->note;
        if ($type === 'au_thanks') {
            $t = $r->certText($type, $d, $lang);
            return [$r->certificate(['lang' => $lang, 'heading' => $t['heading'], 'sub' => $t['sub'], 'honorific' => $d['honorific'] ?? '', 'name' => $d['name'] ?? '',
                'bodyText' => $t['body'], 'date' => (string) $doc->issued_at, 'number' => (string) $doc->number, 'code' => (string) $doc->code, 'verifyUrl' => $verify,
                'journal' => $d['journal'] ?? '', 'signature' => $g['signature']]), $file];
        }
        return [$r->letter(['title' => __('plugins.generic.documentCenter.tx.' . $type . '.title', [], $lang), 'body' => $r->body($type, $d, $lang), 'lang' => $lang,
            'number' => (string) $doc->number, 'date' => (string) $doc->issued_at, 'code' => (string) $doc->code, 'verifyUrl' => $verify, 'signature' => $g['signature'], 'journal' => $d['journal'] ?? '']), $file];
    }

    /** رابط فتح المستند داخل المنصة (يتطلب تسجيل الدخول) */
    public function openUrl(object $doc): string
    {
        return Catalog::kind($doc->doc_type) === 'kit' ? $this->url('documents', 'kit', ['doc' => $doc->doc_id]) : $this->url('documents', 'file', ['doc' => $doc->doc_id]);
    }

    // ================================================================ البريد
    /** المستلمون الافتراضيون: المحكّم، أو مستخدمو المؤلفين، أو بريد المؤلف المراسل */
    public function defaultRecipients(object $doc, $sub): array
    {
        if ($doc->review_id) {
            $u = $doc->user_id ? Catalog::user((int) $doc->user_id) : null;
            return $u ? [['id' => (int) $u->getId(), 'name' => $u->getFullName(), 'email' => (string) $u->getEmail()]] : [];
        }
        $list = Catalog::authorUsers((int) $sub->getId());
        if (!$list) { $pa = Catalog::primaryAuthor($sub); if ($pa['email'] !== '') $list[] = ['id' => 0, 'name' => $pa['name'], 'email' => $pa['email']]; }
        return $list;
    }

    /** @return array{0:int,1:string[]} [عدد المقبول، الأخطاء] */
    public function send(object $doc, $sub, array $recipients): array
    {
        $cid = $this->cid(); $lang = $doc->lang === 'en' ? 'en' : 'ar';
        $cfg = Catalog::cfg($this->plugin, $cid, $doc->doc_type); $g = Catalog::general($this->plugin, $cid);
        $d = json_decode((string) $doc->data, true) ?: [];
        $docName = $this->t('doc.' . $doc->doc_type, [], $lang);
        $link = $this->openUrl($doc);
        $file = null;
        if ($g['attach']) { try { $file = $this->pdf($doc); } catch (\Throwable $e) { error_log('[documentCenter] pdf: ' . $e->getMessage()); } }
        $ok = 0; $errs = [];
        foreach ($recipients as $r) {
            $to = trim((string) ($r['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $errs[] = $to . ': ' . $this->t('err.email'); continue; }
            $who = trim((($d['honorific'] ?? '') !== '' && (int) ($r['id'] ?? 0) === (int) $doc->user_id ? $d['honorific'] . ' ' : '') . (string) ($r['name'] ?? ''));
            $p = ['name' => $who, 'doc' => $docName, 'journal' => $d['journal'] ?? $this->journal($lang), 'title' => $d['title'] ?? '', 'link' => $link, 'number' => (string) $doc->number];
            $subject = $cfg['subject'] !== '' ? self::fill($cfg['subject'], $p) : $this->t('mail.subject', $p, $lang);
            $bodyTxt = $cfg['body'] !== '' ? self::fill($cfg['body'], $p) : $this->t('mail.body.' . Catalog::aud($doc->doc_type), $p, $lang);
            $html = '<div dir="' . ($lang === 'en' ? 'ltr' : 'rtl') . '" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.9">'
                . '<p>' . Render::e($this->t('mail.hello', ['name' => $who], $lang)) . '</p>' . nl2br(Render::e($bodyTxt))
                . '<p>' . Render::e($this->t('mail.open', [], $lang)) . ': <a href="' . Render::e($link) . '">' . Render::e($link) . '</a><br>'
                . Render::e($this->t('mail.number', [], $lang)) . ': <span dir="ltr">' . Render::e((string) $doc->number) . '</span></p>'
                . '<p>' . nl2br(Render::e($this->t('mail.sign', ['journal' => $p['journal']], $lang))) . '</p></div>';
            try {
                $m = new Mailable([$this->context]);
                $from = (string) ($this->context->getData('contactEmail') ?: Config::getVar('email', 'default_envelope_sender'));
                if ($from !== '') $m->from($from, (string) ($this->context->getData('contactName') ?: $p['journal']));
                $m->to($to, (string) ($r['name'] ?? ''));
                if ($g['cc'] !== '' && filter_var($g['cc'], FILTER_VALIDATE_EMAIL)) $m->cc($g['cc']);
                $m->subject(mb_substr($subject, 0, 250));
                $m->body($html);
                if ($file) $m->attachData($file[0], $file[1], ['mime' => 'application/pdf']);
                Mail::send($m);
                $this->store->markSent((int) $doc->doc_id, $to, 'accepted');
                $ok++;
            } catch (\Throwable $e) {
                $this->store->markSent((int) $doc->doc_id, $to, 'failed', $e->getMessage());
                error_log('[documentCenter] mail: ' . $e->getMessage());
                $errs[] = $to . ': ' . mb_substr($e->getMessage(), 0, 140);
            }
        }
        if ($ok) $this->store->release((int) $doc->doc_id);
        return [$ok, $errs];
    }

    public static function fill(string $tpl, array $p): string
    {
        return strtr($tpl, ['{name}' => $p['name'], '{doc}' => $p['doc'], '{journal}' => $p['journal'], '{title}' => $p['title'], '{link}' => $p['link'], '{number}' => $p['number']]);
    }
}
