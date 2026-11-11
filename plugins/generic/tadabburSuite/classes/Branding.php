<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/classes/Branding.php
 *
 * الهوية المشتركة لكل الوحدات: ترويسة المجلة، توقيع رئيس التحرير، الختم، صورة الاسم...
 * تُرفع من صفحة الحزمة وتُحفظ في مجلد الملفات (files_dir/tadabburSuite/branding) خارج مجلد الإضافة،
 * فلا يمحوها أي تحديث للإضافة. وإن لم يُرفع شيء تُستعمل الصور الافتراضية المرفقة مع كل وحدة.
 */
namespace APP\plugins\generic\tadabburSuite\classes;

use PKP\config\Config;

class Branding
{
    /** المفتاح => [الامتداد، الوصف بالعربية، الوصف بالإنجليزية، المقاس الموصى به] */
    public const ASSETS = [
        'letterhead' => ['jpg', 'ورق المجلة الرسمي (الترويسة والتذييل) — الخطابات وشهادات المحكّمين', 'Official letterhead (A4)', '1240×1754 px'],
        'signature' => ['png', 'توقيع رئيس التحرير (خلفية شفافة)', 'Editor-in-Chief signature (transparent PNG)', '≈ 600×300 px'],
        'stamp' => ['png', 'ختم المجلة (خلفية شفافة)', 'Journal stamp (transparent PNG)', '≈ 500×500 px'],
        'name_editor' => ['png', 'صورة اسم رئيس التحرير ولقبه العلمي (خلفية شفافة)', 'Editor-in-Chief name image (transparent PNG)', '≈ 1100×200 px'],
        'sig_blue' => ['png', 'التوقيع الأزرق لنماذج التحكيم (خلفية شفافة)', 'Blue signature for review forms (transparent PNG)', '≈ 600×300 px'],
        'bg' => ['jpg', 'خلفية شهادة شكر المحكّم', 'Reviewer certificate background', '≈ 2480×1754 px'],
    ];
    public const MAX_BYTES = 6 * 1024 * 1024;

    /** مجلة الطلب الحالي (0 = خارج سياق مجلة) */
    private static function ctx(): int
    {
        try { $c = \APP\core\Application::get()->getRequest()->getContext(); return $c ? (int) $c->getId() : 0; } catch (\Throwable $e) { return 0; }
    }

    /** مجلد الهوية الخاص بمجلة واحدة؛ لا يشاركه غيرها (F05). الافتراضي: مجلة الطلب الحالي */
    public static function dir(?int $cid = null): string
    {
        $cid ??= self::ctx();
        return self::base() . '/' . ($cid > 0 ? 'c' . $cid : 'site');
    }

    private static function base(): string { return rtrim((string) Config::getVar('files', 'files_dir'), '/') . '/tadabburSuite/branding'; }

    /** ملفات الهوية القديمة المشتركة بين المجلات (قبل 1.5.0). لا تُستعمل تلقائيًا لأي مجلة. @return string[] */
    public static function legacyFiles(): array
    {
        $out = [];
        foreach (self::ASSETS as $key => [$ext]) if (is_file(self::base() . '/' . $key . '.' . $ext)) $out[] = $key;
        return $out;
    }

    /** نسخ صريح (يقرره المدير) للملفات القديمة إلى مجلته دون استبدال ما رفعه. يعيد عدد المنسوخ */
    public static function importLegacy(): int
    {
        $n = 0;
        foreach (self::legacyFiles() as $key) {
            $ext = self::ASSETS[$key][0];
            if (self::custom($key) !== null) continue;
            if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true)) continue;
            if (@copy(self::base() . '/' . $key . '.' . $ext, self::dir() . '/' . $key . '.' . $ext)) $n++;
        }
        return $n;
    }

    public static function custom(string $key, ?int $cid = null): ?string
    {
        if (!isset(self::ASSETS[$key])) return null;
        $f = self::dir($cid) . '/' . $key . '.' . self::ASSETS[$key][0];
        return is_file($f) ? $f : null;
    }

    /** مسار الصورة: المرفوعة إن وُجدت وإلا الافتراضي المرفق مع الوحدة. $file مثل signature.png */
    public static function path(string $file, string $default): string
    {
        $key = pathinfo($file, PATHINFO_FILENAME);
        if (($c = self::custom($key)) !== null && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === self::ASSETS[$key][0]) return $c;
        return $default;
    }

    /** يحفظ صورة مرفوعة بعد التحقق منها وتحويلها إلى الصيغة المطلوبة. يعيد رسالة خطأ أو null عند النجاح */
    public static function store(string $key, array $upload): ?string
    {
        if (!isset(self::ASSETS[$key])) return 'unknownKey';
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) return 'uploadFailed';
        if (($upload['size'] ?? 0) > self::MAX_BYTES) return 'tooBig';
        $info = @getimagesize($upload['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) return 'notImage';
        if (!function_exists('imagecreatefromstring')) return 'noGd';
        $im = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if (!$im) return 'notImage';
        $ext = self::ASSETS[$key][0];
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true)) return 'notWritable';
        $out = self::dir() . '/' . $key . '.' . $ext;
        $tmp = $out . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if ($ext === 'png') {
            imagealphablending($im, false); imagesavealpha($im, true);
            $ok = imagepng($im, $tmp, 6);
        } else {
            // JPEG لا يدعم الشفافية: نركّب الصورة على أبيض
            $w = imagesx($im); $h = imagesy($im);
            $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
            imagecopy($bg, $im, 0, 0, 0, 0, $w, $h); imagedestroy($im); $im = $bg;
            $ok = imagejpeg($im, $tmp, 92);
        }
        imagedestroy($im);
        if (!$ok || !@rename($tmp, $out)) { @unlink($tmp); return 'notWritable'; }
        return null;
    }

    public static function reset(string $key): void
    {
        if (($c = self::custom($key)) !== null) @unlink($c);
    }
}
