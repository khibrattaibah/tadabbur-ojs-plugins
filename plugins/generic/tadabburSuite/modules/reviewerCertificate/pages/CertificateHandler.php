<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerCertificate/pages/CertificateHandler.php
 *
 * مسارات الإضافة تحت /{journal}/{locale}/reviewerCertificate/...
 *   index            لوحة الإدارة (مدير المجلة)
 *   issue|issueBulk|email|revoke|reissue   إجراءات POST + CSRF (مدير المجلة)
 *   printCerts       عرض/PDF لعدة شهادات (مدير المجلة)
 *   doc              ملفات التحكيم (مدير المجلة؛ والمحكّم لنماذج تحكيمه فقط)
 *   view/{code}      الشهادة HTML (صاحبها أو المدير)   pdf/{code}  الشهادة PDF
 *   mine             «شهاداتي وملفات التحكيم» للمحكّم
 *   verify[/{code}]  صفحة تحقق عامة (حالة الشهادة فقط، دون بيانات سرية)
 * ملاحظة: محتوى لوحة OJS 3.5 يُعرض داخل تطبيق Vue؛ لذا لا نستعمل <script> ولا <style> داخلها، والتنسيق مضمّن.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerCertificate\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\CertificateRenderer;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\CertificateRepository;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes\ReviewKitRenderer;
use APP\plugins\generic\tadabburSuite\m\reviewerCertificate\ReviewerCertificatePlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\mail\Mailable;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class CertificateHandler extends Handler
{
    private const MANAGER_OPS = ['index', 'issue', 'issueBulk', 'email', 'revoke', 'reissue', 'printCerts'];
    private const POST_OPS = ['issue', 'issueBulk', 'email', 'revoke', 'reissue'];

    // أنماط مضمّنة (واجهة 3.5 تُلغي تنسيق العناصر الافتراضي)
    private const S_BTN = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #006798;border-radius:4px;background:#fff;color:#006798;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5';
    private const S_BTNP = 'display:inline-block;margin:2px;padding:6px 14px;border:1px solid #006798;border-radius:4px;background:#006798;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.5';
    private const S_BTND = 'display:inline-block;margin:2px;padding:5px 12px;border:1px solid #b02a2a;border-radius:4px;background:#fff;color:#b02a2a;font-weight:700;font-size:13px;cursor:pointer';
    private const S_INP = 'border:1px solid #9aa5b1;border-radius:4px;padding:4px 6px;font-size:13px;background:#fff;color:#222;min-height:30px';
    private const S_TBL = 'width:100%;border-collapse:collapse;font-size:13px;background:#fff';
    private const S_TH = 'background:#ecf0f3;border:1px solid #d5dbe1;padding:8px;text-align:right;vertical-align:bottom';
    private const S_TD = 'border:1px solid #d5dbe1;padding:8px;vertical-align:top;';
    private const S_BOX = 'border:1px solid #d5dbe1;border-radius:6px;padding:12px 14px;margin:0 0 14px;background:#fff;';

    private CertificateRepository $repo;

    public function __construct(private ReviewerCertificatePlugin $plugin)
    {
        parent::__construct();
        $this->repo = new CertificateRepository();
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function url($request, string $op, array $path = [], array $params = []): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, ReviewerCertificatePlugin::PAGE, $op, $path, $params);
    }

    private function csrf($request): string
    {
        return '<input type="hidden" name="csrfToken" value="' . self::e($request->getSession()->token()) . '">';
    }

    private function requireLogin($request): void
    {
        if (!$request->getUser()) { Validation::redirectLogin(); exit; }
    }

    private function deny(string $msg = 'غير مصرح بالوصول.'): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><body style="font:16px Tahoma,Arial;padding:40px;color:#7a1f1f">' . self::e($msg) . '</body></html>';
        exit;
    }

    private function backend($request, string $title, string $html): void
    {
        $this->_isBackendPage = true;
        $this->setupTemplate($request);
        $tm = TemplateManager::getManager($request);
        $tm->assign(['pageTitle' => $title, 'rcHtml' => $html]);
        $tm->display($this->plugin->getTemplateResource('page.tpl'));
    }

    // ------------------------------------------------------------------ بيانات التحكيم
    private function reviews(int $contextId, ?int $submissionId = null, ?int $reviewerId = null, int $limit = 300): array
    {
        $q = DB::table('review_assignments as ra')
            ->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $contextId)->where('ra.cancelled', 0)
            ->select('ra.review_id', 'ra.submission_id', 'ra.reviewer_id', 'ra.round', 'ra.date_assigned', 'ra.date_due', 'ra.date_completed', 'ra.declined')
            ->orderByDesc('ra.review_id')->limit($limit);
        if ($submissionId) $q->where('ra.submission_id', $submissionId);
        if ($reviewerId) $q->where('ra.reviewer_id', $reviewerId);
        return $q->get()->all();
    }

    private function review(int $contextId, int $reviewId): ?object
    {
        return DB::table('review_assignments as ra')
            ->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $contextId)->where('ra.review_id', $reviewId)->where('ra.cancelled', 0)
            ->select('ra.*')->first();
    }

    private static function completed(?object $r): bool
    {
        return $r && $r->date_completed && !(int) $r->declined;
    }

    private array $titles = [];
    private function title(int $sid): string
    {
        if (!isset($this->titles[$sid])) {
            $pub = Repo::submission()->get($sid)?->getCurrentPublication();
            $this->titles[$sid] = $pub ? trim(strip_tags((string) $pub->getLocalizedFullTitle('ar'))) : '';
        }
        return $this->titles[$sid];
    }

    private array $users = [];
    private function user(int $id)
    {
        return $this->users[$id] ??= Repo::user()->get($id, true);
    }

    private function guessHonorific($user, array $cfg): string
    {
        $sal = mb_strtolower((string) $user->getData('salutation'));
        $female = strtoupper((string) $user->getData('gender')) === 'F';
        if ($sal !== '' && (str_contains($sal, 'prof') || str_contains($sal, 'أ.د') || str_contains($sal, 'أستاذ'))) return $female ? 'prof_f' : 'prof_m';
        if ($sal !== '' && (str_contains($sal, 'dr') || str_contains($sal, 'د.'))) return $female ? 'dr_f' : 'dr_m';
        return 'none';
    }

    private function honorificSelect(array $cfg, string $name, string $selected, string $form = ''): string
    {
        $o = '';
        foreach ($cfg['honorifics'] as $k => $label) {
            $o .= '<option value="' . self::e($k) . '"' . ($k === $selected ? ' selected' : '') . '>' . self::e($label !== '' ? $label : '(بدون لقب)') . '</option>';
        }
        return '<select name="' . $name . '"' . ($form ? ' form="' . $form . '"' : '') . ' style="' . self::S_INP . '">' . $o . '</select>';
    }

    private function certDate(object $review, array $cfg): string
    {
        $fmt = $cfg['date_format'] ?? 'd-m-Y';
        if (($cfg['certificate_date'] ?? 'completed') === 'completed' && $review->date_completed) {
            return date($fmt, strtotime((string) $review->date_completed));
        }
        return date($fmt);
    }

    private function certData($request, object $rec): array
    {
        return [
            'honorific' => (string) $rec->honorific, 'name' => (string) $rec->reviewer_name,
            'date' => (string) ($rec->cert_date ?: date('d-m-Y', strtotime((string) $rec->issued_at))),
            'code' => (string) $rec->code, 'verifyUrl' => $this->url($request, 'verify', [$rec->code]),
        ];
    }

    private function tempDir(): string
    {
        $base = (string) Config::getVar('files', 'files_dir');
        $dir = ($base !== '' && is_dir($base) && is_writable($base)) ? rtrim($base, '/') . '/temp/reviewerCertificate' : sys_get_temp_dir() . '/reviewerCertificate';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    // ------------------------------------------------------------------ لوحة الإدارة
    public function index($args, $request)
    {
        $this->managerGate($request);
        $this->managePage($request, '');
    }

    private function managerGate($request): void
    {
        $this->requireLogin($request);
        if (!$this->plugin->isManager($request)) $this->deny('هذا القسم لمدير المجلة فقط.');
        $this->repo->ensureSchema();
    }

    private function managePage($request, string $notice, bool $ok = true): void
    {
        $cfg = $this->plugin->cfg($request);
        $context = $request->getContext(); $cid = (int) $context->getId();
        $filter = (int) $request->getUserVar('submissionId');
        $show = (string) $request->getUserVar('show');
        $records = $this->repo->currentForContext($cid);
        $counts = $this->repo->counts($cid);
        $csrf = $this->csrf($request);
        $self = $this->url($request, 'index');
        $today = date($cfg['date_format']);

        $h = '';
        if ($notice !== '') {
            $h .= '<div style="' . self::S_BOX . 'border-color:' . ($ok ? '#1e7b4a' : '#b02a2a') . ';background:' . ($ok ? '#eef8f2' : '#fdf0f0') . '"><strong>' . self::e($notice) . '</strong></div>';
        }
        $h .= '<div style="' . self::S_BOX . 'display:flex;gap:28px;flex-wrap:wrap;align-items:center">'
            . '<div><div style="font-size:26px;font-weight:700;color:#1e7b4a">' . $counts['valid'] . '</div>شهادة سارية</div>'
            . '<div><div style="font-size:26px;font-weight:700;color:#006798">' . $counts['sent'] . '</div>أُرسلت بالبريد</div>'
            . '<div><div style="font-size:26px;font-weight:700;color:#b02a2a">' . $counts['revoked'] . '</div>ملغاة</div>'
            . '<div style="flex:1;min-width:260px;font-size:13px;color:#444">كل شهادة مرتبطة بمهمة تحكيم مكتملة واحدة، برقم عشوائي فريد ورمز QR يفتح صفحة تحقق عامة لا تكشف البحث ولا المحكّم. '
            . 'صفحة التحقق اليدوي للجمهور: <a href="' . self::e($this->url($request, 'verify')) . '" target="_blank" rel="noopener">' . self::e($this->url($request, 'verify')) . '</a>'
            . (CertificateRenderer::pdfAvailable() ? '' : '<br><b style="color:#b02a2a">تنبيه: مكتبة mPDF غير متاحة؛ سيُكتفى بالطباعة من المتصفح ولن يُرفق PDF بالبريد.</b>') . '</div></div>';

        // التصفية + الإجراءات الجماعية
        $h .= '<div style="' . self::S_BOX . '"><form method="get" action="' . self::e($self) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            . '<label>رقم البحث في OJS: <input type="number" min="1" name="submissionId" value="' . ($filter ?: '') . '" style="' . self::S_INP . ';width:110px"></label>'
            . '<label>العرض: <select name="show" style="' . self::S_INP . '">'
            . '<option value="">كل مهام التحكيم</option><option value="done"' . ($show === 'done' ? ' selected' : '') . '>المكتملة فقط</option>'
            . '<option value="nocert"' . ($show === 'nocert' ? ' selected' : '') . '>مكتملة بلا شهادة</option></select></label>'
            . '<button type="submit" style="' . self::S_BTN . '">تطبيق</button>'
            . ($filter || $show ? '<a href="' . self::e($self) . '" style="' . self::S_BTN . '">إلغاء التصفية</a>' : '') . '</form>'
            . '<form id="rcBulk" method="post" action="' . self::e($this->url($request, 'issueBulk')) . '" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">' . $csrf
            . '<span>المحدد في الجدول:</span><select name="honorific" style="' . self::S_INP . '"><option value="auto">اللقب: تخمين من الملف الشخصي</option>'
            . implode('', array_map(fn ($k, $l) => '<option value="' . self::e($k) . '">' . self::e($l !== '' ? $l : '(بدون لقب)') . '</option>', array_keys($cfg['honorifics']), $cfg['honorifics'])) . '</select>'
            . '<button type="submit" style="' . self::S_BTNP . '">إصدار شهادات للمحدد</button>'
            . '<button type="submit" formtarget="_blank" formaction="' . self::e($this->url($request, 'printCerts')) . '" style="' . self::S_BTN . '">عرض/طباعة شهادات المحدد</button>'
            . (CertificateRenderer::pdfAvailable() ? '<button type="submit" name="format" value="pdf" formtarget="_blank" formaction="' . self::e($this->url($request, 'printCerts')) . '" style="' . self::S_BTN . '">PDF واحد للمحدد</button>' : '')
            . '</form></div>';

        // الجدول
        $rows = '';
        foreach ($this->reviews($cid, $filter ?: null) as $r) {
            $rid = (int) $r->review_id; $sid = (int) $r->submission_id;
            $done = self::completed($r); $rec = $records[$rid] ?? null;
            if ($show === 'done' && !$done) continue;
            if ($show === 'nocert' && (!$done || ($rec && $rec->status === 'valid'))) continue;
            $u = $this->user((int) $r->reviewer_id);
            if (!$u) continue;
            $guess = $this->guessHonorific($u, $cfg);
            $status = $done ? '<span style="color:#1e7b4a;font-weight:700">مكتمل</span><br><small>' . self::e(substr((string) $r->date_completed, 0, 10)) . '</small>'
                : ((int) $r->declined ? '<span style="color:#b02a2a">اعتذر</span>' : '<span style="color:#8a6d1f">قيد التحكيم</span>' . ($r->date_due ? '<br><small>الموعد: ' . self::e(substr((string) $r->date_due, 0, 10)) . '</small>' : ''));

            // خلية الشهادة
            if (!$done) {
                $cert = '<small style="color:#777">تُتاح بعد اكتمال التحكيم.</small>';
            } elseif (!$rec || $rec->status !== 'valid') {
                $cert = ($rec ? '<div style="color:#b02a2a;font-size:12px">الشهادة السابقة ملغاة: ' . self::e($rec->revoke_reason ?? '') . '</div>' : '')
                    . '<form method="post" action="' . self::e($this->url($request, 'issue')) . '" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">' . $csrf
                    . '<input type="hidden" name="reviewId" value="' . $rid . '">' . $this->honorificSelect($cfg, 'honorific', $guess)
                    . '<button type="submit" style="' . self::S_BTNP . '">إصدار الشهادة</button></form>';
            } else {
                $code = (string) $rec->code;
                $mail = ['not_sent' => 'لم تُرسل', 'accepted' => 'قُبلت للإرسال', 'failed' => 'فشل الإرسال'][$rec->email_status] ?? $rec->email_status;
                $cert = '<code dir="ltr" style="font-size:11px;word-break:break-all">' . self::e($code) . '</code>'
                    . '<div style="font-size:12px;margin:3px 0">' . self::e(trim($rec->honorific . ' ' . $rec->reviewer_name)) . ' — البريد: ' . self::e($mail)
                    . ($rec->email_status === 'failed' && $rec->email_error ? ' <span style="color:#b02a2a">(' . self::e(mb_substr($rec->email_error, 0, 80)) . ')</span>' : '') . '</div>'
                    . '<a target="_blank" rel="noopener" href="' . self::e($this->url($request, 'view', [$code])) . '" style="' . self::S_BTN . '">عرض</a>'
                    . (CertificateRenderer::pdfAvailable() ? '<a href="' . self::e($this->url($request, 'pdf', [$code])) . '" style="' . self::S_BTN . '">PDF</a>' : '')
                    . '<a target="_blank" rel="noopener" href="' . self::e($this->url($request, 'verify', [$code])) . '" style="' . self::S_BTN . '">صفحة التحقق</a>'
                    . '<form method="post" action="' . self::e($this->url($request, 'email')) . '" style="display:inline">' . $csrf . '<input type="hidden" name="code" value="' . self::e($code) . '">'
                    . '<button type="submit" style="' . self::S_BTN . '">' . ($rec->email_status === 'accepted' ? 'إعادة الإرسال' : 'إرسال للبريد المسجل') . '</button></form>'
                    . '<details style="margin-top:4px"><summary style="cursor:pointer;color:#555;font-size:12px">تصحيح أو إلغاء</summary>'
                    . '<form method="post" action="' . self::e($this->url($request, 'reissue')) . '" style="margin:6px 0">' . $csrf . '<input type="hidden" name="code" value="' . self::e($code) . '">'
                    . $this->honorificSelect($cfg, 'honorific', (string) (array_search($rec->honorific, $cfg['honorifics'], true) ?: 'none'))
                    . ' <input name="reason" required placeholder="سبب التصحيح" style="' . self::S_INP . ';width:150px"> <button type="submit" style="' . self::S_BTN . '">إعادة إصدار برقم جديد</button></form>'
                    . '<form method="post" action="' . self::e($this->url($request, 'revoke')) . '">' . $csrf . '<input type="hidden" name="code" value="' . self::e($code) . '">'
                    . '<input name="reason" required placeholder="سبب الإلغاء" style="' . self::S_INP . ';width:150px"> <button type="submit" style="' . self::S_BTND . '">إلغاء الشهادة</button></form></details>';
            }

            // خلية ملفات التحكيم
            $kit = '<form method="get" target="_blank" action="' . self::e($this->url($request, 'doc')) . '" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">'
                . '<input type="hidden" name="rid" value="' . $rid . '">'
                . '<select name="type" style="' . self::S_INP . '"><option value="invite">خطاب دعوة التحكيم</option><option value="forms">نموذجا النتيجة والتقويم</option>'
                . '<option value="revision">خطاب طلب التعديل (للباحث)</option><option value="matrix">مصفوفة استجابة الباحث</option></select>'
                . $this->honorificSelect($cfg, 'h', $guess)
                . '<input name="ref" placeholder="الرقم الصادر" style="' . self::S_INP . ';width:95px" dir="ltr">'
                . '<input name="date" value="' . self::e($today) . '" style="' . self::S_INP . ';width:95px" dir="ltr">'
                . '<button type="submit" style="' . self::S_BTN . '">فتح</button></form>';

            $wf = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'workflow', 'access', [$sid]);
            $rows .= '<tr>'
                . '<td style="' . self::S_TD . ';text-align:center">' . ($done && (!$rec || $rec->status !== 'valid') ? '<input type="checkbox" name="rids[]" value="' . $rid . '" form="rcBulk">' : '')
                . ($rec && $rec->status === 'valid' ? '<input type="checkbox" name="codes[]" value="' . self::e($rec->code) . '" form="rcBulk">' : '') . '</td>'
                . '<td style="' . self::S_TD . '"><a href="' . self::e($wf) . '">#' . $sid . '</a> <small>(جولة ' . (int) $r->round . ')</small><br><small>' . self::e(mb_substr($this->title($sid), 0, 90)) . '</small></td>'
                . '<td style="' . self::S_TD . '">' . self::e($u->getFullName(true, false, 'ar')) . '<br><small dir="ltr">' . self::e($u->getEmail()) . '</small></td>'
                . '<td style="' . self::S_TD . 'white-space:nowrap">' . $status . '</td><td style="' . self::S_TD . ';min-width:260px">' . $cert . '</td><td style="' . self::S_TD . ';min-width:250px">' . $kit . '</td></tr>';
        }
        $h .= '<table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . ';width:34px">تحديد</th><th style="' . self::S_TH . '">البحث</th><th style="' . self::S_TH . '">المحكّم والبريد المسجل</th>'
            . '<th style="' . self::S_TH . '">حالة التحكيم</th><th style="' . self::S_TH . '">الشهادة</th><th style="' . self::S_TH . '">ملفات التحكيم</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="6" style="' . self::S_TD . '">لا توجد مهام تحكيم مطابقة.</td></tr>') . '</tbody></table>'
            . '<p style="font-size:12px;color:#555">يُعرض آخر 300 مهمة تحكيم؛ استعمل التصفية برقم البحث للوصول إلى الأقدم. التاريخ الهجري في الخطابات يُحسب آليًا فراجعه قبل الإرسال.</p>';
        $this->backend($request, 'شهادات المحكّمين وحزمة التحكيم', $h);
    }

    // ------------------------------------------------------------------ إجراءات POST
    private function postGate($request): void
    {
        $this->managerGate($request);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || !$request->checkCSRF()) {
            $this->deny('رفض الطلب: يلزم إرسال آمن (POST) مع رمز CSRF صحيح. حدّث الصفحة وأعد المحاولة.');
        }
    }

    private function run($request, callable $fn): void
    {
        $this->postGate($request);
        try {
            $this->managePage($request, (string) $fn(), true);
        } catch (\Throwable $e) {
            error_log('[reviewerCertificate] ' . $e->getMessage());
            $this->managePage($request, $e->getMessage(), false);
        }
    }

    private function doIssue($request, int $reviewId, string $hKey, ?object $replacing = null): object
    {
        $cfg = $this->plugin->cfg($request); $cid = (int) $request->getContext()->getId();
        $review = $this->review($cid, $reviewId);
        if (!self::completed($review)) throw new \RuntimeException('لا توجد مهمة تحكيم مكتملة بهذا الرقم في هذه المجلة.');
        $u = $this->user((int) $review->reviewer_id);
        if (!$u) throw new \RuntimeException('حساب المحكّم غير موجود.');
        if ($hKey === 'auto') $hKey = $this->guessHonorific($u, $cfg);
        $honorific = (string) ($cfg['honorifics'][$hKey] ?? '');
        return $this->repo->issue($cid, $review, $u->getFullName(true, false, 'ar'), $honorific, (int) $request->getUser()->getId(),
            (string) $cfg['journal_prefix'], $this->certDate($review, $cfg), $replacing);
    }

    public function issue($args, $request)
    {
        $this->run($request, function () use ($request) {
            $before = $this->repo->current((int) $request->getContext()->getId(), (int) $request->getUserVar('reviewId'));
            $rec = $this->doIssue($request, (int) $request->getUserVar('reviewId'), (string) $request->getUserVar('honorific'));
            return ($before && $before->code === $rec->code ? 'للمهمة شهادة سارية بالفعل: ' : 'صدرت الشهادة برقم: ') . $rec->code;
        });
    }

    public function issueBulk($args, $request)
    {
        $this->run($request, function () use ($request) {
            $ids = array_slice(array_unique(array_map('intval', (array) $request->getUserVar('rids'))), 0, 100);
            if (!$ids) throw new \RuntimeException('لم تحدد أي مهمة تحكيم مكتملة بلا شهادة.');
            $n = 0; $err = [];
            foreach ($ids as $id) {
                try { $this->doIssue($request, $id, (string) $request->getUserVar('honorific')); $n++; } catch (\Throwable $e) { $err[] = '#' . $id . ': ' . $e->getMessage(); }
            }
            return 'صدرت ' . $n . ' شهادة.' . ($err ? ' تعذّر: ' . implode('؛ ', $err) : '');
        });
    }

    public function revoke($args, $request)
    {
        $this->run($request, function () use ($request) {
            $cid = (int) $request->getContext()->getId();
            $code = strtoupper(trim((string) $request->getUserVar('code')));
            $reason = trim((string) $request->getUserVar('reason'));
            $rec = $this->repo->byCode($cid, $code);
            if (!$rec || $rec->status !== 'valid') throw new \RuntimeException('الشهادة غير موجودة أو ملغاة مسبقًا.');
            if ($reason === '') throw new \RuntimeException('يلزم ذكر سبب الإلغاء.');
            $this->repo->revoke($cid, $code, (int) $request->getUser()->getId(), $reason);
            return 'أُلغيت الشهادة ' . $code . '، وستظهر صفحة التحقق أنها غير سارية.';
        });
    }

    public function reissue($args, $request)
    {
        $this->run($request, function () use ($request) {
            $cid = (int) $request->getContext()->getId();
            $code = strtoupper(trim((string) $request->getUserVar('code')));
            $reason = trim((string) $request->getUserVar('reason'));
            $rec = $this->repo->byCode($cid, $code);
            if (!$rec || $rec->status !== 'valid') throw new \RuntimeException('الشهادة غير موجودة أو ملغاة.');
            if ($reason === '') throw new \RuntimeException('يلزم ذكر سبب التصحيح.');
            $this->repo->revoke($cid, $code, (int) $request->getUser()->getId(), 'استُبدلت: ' . $reason);
            $new = $this->doIssue($request, (int) $rec->review_id, (string) $request->getUserVar('honorific'), $rec);
            return 'أُلغيت الشهادة ' . $code . ' وصدرت بديلتها برقم ' . $new->code . '.';
        });
    }

    public function email($args, $request)
    {
        $this->run($request, function () use ($request) {
            $context = $request->getContext(); $cid = (int) $context->getId(); $cfg = $this->plugin->cfg($request);
            $code = strtoupper(trim((string) $request->getUserVar('code')));
            $rec = $this->repo->byCode($cid, $code);
            if (!$rec || $rec->status !== 'valid') throw new \RuntimeException('الشهادة غير موجودة أو ملغاة.');
            $u = $this->user((int) $rec->reviewer_id);
            $to = $u ? (string) $u->getEmail() : '';
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('لا يوجد بريد صالح مسجل للمحكّم في OJS.');
            if ((int) $rec->email_attempts >= 10) throw new \RuntimeException('بلغت محاولات الإرسال الحد الأقصى (10).');
            if ($rec->last_email_attempt_at && strtotime($rec->last_email_attempt_at . ' UTC') > time() - 60) throw new \RuntimeException('انتظر دقيقة بين محاولتي إرسال للشهادة نفسها.');

            $journal = (string) $cfg['journal_name'];
            $view = $this->url($request, 'view', [$code]); $verify = $this->url($request, 'verify', [$code]);
            $body = '<div dir="rtl" style="font-family:Tahoma,Arial;font-size:15px;line-height:1.9">'
                . '<p>' . self::e(trim($rec->honorific . ' ' . $rec->reviewer_name)) . '،</p><p>السلام عليكم ورحمة الله وبركاته، وبعد:</p>'
                . '<p>فتشكر ' . self::e($journal) . ' لكم إسهامكم في تحكيم الأبحاث العلمية المقدَّمة إليها، ويسرّها أن تهديكم شهادة شكر وتقدير'
                . (CertificateRenderer::pdfAvailable() && $cfg['email_attach_pdf'] ? ' مرفقة بهذه الرسالة بصيغة PDF' : '') . '.</p>'
                . '<p>عرض الشهادة بعد تسجيل الدخول: <a href="' . self::e($view) . '">' . self::e($view) . '</a><br>'
                . 'رقم الشهادة: <span dir="ltr">' . self::e($code) . '</span><br>التحقق العام من صحتها: <a href="' . self::e($verify) . '">' . self::e($verify) . '</a></p>'
                . '<p>وتجدون شهاداتكم كلها في حسابكم بالمنصة: «شهاداتي وملفات التحكيم».</p><p>حفاظًا على سرية التحكيم لا تتضمن الرسالة عنوان البحث ولا بيانات المؤلف.</p>'
                . '<p>مع خالص الشكر والتقدير،<br>' . self::e($journal) . '</p></div>';
            try {
                $m = new Mailable();
                $m->to($to, $u->getFullName());
                $fromEmail = (string) ($context->getData('contactEmail') ?: Config::getVar('email', 'default_envelope_sender'));
                if ($fromEmail !== '') $m->from($fromEmail, (string) ($context->getData('contactName') ?: $journal));
                $m->subject('شهادة شكر وتقدير — ' . $journal);
                $m->body($body);
                if (CertificateRenderer::pdfAvailable() && $cfg['email_attach_pdf']) {
                    $pdf = (new CertificateRenderer(dirname(__DIR__)))->pdf([$this->certData($request, $rec)], $this->tempDir());
                    $m->attachData($pdf, 'certificate-' . substr($code, -8) . '.pdf', ['mime' => 'application/pdf']);
                }
                Mail::send($m);
                $this->repo->markEmailAttempt($cid, $code, $to, 'accepted');
                return 'قُبلت الرسالة لدى نظام البريد إلى العنوان المسجل ' . $to . ' (القبول لا يعني ضمان الوصول إلى صندوق المستلم).';
            } catch (\Throwable $e) {
                $this->repo->markEmailAttempt($cid, $code, $to, 'failed', $e->getMessage());
                error_log('[reviewerCertificate] mail: ' . $e->getMessage());
                throw new \RuntimeException('تعذّر الإرسال؛ راجع إعدادات البريد (SMTP) في config.inc.php. التفاصيل: ' . mb_substr($e->getMessage(), 0, 160));
            }
        });
    }

    // ------------------------------------------------------------------ العرض والطباعة
    private function canSee($request, object $rec): bool
    {
        $u = $request->getUser();
        return $u && ((int) $u->getId() === (int) $rec->reviewer_id || $this->plugin->isManager($request));
    }

    private function loadForOwner($request, array $args): object
    {
        $this->requireLogin($request);
        $this->repo->ensureSchema();
        $code = strtoupper((string) ($args[0] ?? ''));
        $rec = preg_match('/^[A-Z0-9-]{20,90}$/D', $code) ? $this->repo->byCode((int) $request->getContext()->getId(), $code) : null;
        if (!$rec) $this->deny('الشهادة غير موجودة.');
        if (!$this->canSee($request, $rec)) $this->deny('غير مصرح بعرض هذه الشهادة.');
        if ($rec->status !== 'valid' && !$this->plugin->isManager($request)) $this->deny('هذه الشهادة غير سارية.');
        return $rec;
    }

    private function noCache(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
    }

    public function view($args, $request)
    {
        $rec = $this->loadForOwner($request, (array) $args);
        $this->noCache(); header('Content-Type: text/html; charset=utf-8');
        $pdf = CertificateRenderer::pdfAvailable() ? $this->url($request, 'pdf', [$rec->code]) : '';
        $html = (new CertificateRenderer(dirname(__DIR__)))->html([$this->certData($request, $rec)], $pdf);
        if ($rec->status !== 'valid') $html = str_replace('<div class="wrap">', '<div style="background:#b02a2a;color:#fff;padding:10px;text-align:center;font:700 16px Tahoma">نسخة ملغاة — للأرشيف فقط</div><div class="wrap">', $html);
        echo $html;
        exit;
    }

    public function pdf($args, $request)
    {
        $rec = $this->loadForOwner($request, (array) $args);
        if ($rec->status !== 'valid') $this->deny('لا يُصدر PDF لشهادة ملغاة.');
        $this->sendPdf((new CertificateRenderer(dirname(__DIR__)))->pdf([$this->certData($request, $rec)], $this->tempDir()), 'certificate-' . substr($rec->code, -8) . '.pdf');
    }

    private function sendPdf(string $bytes, string $name): void
    {
        $this->noCache();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function printCerts($args, $request)
    {
        $this->managerGate($request);
        $cid = (int) $request->getContext()->getId();
        $certs = [];
        foreach (array_slice((array) $request->getUserVar('codes'), 0, 100) as $code) {
            $rec = $this->repo->byCode($cid, strtoupper((string) $code));
            if ($rec && $rec->status === 'valid') $certs[] = $this->certData($request, $rec);
        }
        if (!$certs) $this->deny('لم تحدد شهادات سارية. حدّد الشهادات من عمود «تحديد» أولًا.');
        $r = new CertificateRenderer(dirname(__DIR__));
        if ($request->getUserVar('format') === 'pdf' && CertificateRenderer::pdfAvailable()) {
            $this->sendPdf($r->pdf($certs, $this->tempDir()), 'certificates-' . date('Ymd') . '.pdf');
        }
        $this->noCache(); header('Content-Type: text/html; charset=utf-8');
        echo $r->html($certs);
        exit;
    }

    // ------------------------------------------------------------------ صفحة المحكّم
    public function mine($args, $request)
    {
        $this->requireLogin($request);
        $this->repo->ensureSchema();
        $cfg = $this->plugin->cfg($request);
        $user = $request->getUser(); $cid = (int) $request->getContext()->getId();
        $h = '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:18px">شهاداتي</h2>';
        $recs = $this->repo->byReviewer($cid, (int) $user->getId());
        if (!$recs) {
            $h .= '<p>لا توجد شهادات صادرة لكم بعد. تُصدر الشهادة بعد اكتمال التحكيم واعتمادها من إدارة المجلة.</p>';
        } else {
            $h .= '<table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">رقم الشهادة</th><th style="' . self::S_TH . '">التاريخ</th><th style="' . self::S_TH . '">الإجراءات</th></tr></thead><tbody>';
            foreach ($recs as $rec) {
                $h .= '<tr><td style="' . self::S_TD . '"><code dir="ltr" style="font-size:11px">' . self::e($rec->code) . '</code></td><td style="' . self::S_TD . '">' . self::e($rec->cert_date ?: substr((string) $rec->issued_at, 0, 10)) . '</td><td style="' . self::S_TD . '">'
                    . '<a target="_blank" rel="noopener" href="' . self::e($this->url($request, 'view', [$rec->code])) . '" style="' . self::S_BTNP . '">عرض الشهادة</a>'
                    . (CertificateRenderer::pdfAvailable() ? '<a href="' . self::e($this->url($request, 'pdf', [$rec->code])) . '" style="' . self::S_BTN . '">تنزيل PDF</a>' : '')
                    . '<a target="_blank" rel="noopener" href="' . self::e($this->url($request, 'verify', [$rec->code])) . '" style="' . self::S_BTN . '">رابط التحقق</a></td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '</div>';
        if (!empty($cfg['reviewer_can_see_kit'])) {
            $h .= '<div style="' . self::S_BOX . '"><h2 style="margin:0 0 8px;font-size:18px">نماذج التحكيم لمهامي</h2>'
                . '<p style="font-size:13px;color:#444">نموذجا «نتيجة التحكيم» و«تقويم بحث علمي» تفاعليان: تُجمع الدرجات تلقائيًا، وتُحفظ مدخلاتكم في متصفحكم، ثم تُطبع أو تُحفظ PDF وتُرفع مع التقرير في مهمة التحكيم.</p>';
            $rows = '';
            foreach ($this->reviews($cid, null, (int) $user->getId(), 100) as $r) {
                if ((int) $r->declined) continue;
                $rows .= '<tr><td style="' . self::S_TD . '">' . self::e(mb_substr($this->title((int) $r->submission_id), 0, 110)) . '</td><td style="' . self::S_TD . '">'
                    . (self::completed($r) ? 'مكتمل' : 'قيد التحكيم' . ($r->date_due ? ' — الموعد ' . self::e(substr((string) $r->date_due, 0, 10)) : '')) . '</td><td style="' . self::S_TD . '">'
                    . '<a target="_blank" rel="noopener" href="' . self::e($this->url($request, 'doc', [], ['type' => 'forms', 'rid' => (int) $r->review_id])) . '" style="' . self::S_BTN . '">فتح النماذج</a></td></tr>';
            }
            $h .= $rows !== '' ? '<table style="' . self::S_TBL . '"><thead><tr><th style="' . self::S_TH . '">البحث</th><th style="' . self::S_TH . '">الحالة</th><th style="' . self::S_TH . '"></th></tr></thead><tbody>' . $rows . '</tbody></table>'
                : '<p>لا توجد مهام تحكيم مسندة إليكم في هذه المجلة.</p>';
            $h .= '</div>';
        }
        $this->backend($request, 'شهاداتي وملفات التحكيم', $h);
    }

    // ------------------------------------------------------------------ ملفات التحكيم
    public function doc($args, $request)
    {
        $this->requireLogin($request);
        $cfg = $this->plugin->cfg($request); $cid = (int) $request->getContext()->getId();
        $type = (string) $request->getUserVar('type');
        $r = $this->review($cid, (int) $request->getUserVar('rid'));
        if (!$r || !in_array($type, ['invite', 'forms', 'revision', 'matrix'], true)) $this->deny('طلب غير صالح.');
        $isMgr = $this->plugin->isManager($request);
        $isOwner = (int) $request->getUser()->getId() === (int) $r->reviewer_id;
        if (!$isMgr && !($type === 'forms' && $isOwner && !empty($cfg['reviewer_can_see_kit']))) $this->deny();

        $kit = new ReviewKitRenderer(dirname(__DIR__), $cfg);
        $title = $this->title((int) $r->submission_id);
        $date = \DateTimeImmutable::createFromFormat('!d-m-Y', trim((string) $request->getUserVar('date'))) ?: new \DateTimeImmutable('today');
        $u = $this->user((int) $r->reviewer_id);
        $rname = $u ? $u->getFullName(true, false, 'ar') : '';
        $hk = (string) ($request->getUserVar('h') ?: ($u ? $this->guessHonorific($u, $cfg) : 'none'));
        $hon = (string) ($cfg['honorifics'][$hk] ?? '');
        $this->noCache(); header('Content-Type: text/html; charset=utf-8');

        if ($type === 'invite') {
            $days = (int) $cfg['default_review_days'];
            if ($r->date_due && $r->date_assigned) $days = max(1, (int) round((strtotime((string) $r->date_due) - strtotime((string) $r->date_assigned)) / 86400));
            echo $kit->invitation(['ref' => trim((string) $request->getUserVar('ref')) ?: ('R' . (int) $r->review_id), 'date' => $date, 'honorificKey' => $hk,
                'honorific' => $hon, 'reviewer' => $rname, 'title' => $title, 'days' => $days]);
        } elseif ($type === 'forms') {
            echo $kit->forms(['reviewer' => trim($hon . ' ' . $rname), 'title' => $title, 'docId' => 'forms-' . (int) $r->review_id]);
        } elseif ($type === 'revision') {
            $pub = Repo::submission()->get((int) $r->submission_id)?->getCurrentPublication();
            $a = $pub?->getPrimaryAuthor() ?? $pub?->getData('authors')?->first();
            $ah = (string) ($request->getUserVar('h') ?: 'dr_m');   // اللقب المختار يُطبَّق على الباحث المخاطَب
            echo $kit->revisionLetter(['date' => $date, 'honorificKey' => $ah, 'honorific' => (string) ($cfg['honorifics'][$ah] ?? ''),
                'author' => $a ? $a->getFullName(true, false, 'ar') : '', 'title' => $title]);
        } else {
            $n = DB::table('review_assignments')->where('submission_id', $r->submission_id)->where('cancelled', 0)->where('declined', 0)
                ->where('round', $r->round)->count();
            echo $kit->matrix(['title' => $title, 'date' => $date, 'docId' => 'matrix-' . (int) $r->submission_id . '-' . (int) $r->round,
                'reviewers' => array_fill(0, max(2, min(4, $n)), [])]);
        }
        exit;
    }

    // ------------------------------------------------------------------ التحقق العام
    public function verify($args, $request)
    {
        $this->repo->ensureSchema();
        $context = $request->getContext();
        $cfg = $this->plugin->cfg($request);
        $code = strtoupper(trim((string) (($args[0] ?? '') ?: $request->getUserVar('code'))));
        $rec = ($code !== '' && preg_match('/^[A-Z0-9-]{20,90}$/D', $code)) ? $this->repo->byCode((int) $context->getId(), $code) : null;
        $this->noCache(); header('Content-Type: text/html; charset=utf-8'); header('Referrer-Policy: no-referrer');
        if ($code !== '' && (!$rec || $rec->status !== 'valid')) http_response_code(404);
        $journal = (string) $cfg['journal_name'];
        $logo = 'data:image/png;base64,' . base64_encode((string) file_get_contents(dirname(__DIR__) . '/assets/logo_tadabbur.png'));
        if ($code === '') {
            $state = '<p>أدخل رقم الشهادة المطبوع أسفل رمز QR:</p>';
        } elseif ($rec && $rec->status === 'valid') {
            $state = '<div class="st ok">✔ شهادة أصيلة وسارية</div><table><tr><th>الجهة المصدرة</th><td>' . self::e($journal) . '</td></tr>'
                . '<tr><th>نوع الشهادة</th><td>شهادة شكر وتقدير لمحكّم</td></tr><tr><th>رقم الشهادة</th><td dir="ltr"><code>' . self::e($rec->code) . '</code></td></tr>'
                . '<tr><th>تاريخ الشهادة</th><td>' . self::e($rec->cert_date ?: substr((string) $rec->issued_at, 0, 10)) . '</td></tr></table>';
        } elseif ($rec) {
            $state = '<div class="st bad">✖ هذه الشهادة ملغاة وغير سارية</div>'
                . ($rec->replaced_by ? '<p>صدرت بدلًا منها شهادة مصحّحة؛ يُرجى طلبها من صاحبها.</p>' : '');
        } else {
            $state = '<div class="st bad">✖ لا توجد شهادة بهذا الرقم في سجل المجلة</div>';
        }
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>التحقق من شهادة محكّم — ' . self::e($journal) . '</title><style>'
            . 'body{margin:0;background:#f3f1ec;font-family:Tahoma,Arial,sans-serif;color:#22303f;line-height:1.8}main{max-width:640px;margin:6vh auto;background:#fff;border-radius:14px;box-shadow:0 2px 14px #0001;overflow:hidden}'
            . 'header{background:#43260f;color:#fff;padding:18px 22px;display:flex;gap:14px;align-items:center;border-bottom:4px solid #c5a158}header img{height:52px;background:#fff;border-radius:8px;padding:4px}header h1{margin:0;font-size:20px}'
            . '.in{padding:22px}.st{font-size:20px;font-weight:700;padding:12px 14px;border-radius:8px;margin-bottom:14px}.ok{background:#e8f5ee;color:#14623e}.bad{background:#fbeaea;color:#9b2929}'
            . 'table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #eee;padding:8px;text-align:right}th{width:38%;color:#666;font-weight:400}code{word-break:break-all}'
            . 'form{display:flex;gap:8px;margin-top:16px}input{flex:1;padding:9px;border:1px solid #bbb;border-radius:6px;font:14px monospace;direction:ltr}button{background:#43260f;color:#fff;border:0;border-radius:6px;padding:9px 16px;font:700 14px Tahoma;cursor:pointer}'
            . 'small{color:#777;display:block;margin-top:16px}</style></head><body><main><header><img src="' . $logo . '" alt=""><h1>التحقق من شهادة محكّم<br><span style="font-size:14px;font-weight:400">' . self::e($journal) . '</span></h1></header>'
            . '<div class="in">' . $state . '<form method="get" action="' . self::e($this->url($request, 'verify')) . '"><input name="code" placeholder="TADABBUR-2026-..." value="' . self::e($code) . '" required><button type="submit">تحقق</button></form>'
            . '<small>لصون سرية التحكيم لا تعرض هذه الصفحة عنوان البحث ولا اسم المحكّم ولا تقرير التحكيم.</small></div></main></body></html>';
        exit;
    }
}
