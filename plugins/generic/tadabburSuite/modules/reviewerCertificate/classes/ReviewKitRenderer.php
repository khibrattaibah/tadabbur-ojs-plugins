<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/reviewerCertificate/classes/ReviewKitRenderer.php
 * يولّد ملفات التحكيم (HTML مكتفٍ ذاتيًا، تفاعلي وقابل للطباعة/الحفظ PDF):
 *  01 خطاب دعوة التحكيم — 02 خطاب طلب التعديل — 03 نموذج نتيجة التحكيم — 04 نموذج التقويم — 05 مصفوفة استجابة الباحث.
 * لا يعتمد على OJS، فيُختبر مستقلًا.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes;

class ReviewKitRenderer
{
    private string $dir;
    private array $cfg;
    public static string $calendar = 'islamic';
    public function __construct(string $pluginDir, array $cfg) { $this->dir = rtrim($pluginDir, '/'); $this->cfg = $cfg; self::$calendar = (string) ($cfg['hijri_calendar'] ?? 'islamic'); }

    // ---------- أدوات ----------
    /** صورة الهوية: المرفوعة من لوحة الحزمة إن وُجدت، وإلا الافتراضية المرفقة */
    private function ap(string $file): string
    {
        $d = $this->dir . '/assets/' . $file;
        return class_exists(\APP\plugins\generic\tadabburSuite\classes\Branding::class) ? \APP\plugins\generic\tadabburSuite\classes\Branding::path($file, $d) : $d;
    }

    private function img(string $file): string
    {
        static $c = [];
        if (!isset($c[$file])) {
            $mime = str_ends_with($file, '.jpg') ? 'image/jpeg' : (str_ends_with($file, '.ttf') ? 'font/ttf' : 'image/png');
            $c[$file] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($this->ap($file)));
        }
        return $c[$file];
    }
    public static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** التاريخ الهجري (أم القرى) بصيغة 1-3-1448 */
    public static function hijri(\DateTimeInterface $d): string
    {
        if (class_exists('IntlDateFormatter')) {
            $f = new \IntlDateFormatter('en_US@calendar=' . self::$calendar, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $d->getTimezone()->getName(), \IntlDateFormatter::TRADITIONAL, 'd-M-y');
            $r = $f->format($d);
            if ($r) return $r;
        }
        // احتياطي: خوارزمية الجدول (قد تختلف يومًا عن أم القرى)
        $Y = (int) $d->format('Y'); $M = (int) $d->format('n'); $D = (int) $d->format('j');
        $a = intdiv(14 - $M, 12); $yy = $Y + 4800 - $a; $mm = $M + 12 * $a - 3;
        $jd = $D + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
        $l = $jd - 1948440 + 10632; $n = intdiv($l - 1, 10631); $l = $l - 10631 * $n + 354;
        $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
        $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
        $m = intdiv(24 * $l, 709); $day = $l - intdiv(709 * $m, 24); $y = 30 * $n + $j - 30;
        return $day . '-' . $m . '-' . $y;
    }
    public static function dateLine(\DateTimeInterface $d): string { return "\u{200E}" . self::hijri($d) . "\u{200E}هـ / \u{200E}" . $d->format('d-m-Y') . "\u{200E}م"; }

    public static function daysWords(int $n): string
    {
        $w = [1 => 'يوم واحد', 2 => 'يومان', 3 => 'ثلاثة أيام', 4 => 'أربعة أيام', 5 => 'خمسة أيام', 6 => 'ستة أيام', 7 => 'سبعة أيام', 8 => 'ثمانية أيام', 9 => 'تسعة أيام',
              10 => 'عشرة أيام', 12 => 'اثنا عشر يومًا', 14 => 'أربعة عشر يومًا', 15 => 'خمسة عشر يومًا', 20 => 'عشرون يومًا', 21 => 'واحد وعشرون يومًا', 30 => 'ثلاثون يومًا'];
        return $w[$n] ?? ($n . ' يومًا');
    }
    private function isFem(string $key): bool { return in_array($key, $this->cfg['feminine_honorifics'] ?? [], true); }

    // ---------- هيكل الصفحة ----------
    private function shell(string $title, string $body, string $docId, string $toolbar = ''): string
    {
        $fr = $this->img('IBMPlexSansArabic-Regular.ttf'); $fb = $this->img('IBMPlexSansArabic-Bold.ttf');
        $brown = '#43260f'; $gold = '#c5a158';
        $css = '@font-face{font-family:KitFont;font-weight:400;src:url(' . $fr . ') format("truetype")}@font-face{font-family:KitFont;font-weight:700;src:url(' . $fb . ') format("truetype")}'
            . '@page{size:A4;margin:0}*{box-sizing:border-box}html,body{margin:0;background:#e9e9ee;font-family:KitFont,Tahoma,sans-serif;color:#1c1c1c;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.tb{position:sticky;top:0;z-index:20;background:#1f2a44;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:14px}'
            . '.tb button{background:#c5a158;border:0;color:#1f2a44;font:700 14px KitFont,sans-serif;padding:8px 16px;border-radius:6px;cursor:pointer}.tb .sp{flex:1}'
            . '.pg{position:relative;width:794px;min-height:1123px;margin:14px auto;background:#fff;box-shadow:0 2px 10px #0003;display:flex;flex-direction:column;direction:rtl;page-break-after:always;break-after:page;overflow:hidden}'
            . '.pg:last-child{page-break-after:auto;break-after:auto}'
            . '.lh{background:#fff url(' . $this->img('letterhead.jpg') . ') center/100% 100% no-repeat;padding:150px 64px 130px}'
            . '.hd{display:flex;align-items:center;justify-content:space-between;padding:12px 40px 6px}.hd img{height:54px}.hd .c{text-align:center;line-height:1.5}.hd .c b{font-size:19px}.hd .c i{display:block;font-style:normal;color:' . $gold . ';font-size:12.5px}'
            . '.band{margin:6px 40px 0;background:' . $brown . ';color:#fff;padding:14px 24px 10px;border-bottom:4px solid ' . $gold . '}.band h1{margin:0;font-size:30px}.band p{margin:6px 0 0;color:' . $gold . ';font-size:14px}'
            . '.meta{margin:0 40px;display:flex;border:1px solid #ddd;border-top:0;font-size:12.5px}.meta div{flex:1;padding:6px 12px;text-align:center}.meta small{display:block;color:#777;font-size:10.5px}'
            . '.ct{padding:18px 40px;flex:1;font-size:14px;line-height:1.8}.ft{display:flex;align-items:center;justify-content:space-between;padding:10px 40px 18px;font-size:11px;color:#444;border-top:1px solid #ddd;margin:0 40px}.ft img.q{height:56px}.ft img.b{height:34px}.ft .m{text-align:center;line-height:1.6;flex:1;padding:0 10px}'
            . 'table.t{width:100%;border-collapse:collapse;margin:8px 0}table.t th{background:' . $brown . ';color:#fff;padding:7px 8px;font-size:13px;border:1px solid ' . $brown . '}table.t td{border:1px solid #cfc7bd;padding:6px 8px;vertical-align:middle;font-size:13px}'
            . 'table.k td:first-child{background:#f3efe9;width:22%;font-weight:700}input[type=text],input[type=number],textarea{width:100%;border:0;background:transparent;font:inherit;color:#1a3d8f;outline:0;resize:none}'
            . 'input[type=number]{text-align:center;width:70px}textarea{overflow:hidden;min-height:24px}td:focus-within{background:#fffbe9}'
            . '.note{background:#f6f1e8;border-right:3px solid ' . $gold . ';padding:6px 12px;font-size:12.5px;margin:8px 0}.chk{width:22px;height:22px;accent-color:' . $brown . '}'
            . '#tn td,#te td,[id^=tr] td{padding:2px 6px}#tn input,#te input{height:21px}[id^=tr] textarea{min-height:18px;padding:0}[id^=tr] input{height:18px}.ct{padding:8px 40px}h2{font-size:18px;margin:14px 0 4px}a{color:#1a4fa8}.btnrow button{margin:4px}'
            . '@media print{html,body{background:#fff}.tb{display:none}.pg{margin:0;box-shadow:none}input,textarea{color:#000}.noprint{display:none}}';
        $js = '(function(){var K="kit:' . $docId . '";function save(){try{var o={};document.querySelectorAll("[data-f]").forEach(function(e){o[e.dataset.f]=e.type=="checkbox"||e.type=="radio"?e.checked:e.value});localStorage.setItem(K,JSON.stringify(o))}catch(x){}}'
            . 'function load(){try{var o=JSON.parse(localStorage.getItem(K)||"{}");document.querySelectorAll("[data-f]").forEach(function(e){if(!(e.dataset.f in o))return;if(e.type=="checkbox"||e.type=="radio")e.checked=o[e.dataset.f];else e.value=o[e.dataset.f]})}catch(x){}}'
            . 'function grow(t){t.style.height="auto";t.style.height=t.scrollHeight+"px"}'
            . 'function total(){var s=0,m=0;document.querySelectorAll(".sc").forEach(function(i){var mx=+i.max,v=parseFloat(i.value);if(v>mx){v=mx;i.value=mx}if(v<0){v=0;i.value=0}if(!isNaN(v))s+=v});document.querySelectorAll(".tot").forEach(function(t){t.textContent=s})}'
            . 'window.addRows=function(id,n){var tb=document.getElementById(id);var tr=tb.querySelector("tr:last-child");for(var i=0;i<(n||1);i++){var c=tr.cloneNode(true);c.querySelectorAll("input,textarea").forEach(function(e){e.value="";e.dataset.f=e.dataset.f.replace(/\\d+$/,"")+(tb.children.length+1)});c.firstElementChild.textContent=tb.children.length+1;tb.appendChild(c)}};'
            . 'window.bake=function(){document.querySelectorAll("input,textarea").forEach(function(e){if(e.type=="checkbox"||e.type=="radio"){e.checked?e.setAttribute("checked",""):e.removeAttribute("checked")}else if(e.tagName=="TEXTAREA"){e.textContent=e.value}else e.setAttribute("value",e.value)});var h="<!DOCTYPE html>"+document.documentElement.outerHTML;var a=document.createElement("a");a.href=URL.createObjectURL(new Blob([h],{type:"text/html"}));a.download="' . $docId . '.html";a.click()};'
            . 'document.addEventListener("DOMContentLoaded",function(){load();document.querySelectorAll("textarea").forEach(function(t){grow(t);t.addEventListener("input",function(){grow(t)})});document.querySelectorAll("input[name=rec]").forEach(function(r){r.addEventListener("change",function(){document.querySelectorAll("input[name=rec]").forEach(function(o){if(o!==r)o.checked=false})})});total();document.addEventListener("input",function(){total();save()});document.addEventListener("change",save)});})();';
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($title) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="tb"><span>' . self::e($title) . '</span><span class="sp"></span>' . $toolbar . '<button onclick="window.print()">طباعة / حفظ PDF</button><button onclick="bake()">حفظ نسخة قابلة للتعديل</button></div>'
            . $body . '<script>' . $js . '</script></body></html>';
    }

    private function head(string $sub): string
    {
        return '<div class="hd"><img src="' . $this->img('logo_kt.png') . '" alt=""><div class="c"><b>' . self::e($this->cfg['journal_name']) . '</b><i>' . self::e($sub) . '</i></div><img src="' . $this->img('logo_tadabbur.png') . '" alt=""></div>';
    }
    private function band(string $t, string $s, string $code, string $r3label, string $r3): string
    {
        return '<div class="band"><h1>' . self::e($t) . '</h1><p>' . self::e($s) . '</p></div><div class="meta"><div><small>رمز الوثيقة</small><b dir="ltr">' . self::e($code) . '</b></div>'
            . '<div><small>الإصدار</small><b>' . self::e($this->cfg['form_version']) . '</b></div><div><small>' . self::e($r3label) . '</small><b>' . self::e($r3) . '</b></div></div>';
    }
    private function foot(string $bc, int $p, int $n): string
    {
        $c = $this->cfg;
        return '<div class="ft"><img class="q" src="' . $this->img('qr_policy.png') . '" alt=""><div class="m">صفحة <b>' . $p . '</b> من ' . $n . '<br>' . self::e($c['journal_name']) . ' — ' . self::e($c['publisher_name'])
            . ' — ISSN ' . self::e($c['issn_online']) . ' (Online) | ' . self::e($c['issn_print']) . ' (Print) — الإصدار ' . self::e($c['form_version']) . '</div>'
            . '<div style="text-align:center"><img class="b" src="' . $this->img('bc_' . $bc . '.png') . '" alt=""><div dir="ltr" style="font-weight:700">' . self::e($c['codes'][$bc]) . '</div></div></div>';
    }
    private function titleRows(string $title, string $who = ''): string
    {
        return '<table class="t k"><tr><td>عنوان البحث</td><td>' . self::e($title) . '</td></tr>' . ($who !== '' ? '<tr><td>اسم المحكّم</td><td>' . self::e($who) . '</td></tr>' : '') . '</table>';
    }

    // ---------- 01 خطاب الدعوة ----------
    /** @param array{ref:string,date:\DateTimeInterface,honorificKey:string,honorific:string,reviewer:string,title:string,days:int} $d */
    public function invitation(array $d): string
    {
        $c = $this->cfg; $fem = $this->isFem($d['honorificKey']);
        $sal = trim(($d['honorific'] !== '' ? $d['honorific'] . '/ ' : '') . $d['reviewer']);
        $body = '<div class="pg lh"><div style="position:absolute;left:50px;top:60px;width:136px;font-size:9px;font-weight:700;white-space:nowrap;direction:rtl;text-align:center">' . self::e($d['ref']) . '</div><div style="position:absolute;left:50px;top:88px;width:136px;font-size:9px;font-weight:700;white-space:nowrap;direction:rtl;text-align:center">' . self::e(self::dateLine($d['date'])) . '</div><div style="position:absolute;left:50px;top:112px;width:136px;font-size:8px;font-weight:700;line-height:1.25;white-space:normal;direction:rtl;text-align:center">' . self::e($c['invitation_attachments']) . '</div>'

            . '<h2 style="text-align:center;text-decoration:underline;font-size:22px;margin:30px 0 18px">طلب تحكيم بحث علمي</h2>'
            . '<p style="font-weight:700;font-size:16.5px;margin:0">سعادة ' . self::e($sal) . ' ' . ($fem ? 'سلمها الله' : 'سلمه الله') . '</p>'
            . '<p style="font-weight:700;margin:6px 0">السلام عليكم ورحمة الله وبركاته .... وبعد:</p><p style="font-weight:700;margin:6px 0">فأسأل الله تعالى لكم مزيد العون والتوفيق والسداد</p>'
            . '<p style="text-align:justify;font-size:15px;line-height:2.0">ويسرنا في «' . self::e($c['journal_name']) . '» التي تصدر عن ' . self::e($c['publisher_name']) . ' بـ' . self::e($c['publisher_city']) . '؛ أن نرفق لفضيلتكم بحثًا بعنوان: (' . self::e($d['title'])
            . ') آملين التكرم بتحكيمه وتزويدنا برأيكم حول مدى صلاحيته للنشر في المجلة، ومدى التزام الباحث بقواعد البحث العلمي، كما نأمل من فضيلتكم أن يصلنا تقريركم وفق متطلبات المجلة المرفقة مع البحث، وملحوظاتكم المدونة عن البحث، في مدة أقصاها: ('
            . self::e(self::daysWords((int) $d['days'])) . ') من تاريخ وصول هذا الخطاب لكم، وإرسالها على حسابكم في منصة المجلة الإلكترونية:</p>'
            . '<p style="text-align:center;direction:ltr"><a href="' . self::e($c['platform_url']) . '">' . self::e($c['platform_url']) . '</a></p>'
            . '<p style="font-size:15px">كما نأمل من فضيلتكم الاطلاع على سياسة المجلة في عملية تحكيم الأبحاث:</p><p style="text-align:center;direction:ltr"><a href="' . self::e($c['policy_url']) . '">' . self::e($c['policy_url']) . '</a></p>'
            . '<p style="font-size:15px">شاكرين لكم تعاونكم معنا، وكتب الله أجركم، وجعل ذلك في موازين حسناتكم.</p><p style="font-weight:700;margin:4px 0">وتفضلوا بقبول وافر التقدير والاحترام.</p><p style="font-weight:700;margin:4px 0">والسلام عليكم ورحمة الله وبركاته.</p>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:34px"><div style="text-align:center;position:relative;width:300px;height:150px"><img src="' . $this->img('rais.png') . '" style="position:absolute;right:90px;top:0;height:42px" alt="">'
            . '<img src="' . $this->img('sig_blue.png') . '" style="position:absolute;right:60px;top:20px;height:70px;mix-blend-mode:multiply" alt=""><img src="' . $this->img('name_editor.png') . '" style="position:absolute;right:0;top:92px;width:290px" alt=""></div>'
            . '<div style="text-align:center"><img src="' . $this->img('qr_policy.png') . '" style="height:92px" alt=""><div dir="ltr" style="font-size:10px;font-weight:700">' . self::e($c['codes']['inv']) . '</div></div></div></div>';
        return $this->shell('خطاب دعوة تحكيم — ' . $d['reviewer'], $body, 'inv-' . $d['ref']);
    }

    // ---------- 02 خطاب طلب التعديل ----------
    /** @param array{date:\DateTimeInterface,honorificKey:string,honorific:string,author:string,title:string} $d */
    public function revisionLetter(array $d): string
    {
        $c = $this->cfg; $fem = $this->isFem($d['honorificKey']);
        $sal = trim(($d['honorific'] !== '' ? $d['honorific'] . '/ ' : '') . $d['author']);
        $body = '<div class="pg lh"><div style="position:absolute;left:50px;top:88px;width:136px;font-size:9px;font-weight:700;white-space:nowrap;direction:rtl;text-align:center">' . self::e(self::dateLine($d['date'])) . '</div><div style="position:absolute;left:50px;top:112px;width:136px;font-size:8px;font-weight:700;line-height:1.25;white-space:normal;direction:rtl;text-align:center">' . self::e($c['revision_attachments']) . '</div>'
            . '<h2 style="text-align:center;text-decoration:underline;font-size:22px;margin:30px 0 18px">طلب تعديل بحث علمي محكَّم</h2>'
            . '<p style="font-weight:700;font-size:16.5px;margin:0">سعادة الباحث ' . self::e($sal) . ' ' . ($fem ? 'سلمها الله' : 'سلمه الله') . '</p><p style="font-weight:700;margin:6px 0">السلام عليكم ورحمة الله وبركاته وبعد:</p>'
            . '<p style="text-align:justify;font-size:15px;line-height:2.0">فأسأل الله لكم دوام التوفيق والسداد، وانطلاقًا من حرص هيئة التحرير بـ' . self::e($c['journal_name']) . ' على تجويد البحث؛ نرفق لفضيلتكم ملحوظات المحكّمين حول بحثكم المعنون بـ ('
            . self::e($d['title']) . ') إذ أوصت هيئة التحرير بأن تقوموا بالتعديلات الموضحة من قبل المحكّمين، بالإضافة إلى كتابة تقرير يُرفق مع البحث يوضح خطوات تصحيح ملحوظات المحكّمين، بحيث تُكتب عبارة المحكّم بنصها ثم تُردف بما تم حيالها، ونفيدكم بأن البحث سيُدفع لجولة تحكيم أخرى بعد قيامكم بالتعديلات المطلوبة — بمشيئة الله تعالى —.</p>'
            . '<p style="font-size:15px">هذا وتفضلوا بقبول وافر تحياتي.</p><p style="font-weight:700">والسلام عليكم ورحمة الله وبركاته.</p>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:34px"><div style="text-align:center;position:relative;width:300px;height:150px"><img src="' . $this->img('rais.png') . '" style="position:absolute;right:90px;top:0;height:42px" alt="">'
            . '<img src="' . $this->img('sig_blue.png') . '" style="position:absolute;right:60px;top:20px;height:70px;mix-blend-mode:multiply" alt=""><img src="' . $this->img('name_editor.png') . '" style="position:absolute;right:0;top:92px;width:290px" alt=""></div>'
            . '<div style="text-align:center"><img src="' . $this->img('qr_policy.png') . '" style="height:92px" alt=""><div dir="ltr" style="font-size:10px;font-weight:700">' . self::e($c['codes']['rev']) . '</div></div></div></div>';
        return $this->shell('خطاب طلب تعديل — ' . $d['author'], $body, 'rev-' . md5($d['title'] . $d['author']));
    }

    // ---------- 03 + 04: النماذج التفاعلية ----------
    /** @param array{reviewer:string,title:string,docId:string} $d */
    public function forms(array $d): string
    {
        $c = $this->cfg; $n = count($c['review_scale']); $sum = array_sum(array_column($c['review_scale'], 1));
        // 03
        $rows = '';
        foreach ($c['recommendations'] as $i => $r) {
            $rows .= '<tr><td style="text-align:center"><input type="checkbox" name="rec" class="chk" data-f="rec' . $i . '"></td><td><b>' . self::e($r[0]) . '</b></td><td>' . self::e($r[1]) . '</td><td dir="ltr" style="text-align:center;font-size:12px">' . self::e($r[2]) . '</td></tr>';
        }
        $p1 = '<div class="pg">' . $this->head('نموذج المحكّم — مدى صلاحية البحث للنشر') . $this->band('نموذج نتيجة التحكيم', 'مدى صلاحية البحث للنشر', $c['codes']['res'], 'نظام الإدارة', 'OJS')
            . '<div class="ct">' . $this->titleRows($d['title'], $d['reviewer']) . '<h2>خلاصة رأي المحكّم</h2><p style="font-size:12.5px;color:#555">الرجاء وضع علامة (√) أو أي علامة أخرى أمام العبارة التي ترونها مناسبة، بناءً على الملحوظات التفصيلية والدرجة الإجمالية المستحقة للبحث.</p>'
            . '<table class="t"><tr><th style="width:9%">اختيار</th><th style="width:30%">التوصية</th><th>المعنى</th><th style="width:16%">OJS</th></tr>' . $rows . '</table>'
            . '<div class="note">الدرجة الإجمالية في نموذج التقويم: <b class="tot">0</b> / ' . $sum . ' — نأمل من فضيلتكم أن تتناسب النتيجة النهائية مع الدرجات في نموذج التقويم، وكذلك الملاحظات العلمية والفنية على البحث.</div>'
            . '<table class="t k" style="margin-top:14px"><tr><td>اسم المحكّم</td><td>' . self::e($d['reviewer']) . '</td></tr><tr><td>التوقيع</td><td style="height:44px"><input type="text" data-f="sig"></td></tr><tr><td>التاريخ</td><td><input type="text" data-f="date" placeholder="يوم-شهر-سنة"></td></tr></table>'
            . '<div class="note">يُرفق مع هذا النموذج التقرير المفصّل عن البحث، ويُرفع على حسابكم في منصة المجلة (نظام المجلات المفتوحة)، مع نسخة أخرى — إذا أمكن — على بريد المجلة الإلكتروني. ونأمل تسجيل حساب لكم في موقع المجلة عبر الرابط: <a href="' . self::e($c['platform_url']) . '">' . self::e($c['platform_url']) . '</a><br>شاكرين لكم حسن تعاونكم معنا.</div></div>'
            . $this->foot('res', 1, 1) . '</div>';
        // 04 صفحة 1
        $crit = '';
        foreach ($c['review_scale'] as $i => $it) {
            $crit .= '<tr><td style="text-align:center;width:6%">' . ($i + 1) . '</td><td>' . self::e($it[0]) . '</td><td style="text-align:center;width:13%">' . $it[1] . '</td><td style="text-align:center;width:15%"><input type="number" class="sc" min="0" max="' . $it[1] . '" step="0.5" data-f="sc' . $i . '"></td></tr>';
        }
        $notes = '';
        for ($i = 1; $i <= 15; $i++) $notes .= '<tr><td style="text-align:center;width:6%">' . $i . '</td><td style="width:9%"><input type="text" data-f="np' . $i . '"></td><td style="width:8%"><input type="text" data-f="nl' . $i . '"></td><td style="width:14%"><input type="text" data-f="nw' . $i . '"></td><td><textarea data-f="nn' . $i . '" rows="1"></textarea></td></tr>';
        $errs = '';
        for ($i = 1; $i <= 20; $i++) $errs .= '<tr><td style="text-align:center;width:6%">' . $i . '</td><td style="width:10%"><input type="text" data-f="ep' . $i . '"></td><td style="width:9%"><input type="text" data-f="el' . $i . '"></td><td style="width:37%"><input type="text" data-f="ew' . $i . '"></td><td><input type="text" data-f="er' . $i . '"></td></tr>';
        $e1 = '<div class="pg">' . $this->head('نموذج المحكّم — تقويم بحث علمي بعشرة معايير') . $this->band('تقويم بحث علمي', 'ملحوظة: نرجو عدم ذكر اسم سعادتكم في هذا النموذج', $c['codes']['eval'], 'المعايير', $n . ' عناصر / ' . $sum . ' درجة')
            . '<div class="ct">' . $this->titleRows($d['title']) . '<table class="t"><tr><th>م</th><th>عناصر التحكيم</th><th>الدرجة الكلية</th><th>الدرجة المستحقة</th></tr>' . $crit
            . '<tr><td colspan="2" style="font-weight:700;background:#f3efe9">إجمالي الدرجة المستحقة للبحث</td><td style="text-align:center;font-weight:700;background:#f3efe9">' . $sum . '</td><td style="text-align:center;font-weight:700;background:#f3efe9" class="tot">0</td></tr></table>'
            . '<div class="note">فضلًا نأمل من فضيلتكم أن تتوافق الدرجات في الجدول مع النتيجة النهائية في نموذج نتيجة تقويم البحث.</div>'
            . '<p>نأمل التكرم بالاطلاع على سياسة التحكيم في المجلة عن طريق الرابط: <a href="' . self::e($c['policy_url']) . '">' . self::e($c['policy_url']) . '</a></p></div>' . $this->foot('eval', 1, 3) . '</div>';
        $e2 = '<div class="pg">' . $this->head('نموذج المحكّم — الملحوظات التفصيلية') . '<div class="ct"><h2>الملحوظات التفصيلية العلمية والفنية</h2><p style="font-size:12.5px;color:#555">(ح = حاشية) (س = من أسفل الصفحة) (ف = الفقرة)</p>'
            . '<table class="t"><thead><tr><th>م</th><th>الصفحة</th><th>السطر</th><th>الكلمة</th><th>الملاحظة / التصحيح</th></tr></thead><tbody id="tn">' . $notes . '</tbody></table><div class="noprint btnrow"><button onclick="addRows(\'tn\',5)">+ خمسة أسطر</button></div></div>' . $this->foot('eval', 2, 3) . '</div>';
        $e3 = '<div class="pg">' . $this->head('نموذج المحكّم — الأخطاء اللغوية والإملائية') . '<div class="ct"><h2>هل ورد في هذا البحث عدد من الأخطاء الإملائية والنحوية؟</h2>'
            . '<p><label><input type="checkbox" class="chk" data-f="errYes"> نعم</label> &nbsp; <label><input type="checkbox" class="chk" data-f="errNo"> لا</label> — في حال الإجابة بنعم، نأمل توضيحها في هذا الجدول:</p>'
            . '<table class="t"><thead><tr><th>م</th><th>الصفحة</th><th>السطر</th><th>الخطأ</th><th>الصواب</th></tr></thead><tbody id="te">' . $errs . '</tbody></table><div class="noprint btnrow"><button onclick="addRows(\'te\',5)">+ خمسة أسطر</button></div>'
            . '<div class="note">الملحوظات التفصيلية: الأمر متروك لفضيلتكم إما تدوينها على نسخة Word أو PDF من البحث، أو تسجيلها هنا. ويُرفع هذا النموذج مع نموذج نتيجة التحكيم على حسابكم في منصة المجلة (نظام المجلات المفتوحة)، مع نسخة أخرى — إذا أمكن — على بريد المجلة الإلكتروني.</div></div>' . $this->foot('eval', 3, 3) . '</div>';
        return $this->shell('نماذج التحكيم — ' . $d['title'], $p1 . $e1 . $e2 . $e3, $d['docId']);
    }

    // ---------- 05 مصفوفة استجابة الباحث ----------
    /** @param array{title:string,date:\DateTimeInterface,docId:string,reviewers:array<int,array<int,array{0:string,1:string,2:string,3:string}>>} $d */
    public function matrix(array $d): string
    {
        $c = $this->cfg; $ord = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس'];
        $tbls = [];
        foreach ($d['reviewers'] as $k => $notes) {
            $rows = ''; $min = max(12, count($notes));
            for ($i = 0; $i < $min; $i++) {
                $nt = $notes[$i] ?? ['', '', '', ''];
                $rows .= '<tr><td style="text-align:center;width:5%">' . ($i + 1) . '</td><td style="width:8%"><input type="text" value="' . self::e($nt[0]) . '" data-f="p' . $k . '_' . $i . '"></td><td style="width:7%"><input type="text" value="' . self::e($nt[1]) . '" data-f="l' . $k . '_' . $i . '"></td>'
                    . '<td style="width:27%"><textarea rows="1" data-f="n' . $k . '_' . $i . '">' . self::e($nt[2]) . '</textarea></td><td style="width:17%"><textarea rows="1" data-f="r' . $k . '_' . $i . '">' . self::e($nt[3]) . '</textarea></td><td><textarea rows="1" data-f="a' . $k . '_' . $i . '"></textarea></td></tr>';
            }
            $lbl = 'المحكّم ' . ($ord[$k] ?? ($k + 1));
            $tbls[] = '<table class="t"><thead><tr><th colspan="6" style="text-align:right">ملاحظات ' . $lbl . '</th></tr><tr><th>م</th><th>الصفحة</th><th>السطر</th><th>ملحوظة ' . $lbl . '</th><th>المطلوب</th><th>إجراء الباحث</th></tr></thead><tbody id="tr' . $k . '">' . $rows . '</tbody></table>'
                . '<div class="noprint btnrow"><button onclick="addRows(\'tr' . $k . '\',3)">+ ثلاثة أسطر</button></div>';
        }
        $who = count($d['reviewers']) === 2 ? 'الأول والثاني (مجهولا الهوية)' : count($d['reviewers']) . ' محكّمين (مجهولو الهوية)';
        $ack = '<h2>إقرار الباحث</h2><p>أقر بأنني راجعت جميع الملاحظات، وميّزت التعديلات في النسخة المنقحة وفق تعليمات المجلة.</p>'
            . '<table class="t k"><tr><td>اسم الباحث المراسل</td><td><input type="text" data-f="aname"></td></tr><tr><td>التاريخ</td><td><input type="text" data-f="adate" placeholder="يوم-شهر-سنة"></td></tr></table>';
        $n = count($tbls); $pages = '';
        foreach ($tbls as $k => $t) {
            $first = $k === 0;
            $pages .= '<div class="pg">' . $this->head('نموذج الباحث — مصفوفة استجابة الباحث')
                . ($first ? $this->band('جدول توضيح القيام بملحوظات المحكّمين', 'مصفوفة استجابة الباحث', $c['codes']['rsp'], 'تاريخ الإرسال', self::dateLine($d['date'])) : '')
                . '<div class="ct">' . ($first ? $this->titleRows($d['title']) . '<table class="t k"><tr><td>المحكّمان</td><td>' . self::e($who) . '</td></tr></table><p>نرجو تسجيل كل ملاحظة ذكرها المحكّم، وما تم حيالها من قبلكم سواء بقبول الملاحظة من عدمها (مع التعليل العلمي عند عدم الأخذ بها).</p>' : '')
                . $t . ($k === $n - 1 ? $ack : '') . '</div>' . $this->foot('rsp', $k + 1, $n) . '</div>';
        }
        $docId = $d['docId'];
        return $this->shell('مصفوفة استجابة الباحث — ' . $d['title'], $pages, $docId);
    }
}
