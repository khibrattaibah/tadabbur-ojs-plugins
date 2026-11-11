<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/referenceRomanizer/classes/Romanizer.php
 *
 * رومنة المراجع العربية وفق ALA-LC (مع خيار الصيغة المبسطة دون علامات).
 * - معجم مدمج لأشهر ألفاظ التوثيق وأسماء المصنفين والمدن ودور النشر وألفاظ عناوين علوم الشريعة.
 * - للكلمات غير الموجودة في المعجم: نقحرة حرفية مع تقدير الحركات، وتُعلَّم «تحتاج مراجعة».
 * - يميّز أرقام الطبعات والمجلدات والصفحات والتواريخ الهجرية والميلادية.
 * النص غير المشكول لا يمكن رومنته آليًا بدقة كاملة؛ لذا تُعرض النتيجة مسودةً للمحرر.
 */
namespace APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes;

class Romanizer
{
    /** ألفاظ كاملة (بعد التطبيع وحذف أل) => الرومنة */
    private const DICT = [
        // ألفاظ التوثيق
        'تحقيق' => 'taḥqīq', 'تح' => 'ed.', 'تحقيقودراسة' => 'taḥqīq wa-dirāsah', 'دراسة' => 'dirāsah', 'ترجمة' => 'tarjamat', 'تقديم' => 'taqdīm', 'تعليق' => 'taʻlīq',
        'تخريج' => 'takhrīj', 'شرح' => 'sharḥ', 'مراجعة' => 'murājaʻat', 'إعداد' => 'iʻdād', 'جمع' => 'jamʻ', 'ضبط' => 'ḍabṭ', 'تصحيح' => 'taṣḥīḥ', 'اعتنى' => 'iʻtaná', 'به' => 'bihi',
        'طبعة' => 'ṭabʻah', 'الطبعة' => 'al-ṭabʻah', 'دار' => 'Dār', 'مكتبة' => 'Maktabat', 'مطبعة' => 'Maṭbaʻat', 'مؤسسة' => 'Muʼassasat', 'مركز' => 'Markaz', 'مجمع' => 'Majmaʻ',
        'جامعة' => 'Jāmiʻat', 'كلية' => 'Kullīyat', 'مجلة' => 'Majallat', 'مجلس' => 'Majlis', 'وزارة' => 'Wizārat', 'المملكة' => 'al-Mamlakah', 'العربية' => 'al-ʻArabīyah', 'السعودية' => 'al-Saʻūdīyah',
        'رسالة' => 'Risālat', 'ماجستير' => 'mājistīr', 'دكتوراه' => 'duktūrāh', 'غير' => 'ghayr', 'منشورة' => 'manshūrah', 'بحث' => 'baḥth', 'مقال' => 'maqāl', 'مؤتمر' => 'muʼtamar',
        'العدد' => 'al-ʻadad', 'عدد' => 'ʻadad', 'المجلد' => 'al-mujallad', 'مجلد' => 'mujallad', 'الجزء' => 'al-juzʼ', 'جزء' => 'juzʼ', 'السنة' => 'al-sanah', 'سنة' => 'sanah',
        'للنشر' => 'lil-Nashr', 'والتوزيع' => 'wa-al-Tawzīʻ', 'للطباعة' => 'lil-Ṭibāʻah', 'والنشر' => 'wa-al-Nashr', 'النشر' => 'al-Nashr', 'الطباعة' => 'al-Ṭibāʻah', 'التوزيع' => 'al-Tawzīʻ',
        'الإسلامية' => 'al-Islāmīyah', 'الإسلامي' => 'al-Islāmī', 'الإسلام' => 'al-Islām', 'العلمية' => 'al-ʻIlmīyah', 'العلمي' => 'al-ʻIlmī', 'الكتب' => 'al-Kutub', 'الكتاب' => 'al-Kitāb',
        'الرسالة' => 'al-Risālah', 'الفكر' => 'al-Fikr', 'المعرفة' => 'al-Maʻrifah', 'المعارف' => 'al-Maʻārif', 'الشروق' => 'al-Shurūq', 'السلام' => 'al-Salām', 'التراث' => 'al-Turāth',
        'إحياء' => 'Iḥyāʼ', 'الجيل' => 'al-Jīl', 'القلم' => 'al-Qalam', 'الحديث' => 'al-Ḥadīth', 'الحديثة' => 'al-Ḥadīthah', 'العصرية' => 'al-ʻAṣrīyah', 'الرشد' => 'al-Rushd', 'طيبة' => 'Ṭaybah',
        'ابن' => 'Ibn', 'بن' => 'ibn', 'بنت' => 'bint', 'أبو' => 'Abū', 'أبي' => 'Abī', 'أبا' => 'Abā', 'أم' => 'Umm', 'عبد' => 'ʻAbd', 'آل' => 'Āl',
        // المدن والبلدان
        'بيروت' => 'Beirut', 'القاهرة' => 'Cairo', 'الرياض' => 'Riyadh', 'جدة' => 'Jeddah', 'مكة' => 'Makkah', 'المكرمة' => 'al-Mukarramah', 'المدينة' => 'al-Madīnah', 'المنورة' => 'al-Munawwarah',
        'دمشق' => 'Damascus', 'بغداد' => 'Baghdad', 'عمان' => 'Amman', 'الكويت' => 'Kuwait', 'تونس' => 'Tunis', 'الدوحة' => 'Doha', 'الدمام' => 'Dammam', 'أبها' => 'Abha', 'الطائف' => 'Taif',
        'الشارقة' => 'Sharjah', 'دبي' => 'Dubai', 'أبوظبي' => 'Abu Dhabi', 'الرباط' => 'Rabat', 'الجزائر' => 'Algiers', 'حلب' => 'Aleppo', 'الإسكندرية' => 'Alexandria', 'المنصورة' => 'Mansoura',
        'إسطنبول' => 'Istanbul', 'كوالالمبور' => 'Kuala Lumpur', 'لندن' => 'London', 'ليدن' => 'Leiden', 'القصيم' => 'Qassim', 'بريدة' => 'Buraydah', 'تبوك' => 'Tabuk', 'نجران' => 'Najran',
        'مصر' => 'Miṣr', 'لبنان' => 'Lubnān', 'سوريا' => 'Sūriyā', 'الأردن' => 'al-Urdun', 'المغرب' => 'al-Maghrib', 'العراق' => 'al-ʻIrāq', 'اليمن' => 'al-Yaman',
        // ألفاظ العناوين الشائعة في علوم القرآن والشريعة
        'تفسير' => 'Tafsīr', 'التفسير' => 'al-Tafsīr', 'القرآن' => 'al-Qurʼān', 'قرآن' => 'Qurʼān', 'الكريم' => 'al-Karīm', 'العظيم' => 'al-ʻAẓīm', 'المجيد' => 'al-Majīd', 'الحكيم' => 'al-Ḥakīm',
        'جامع' => 'Jāmiʻ', 'الجامع' => 'al-Jāmiʻ', 'البيان' => 'al-Bayān', 'بيان' => 'Bayān', 'تأويل' => 'taʼwīl', 'آي' => 'āy', 'لأحكام' => 'li-Aḥkām', 'أحكام' => 'Aḥkām', 'الأحكام' => 'al-Aḥkām',
        'معالم' => 'Maʻālim', 'التنزيل' => 'al-Tanzīl', 'الكشاف' => 'al-Kashshāf', 'حقائق' => 'ḥaqāʼiq', 'غوامض' => 'ghawāmiḍ', 'مفاتيح' => 'Mafātīḥ', 'الغيب' => 'al-Ghayb', 'التحرير' => 'al-Taḥrīr',
        'والتنوير' => 'wa-al-Tanwīr', 'التنوير' => 'al-Tanwīr', 'تيسير' => 'Taysīr', 'الرحمن' => 'al-Raḥmān', 'كلام' => 'kalām', 'المنان' => 'al-Mannān', 'المحرر' => 'al-Muḥarrar', 'الوجيز' => 'al-Wajīz',
        'زاد' => 'Zād', 'المسير' => 'al-Masīr', 'علم' => 'ʻilm', 'علوم' => 'ʻUlūm', 'العلوم' => 'al-ʻUlūm', 'الإتقان' => 'al-Itqān', 'البرهان' => 'al-Burhān', 'مناهل' => 'Manāhil', 'العرفان' => 'al-ʻIrfān',
        'الدر' => 'al-Durr', 'المنثور' => 'al-Manthūr', 'روح' => 'Rūḥ', 'المعاني' => 'al-Maʻānī', 'معاني' => 'Maʻānī', 'السبع' => 'al-Sabʻ', 'المثاني' => 'al-Mathānī', 'أضواء' => 'Aḍwāʼ', 'إيضاح' => 'Īḍāḥ',
        'بالقرآن' => 'bi-al-Qurʼān', 'التدبر' => 'al-Tadabbur', 'تدبر' => 'Tadabbur', 'الأمثال' => 'al-Amthāl', 'أمثال' => 'Amthāl', 'القصص' => 'al-Qaṣaṣ', 'قصص' => 'Qiṣaṣ', 'الأنبياء' => 'al-Anbiyāʼ',
        'سورة' => 'Sūrat', 'السورة' => 'al-Sūrah', 'آية' => 'Āyah', 'الآيات' => 'al-Āyāt', 'آيات' => 'Āyāt', 'الإعجاز' => 'al-Iʻjāz', 'إعجاز' => 'Iʻjāz', 'البلاغة' => 'al-Balāghah', 'البلاغي' => 'al-Balāghī',
        'القراءات' => 'al-Qirāʼāt', 'القراءة' => 'al-Qirāʼah', 'النشر' => 'al-Nashr', 'العشر' => 'al-ʻAshr', 'التجويد' => 'al-Tajwīd', 'الناسخ' => 'al-Nāsikh', 'والمنسوخ' => 'wa-al-Mansūkh', 'أسباب' => 'Asbāb', 'النزول' => 'al-Nuzūl',
        'صحيح' => 'Ṣaḥīḥ', 'سنن' => 'Sunan', 'مسند' => 'Musnad', 'الموطأ' => 'al-Muwaṭṭaʼ', 'فتح' => 'Fatḥ', 'الباري' => 'al-Bārī', 'المنهاج' => 'al-Minhāj', 'شرح' => 'Sharḥ', 'السنة' => 'al-Sunnah', 'الحديث' => 'al-Ḥadīth',
        'لسان' => 'Lisān', 'العرب' => 'al-ʻArab', 'معجم' => 'Muʻjam', 'مقاييس' => 'Maqāyīs', 'اللغة' => 'al-Lughah', 'المفردات' => 'al-Mufradāt', 'غريب' => 'Gharīb', 'الصحاح' => 'al-Ṣiḥāḥ', 'القاموس' => 'al-Qāmūs', 'المحيط' => 'al-Muḥīṭ',
        'تاج' => 'Tāj', 'العروس' => 'al-ʻArūs', 'الفقه' => 'al-Fiqh', 'فقه' => 'Fiqh', 'أصول' => 'Uṣūl', 'المقاصد' => 'al-Maqāṣid', 'مقاصد' => 'Maqāṣid', 'الشريعة' => 'al-Sharīʻah', 'الموافقات' => 'al-Muwāfaqāt',
        'مجموع' => 'Majmūʻ', 'الفتاوى' => 'al-Fatāwá', 'فتاوى' => 'Fatāwá', 'منهج' => 'Manhaj', 'المنهج' => 'al-Manhaj', 'دراسات' => 'Dirāsāt', 'الدراسات' => 'al-Dirāsāt', 'القرآنية' => 'al-Qurʼānīyah',
        'تحليلية' => 'taḥlīlīyah', 'وصفية' => 'waṣfīyah', 'موضوعية' => 'mawḍūʻīyah', 'الموضوعي' => 'al-Mawḍūʻī', 'نقدية' => 'naqdīyah', 'مقارنة' => 'muqāranah', 'حكمة' => 'Ḥikmat', 'ضرب' => 'ḍarb',
        'في' => 'fī', 'من' => 'min', 'إلى' => 'ilá', 'على' => 'ʻalá', 'عن' => 'ʻan', 'مع' => 'maʻa', 'بين' => 'bayna', 'و' => 'wa-', 'أو' => 'aw', 'ما' => 'mā', 'لا' => 'lā', 'هل' => 'hal',
        // أعلام المصنفين
        'الطبري' => 'al-Ṭabarī', 'القرطبي' => 'al-Qurṭubī', 'كثير' => 'Kathīr', 'الزمخشري' => 'al-Zamakhsharī', 'الرازي' => 'al-Rāzī', 'البغوي' => 'al-Baghawī', 'السيوطي' => 'al-Suyūṭī',
        'الزركشي' => 'al-Zarkashī', 'عاشور' => 'ʻĀshūr', 'السعدي' => 'al-Saʻdī', 'الشنقيطي' => 'al-Shinqīṭī', 'الألوسي' => 'al-Ālūsī', 'الآلوسي' => 'al-Ālūsī', 'تيمية' => 'Taymīyah', 'القيم' => 'al-Qayyim',
        'الجوزية' => 'al-Jawzīyah', 'الجوزي' => 'al-Jawzī', 'عطية' => 'ʻAṭīyah', 'البخاري' => 'al-Bukhārī', 'مسلم' => 'Muslim', 'الترمذي' => 'al-Tirmidhī', 'داود' => 'Dāwūd', 'النسائي' => 'al-Nasāʼī',
        'ماجه' => 'Mājah', 'أحمد' => 'Aḥmad', 'حنبل' => 'Ḥanbal', 'مالك' => 'Mālik', 'الشافعي' => 'al-Shāfiʻī', 'الشاطبي' => 'al-Shāṭibī', 'الغزالي' => 'al-Ghazālī', 'الأصفهاني' => 'al-Aṣfahānī',
        'الراغب' => 'al-Rāghib', 'منظور' => 'Manẓūr', 'فارس' => 'Fāris', 'الجوهري' => 'al-Jawharī', 'الفيروزآبادي' => 'al-Fīrūzābādī', 'الزبيدي' => 'al-Zabīdī', 'حجر' => 'Ḥajar', 'العسقلاني' => 'al-ʻAsqalānī',
        'النووي' => 'al-Nawawī', 'الذهبي' => 'al-Dhahabī', 'الجزري' => 'al-Jazarī', 'الواحدي' => 'al-Wāḥidī', 'البيضاوي' => 'al-Bayḍāwī', 'النسفي' => 'al-Nasafī', 'الشوكاني' => 'al-Shawkānī',
        'رضا' => 'Riḍā', 'رشيد' => 'Rashīd', 'قطب' => 'Quṭb', 'سيد' => 'Sayyid', 'دراز' => 'Darrāz', 'الخالدي' => 'al-Khālidī', 'الرومي' => 'al-Rūmī', 'فهد' => 'Fahd', 'الطيار' => 'al-Ṭayyār', 'مساعد' => 'Musāʻid',
        'محمد' => 'Muḥammad', 'عبدالله' => 'ʻAbd Allāh', 'عبدالرحمن' => 'ʻAbd al-Raḥmān', 'عبدالعزيز' => 'ʻAbd al-ʻAzīz', 'إبراهيم' => 'Ibrāhīm', 'عمر' => 'ʻUmar', 'علي' => 'ʻAlī', 'عثمان' => 'ʻUthmān',
        'جرير' => 'Jarīr', 'إسماعيل' => 'Ismāʻīl', 'يوسف' => 'Yūsuf', 'خالد' => 'Khālid', 'صالح' => 'Ṣāliḥ', 'سعيد' => 'Saʻīd', 'حسن' => 'Ḥasan', 'حسين' => 'Ḥusayn', 'مصطفى' => 'Muṣṭafá', 'محمود' => 'Maḥmūd',
        'ناصر' => 'Nāṣir', 'سليمان' => 'Sulaymān', 'عيسى' => 'ʻĪsá', 'موسى' => 'Mūsá', 'يحيى' => 'Yaḥyá', 'زكريا' => 'Zakarīyā', 'جلال' => 'Jalāl', 'الدين' => 'al-Dīn', 'فخر' => 'Fakhr', 'بدر' => 'Badr',
        'شمس' => 'Shams', 'تقي' => 'Taqī', 'الطاهر' => 'al-Ṭāhir', 'الأمين' => 'al-Amīn', 'الله' => 'Allāh',
    ];
    /** ألفاظ تختصر: ج ص ط ... */
    private const ABBR = [
        'ج' => 'vol.', 'ص' => 'p.', 'ط' => 'ed.', 'د.ت' => 'n.d.', 'د.ط' => 'n.p.', 'د.م' => 'n.p.', 'هـ' => 'AH', 'ه' => 'AH', 'م' => 'CE', 'ع' => 'no.', 'مج' => 'vol.', 'تح' => 'ed.', 'ت' => 'd.',
    ];
    private const CONS = [
        'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'ḥ', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 'ṣ',
        'ض' => 'ḍ', 'ط' => 'ṭ', 'ظ' => 'ẓ', 'ع' => 'ʻ', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'ء' => 'ʼ', 'ؤ' => 'ʼ', 'ئ' => 'ʼ',
        'پ' => 'p', 'گ' => 'g', 'ڤ' => 'v', 'چ' => 'ch',
    ];
    private const SUN = ['ت', 'ث', 'د', 'ذ', 'ر', 'ز', 'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ل', 'ن'];

    public function __construct(private bool $simple = false) {}

    public static function hasArabic(string $s): bool { return (bool) preg_match('/\p{Arabic}/u', $s); }

    public static function normalize(string $w): string
    {
        $w = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $w); // تطويل وحركات
        return str_replace(['أ', 'إ', 'آ', 'ٱ'], ['أ', 'إ', 'آ', 'ا'], $w);
    }

    private function flat(string $s): string
    {
        if (!$this->simple) return $s;
        $s = strtr($s, ['ā' => 'a', 'ī' => 'i', 'ū' => 'u', 'Ā' => 'A', 'Ī' => 'I', 'Ū' => 'U', 'ḥ' => 'h', 'Ḥ' => 'H', 'ṣ' => 's', 'Ṣ' => 'S', 'ḍ' => 'd', 'Ḍ' => 'D', 'ṭ' => 't', 'Ṭ' => 'T',
            'ẓ' => 'z', 'Ẓ' => 'Z', 'ʻ' => "'", 'ʼ' => "'", 'á' => 'a']);
        return $s;
    }

    /** يحول الأرقام الهندية إلى عربية غربية */
    public static function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /**
     * يرومن مرجعًا كاملًا.
     * @return array{text:string, unknown:string[]}
     */
    public function romanize(string $ref): array
    {
        $ref = self::digits(trim($ref));
        $unknown = [];
        // علامات الترقيم العربية
        $ref = strtr($ref, ['،' => ',', '؛' => ';', '؟' => '?', '«' => '"', '»' => '"', '–' => '-']);
        // اختصارات بعلامات: د.ت، د.ط
        $ref = preg_replace_callback('/(?<!\p{Arabic})(د\.\s?ت|د\.\s?ط|د\.\s?م)(?!\p{Arabic})/u', fn ($m) => self::ABBR[str_replace(' ', '', $m[1])] ?? $m[1], $ref);
        // الطبعة: ط2 / ط 2 / ط.2
        $ref = preg_replace_callback('/(?<!\p{Arabic})ط\s*\.?\s*(\d+)/u', fn ($m) => self::ordinal((int) $m[1]) . ' ed.', $ref);
        // ج/ص مع رقم
        $ref = preg_replace('/(?<!\p{Arabic})ج\s*\.?\s*(\d+)/u', 'vol. $1', $ref);
        $ref = preg_replace('/(?<!\p{Arabic})صص?\s*\.?\s*(\d+)/u', 'p. $1', $ref);
        // سنة هجرية/ميلادية: 1420هـ، 1999م
        $ref = preg_replace('/(\d{3,4})\s*هـ?(?!\p{Arabic})/u', '$1 AH', $ref);
        $ref = preg_replace('/(\d{3,4})\s*م(?!\p{Arabic})/u', '$1 CE', $ref);

        $out = preg_replace_callback('/[\p{Arabic}\x{0640}]+/u', function ($m) use (&$unknown) {
            [$r, $known] = $this->word($m[0]);
            if (!$known) $unknown[] = $m[0];
            return $r;
        }, $ref);
        // تنظيف: "wa- " → "wa-"
        $out = preg_replace('/\b(wa|fa|bi|li|ka)- +/u', '$1-', $out);
        $out = preg_replace('/\s+([,.;:)])/u', '$1', preg_replace('/\s{2,}/u', ' ', $out));
        $out = preg_replace('/(ed|n\.d|n\.p|vol|p|no)\.\./u', '$1.', $out);
        return ['text' => $this->flat(trim($out)), 'unknown' => array_values(array_unique($unknown))];
    }

    private static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }

    /** @return array{0:string,1:bool} [الرومنة، هل هي معجمية] */
    private function word(string $w): array
    {
        $n = self::normalize($w);
        if (isset(self::ABBR[$n]) && mb_strlen($n) <= 2) return [self::ABBR[$n], true];
        if (mb_substr($n, 0, 5) === 'عبدال' && mb_strlen($n) > 6) {
            $rest = mb_substr($n, 5);
            $d = self::DICT['ال' . $rest] ?? null;
            return ['ʻAbd ' . ($d ? $d : 'al-' . self::cap(self::guess($rest))), (bool) $d];
        }
        if (isset(self::DICT[$n])) return [self::DICT[$n], true];
        // السوابق: و ف ب ل ك + أل
        foreach (['وال' => 'wa-al-', 'فال' => 'fa-al-', 'بال' => 'bi-al-', 'كال' => 'ka-al-', 'لل' => 'lil-', 'ال' => 'al-', 'و' => 'wa-', 'ف' => 'fa-', 'ب' => 'bi-', 'ل' => 'li-', 'ك' => 'ka-'] as $p => $lat) {
            if (mb_strlen($n) - mb_strlen($p) < 2 || mb_substr($n, 0, mb_strlen($p)) !== $p) continue;
            $rest = mb_substr($n, mb_strlen($p));
            $withAl = str_ends_with($lat, 'al-') || $lat === 'lil-';
            if ($withAl) {
                $key = 'ال' . $rest;
                if (isset(self::DICT[$key])) {
                    $d = self::DICT[$key];
                    $d = preg_replace('/^al-/u', '', $d);
                    return [($lat === 'al-' ? 'al-' : $lat) . $d, true];
                }
                if (isset(self::DICT[$rest])) return [$lat . self::lc1(self::DICT[$rest]), true];
                return [$lat . self::cap(self::guess($rest)), false];
            }
            if (isset(self::DICT[$rest])) return [$lat . self::DICT[$rest], true];
            if (isset(self::DICT['ال' . mb_substr($rest, 0)]) ) return [$lat . self::DICT['ال' . $rest], true];
        }
        return [self::cap(self::guess($n)), false];
    }

    /** حرف كبير لأول حرف لاتيني (بعد ʻ أو ʼ) */
    public static function cap(string $s): string
    {
        return preg_replace_callback('/^([ʻʼ]?)(\p{Ll})/u', fn ($m) => $m[1] . mb_strtoupper($m[2]), $s);
    }

    private static function lc1(string $s): string { return $s; }

    /** نقحرة تقديرية لكلمة غير مشكولة */
    public static function guess(string $w): string
    {
        $chars = preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($chars); $o = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i]; $next = $chars[$i + 1] ?? ''; $last = $i === $n - 1;
            switch ($c) {
                case 'ا': $o .= $i === 0 ? 'a' : 'ā'; break;
                case 'أ': $o .= $i === 0 ? 'a' : 'ʼa'; break;
                case 'إ': $o .= $i === 0 ? 'i' : 'ʼi'; break;
                case 'آ': $o .= $i === 0 ? 'ā' : 'ʼā'; break;
                case 'ى': $o .= 'á'; break;
                case 'ة': $o .= 'ah'; break;
                case 'و': $o .= ($i === 0 || $next === 'ا' || $next === 'ي') ? 'w' : 'ū'; break;
                case 'ي': if ($next === 'ة' && $i === $n - 2) { $o .= 'īy'; break; }
                    $o .= ($i === 0 || $next === 'ا' || $next === 'و' || $last && $i > 0 && $chars[$i - 1] === 'ي') ? 'y' : 'ī'; break;
                default:
                    $o .= self::CONS[$c] ?? $c;
                    // تقدير حركة بعد الحرف الساكن إذا تلاه حرف صحيح آخر
                    $nextIsCons = $next !== '' && isset(self::CONS[$next]);
                    if ($nextIsCons && $i < $n - 2) $o .= 'a';
            }
        }
        // كلمات العلم بأحرف كبيرة إن بدأت بأل لاحقًا يتولاه المستدعي
        return $o;
    }
}
