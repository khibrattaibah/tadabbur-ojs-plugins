<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/quranVerifier/classes/QuranEngine.php
 *
 * محرك مطابقة الآيات: يستخرج الاقتباسات القرآنية من نص البحث ومراجعها، ويطابقها بنص المصحف
 * (Tanzil — النسختان الإملائية والعثمانية)، ويحدد: صحة النص، صحة العزو (السورة والآية)، والفروق كلمةً كلمة.
 *
 * لا يعتمد على الإنترنت ولا على قاعدة بيانات. يقارن الحروف دون الحركات (الضبط بالشكل لا يُفحص).
 */
namespace APP\plugins\generic\tadabburSuite\m\quranVerifier\classes;

class QuranEngine
{
    // حالات الاقتباس
    public const OK = 'ok';                 // النص والعزو صحيحان
    public const DIFF = 'diff';             // فروق في الكلمات
    public const REF_WRONG = 'refWrong';    // النص صحيح لكن العزو خاطئ
    public const REF_PARTIAL = 'refPartial';// العزو لا يطابق نطاق الآيات المقتبسة بدقة
    public const REF_MISSING = 'refMissing';// آية بلا عزو
    public const REF_INVALID = 'refInvalid';// سورة غير معروفة أو رقم آية خارج السورة
    public const NOT_FOUND = 'notFound';    // لم يُعثر على نص مطابق
    public const SHORT = 'short';           // اقتباس قصير لا يمكن الحكم عليه

    private string $dataDir;
    /** @var array<int, array{n:int, ar:string, en:string, ayas:int}> */
    private array $surahs = [];
    /** @var array<string,int> اسم مطبَّع → رقم السورة */
    private array $surahByName = [];
    /** آيات: [s][a] => ['c' => نص إملائي خام, 'u' => نص عثماني خام] */
    private array $ayas = [];
    /** كلمات المصحف المتتابعة (لكل نسخة): كل عنصر [s, a, normWord, rawWord, altNorm|null] */
    private array $words = ['c' => [], 'u' => []];
    /** بداية كلمات كل آية في المصفوفة: [corpus][s][a] => index */
    private array $start = ['c' => [], 'u' => []];
    /** فهرس مقلوب: [corpus][normWord] => list of word indices */
    private array $index = ['c' => [], 'u' => []];
    private bool $loaded = false;
    /** مواضع مطابقة تامة متعددة (للآيات المتكررة) من آخر بحث */
    private array $lastAlts = [];

    /** أسماء بديلة شائعة للسور في كتب أهل العلم */
    private const ALIASES = [
        1 => ['ام الكتاب', 'فاتحه الكتاب', 'الحمد'], 9 => ['براءه', 'براءة'], 17 => ['بني اسرائيل'], 40 => ['المؤمن', 'الطول'],
        41 => ['حم السجده', 'السجده حم'], 47 => ['القتال'], 54 => ['اقتربت'], 65 => ['النساء الصغرى'], 76 => ['الدهر', 'هل اتى'],
        83 => ['التطفيف'], 94 => ['الانشراح', 'الم نشرح'], 98 => ['لم يكن', 'القيمه'], 111 => ['تبت', 'اللهب'], 112 => ['التوحيد'],
        32 => ['الم السجده', 'المضاجع'], 33 => ['الاحزاب'], 67 => ['تبارك'], 78 => ['عم'], 113 => ['الفلق'], 114 => ['الناس'],
    ];

    /** مصادر الحديث المعروفة (مطبَّعة) */
    public const HADITH_SOURCES = ['البخاري', 'مسلم', 'ابو داود', 'ابي داود', 'الترمذي', 'النسائي', 'ابن ماجه', 'احمد', 'مالك', 'الموطا', 'الدارمي',
        'ابن حبان', 'الحاكم', 'البيهقي', 'الطبراني', 'ابن خزيمه', 'عبد الرزاق', 'ابن ابي شيبه', 'الدارقطني', 'ابو يعلى', 'البزار', 'سعيد بن منصور'];

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? dirname(__DIR__) . '/data';
    }

    // ================================================================ التطبيع

    /** تطبيع الحروف للمطابقة: حذف الحركات والعلامات وتوحيد الهمزات والألفات */
    public static function norm(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KC);
            if ($n !== false) $s = $n;
        }
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{08D3}-\x{08FF}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '', 'ی' => 'ي', 'ک' => 'ك', 'ۀ' => 'ه']);
        $s = preg_replace('/[^\x{0621}-\x{064A}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** تحويل الأرقام الهندية والفارسية إلى أرقام لاتينية (لا يغيّر عدد الحروف) */
    public static function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /** ترميز أحادي البايت للكلمة المطبّعة (لحساب مسافة التحرير بالحروف) */
    private static function sb(string $w): string
    {
        $r = @mb_convert_encoding($w, 'ISO-8859-6', 'UTF-8');
        return is_string($r) ? $r : $w;
    }

    // ================================================================ التحميل

    public function load(): void
    {
        if ($this->loaded) return;
        foreach (json_decode((string) file_get_contents($this->dataDir . '/surahs.json'), true) as $s) {
            $this->surahs[(int) $s['n']] = $s;
            $this->addName(self::norm($s['ar']), (int) $s['n']);
        }
        foreach (self::ALIASES as $n => $list) foreach ($list as $a) $this->addName(self::norm($a), $n);

        foreach (['c' => 'quran-simple-clean.txt', 'u' => 'quran-uthmani.txt'] as $k => $file) {
            $fh = fopen($this->dataDir . '/' . $file, 'r');
            while (($line = fgets($fh)) !== false) {
                if ($line === '' || $line[0] === '#' || substr_count($line, '|') < 2) continue;
                [$s, $a, $t] = explode('|', rtrim($line, "\r\n"), 3);
                $this->ayas[(int) $s][(int) $a][$k] = $t;
            }
            fclose($fh);
        }
        foreach ($this->ayas as $s => $list) {
            foreach ($list as $a => $v) {
                $cRaw = preg_split('/\s+/u', trim($v['c'])); $uRaw = preg_split('/\s+/u', trim($v['u']));
                $cN = array_map([self::class, 'norm'], $cRaw); $uN = array_map([self::class, 'norm'], $uRaw);
                $same = count($cN) === count($uN);
                foreach (['c' => [$cRaw, $cN, $uN], 'u' => [$uRaw, $uN, $cN]] as $k => [$raw, $nrm, $alt]) {
                    $this->start[$k][$s][$a] = count($this->words[$k]);
                    foreach ($nrm as $i => $w) {
                        if ($w === '') continue;
                        $idx = count($this->words[$k]);
                        $this->words[$k][] = [$s, $a, $w, $raw[$i], $same ? ($alt[$i] ?? null) : null];
                        $this->index[$k][$w][] = $idx;
                    }
                }
            }
        }
        $this->loaded = true;
    }

    private function addName(string $n, int $num): void
    {
        if ($n === '') return;
        $this->surahByName[$n] = $num;
        if (str_starts_with($n, 'ال') && mb_strlen($n) > 4) $this->surahByName['@' . mb_substr($n, 2)] = $num; // بلا «ال» (يُستعمل بعد كلمة «سورة» فقط)
    }

    public function surah(int $n): ?array { $this->load(); return $this->surahs[$n] ?? null; }
    public function surahCount(): int { $this->load(); return count($this->surahs); }

    /** نص الآيات العثماني (للنسخ) */
    public function uthmani(int $s, int $a1, int $a2): string
    {
        $this->load(); $out = [];
        for ($a = $a1; $a <= $a2; $a++) if (isset($this->ayas[$s][$a])) $out[] = $this->ayas[$s][$a]['u'] . ' (' . $a . ')';
        return implode(' ', $out);
    }

    public function refLabel(int $s, int $a1, int $a2): string
    {
        $this->load();
        $name = $this->surahs[$s]['ar'] ?? (string) $s;
        return $name . ': ' . ($a1 === $a2 ? $a1 : $a1 . '-' . $a2);
    }

    // ================================================================ تحليل العزو

    /** يحاول قراءة عزو قرآني من بداية النص المعطى (أو قريبًا منها). @return array{s:int,a1:int,a2:int,raw:string,valid:bool,name:string}|null */
    public function parseRef(string $text, int $maxLead = 60): ?array
    {
        $this->load();
        $t = self::digits(mb_substr($text, 0, $maxLead + 90));
        // نسخة مطبّعة للحروف مع الإبقاء على الأرقام وعلامات الترقيم المهمة
        $n = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $t);
        $n = strtr($n, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '']);
        static $re = null; static $reSura = null;
        if ($re === null) {
            $plain = []; $after = [];
            foreach (array_keys($this->surahByName) as $k) {
                if ($k[0] === '@') $after[] = preg_quote(substr($k, 1), '/'); else $plain[] = preg_quote($k, '/');
            }
            usort($plain, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); usort($after, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $num = '(?:[:：،,\/\-–]|\s)*(?:(?:من\s+)?(?:الايات|الايه|الايتان|الايتين|ايه|ايات|اية|الاية|رقم)\s*[:：]?\s*(?:رقم\s*)?)?\(?(\d{1,3})\)?(?:\s*(?:[-–—]|الي|الى|حتى|و|،|,)\s*\(?(\d{1,3})\)?)?';
            $re = '/(?:سوره\s+)?(' . implode('|', $plain) . ')' . $num . '/u';
            $reSura = '/سوره\s+(' . implode('|', array_merge($plain, $after)) . ')' . $num . '/u';
        }
        $best = null;
        foreach ([$reSura, $re] as $rx) {
            if (preg_match($rx, $n, $m, PREG_OFFSET_CAPTURE)) {
                $leadTxt = substr($n, 0, $m[0][1]);
                $lead = mb_strlen($leadTxt);
                if ($lead > $maxLead) continue;
                // ما قبل العزو يجب ألا يحتوي كلامًا (إلا: انظر، ينظر، راجع)
                if (preg_match('/[\x{0621}-\x{064A}]/u', preg_replace('/انظر|ينظر|راجع|حاشيه/u', '', $leadTxt))) continue;
                if ($best === null || $m[0][1] < $best[0][0][1]) $best = [$m, $lead];
            }
        }
        if (!$best) return null;
        [$m] = $best;
        $name = $m[1][0];
        $s = $this->surahByName[$name] ?? $this->surahByName['@' . $name] ?? 0;
        $a1 = (int) $m[2][0]; $a2 = isset($m[3]) && $m[3][0] !== '' ? (int) $m[3][0] : $a1;
        if ($a2 < $a1) $a2 = $a1;
        $valid = $s > 0 && $a1 >= 1 && $a2 <= ($this->surahs[$s]['ayas'] ?? 0) && ($a2 - $a1) <= 40;
        return ['s' => $s, 'a1' => $a1, 'a2' => $a2, 'raw' => trim($m[0][0]), 'valid' => $valid, 'name' => $this->surahs[$s]['ar'] ?? $name, 'len' => mb_strlen(substr($n, 0, $m[0][1] + strlen($m[0][0])))];
    }

    // ================================================================ المطابقة

    /** تحويل الاقتباس الخام إلى كلمات مطبّعة مع الأصل */
    private function tokens(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim(self::digits($raw))) as $tok) {
            $n = self::norm($tok);
            if ($n === '') continue;
            foreach (explode(' ', $n) as $piece) $out[] = ['n' => $piece, 'r' => $tok];
        }
        return $out;
    }

    private static function sim(string $q, array $w): float
    {
        if ($q === $w[2] || ($w[4] !== null && $q === $w[4])) return 2.0;
        $a = self::sb($q); $b = self::sb($w[2]);
        $l = max(strlen($a), strlen($b));
        if ($l >= 3 && levenshtein($a, $b) === 1) return 0.4;  // كلمة قريبة (خطأ حرف واحد)
        return -1.0;
    }

    /**
     * محاذاة الاقتباس على مقطع من كلمات المصحف.
     * $local=false: كل كلمات الاقتباس تُحتسب (بدايات المقطع ونهاياته حرّة). $local=true: يُؤخذ أفضل جزء متطابق فقط.
     */
    private function align(array $q, string $k, int $from, int $to, bool $local): array
    {
        $T = array_slice($this->words[$k], $from, $to - $from + 1);
        $m = count($q); $n = count($T); $gap = 1.0;
        $H = array_fill(0, $m + 1, array_fill(0, $n + 1, 0.0));
        $P = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = 1; $i <= $m; $i++) { $H[$i][0] = $local ? 0.0 : -$i * $gap; $P[$i][0] = 2; }
        $best = [-INF, 0, 0];
        for ($i = 1; $i <= $m; $i++) {
            $qi = $q[$i - 1]['n'];
            for ($j = 1; $j <= $n; $j++) {
                $d = $H[$i - 1][$j - 1] + self::sim($qi, $T[$j - 1]);
                $u = $H[$i - 1][$j] - $gap;     // كلمة زائدة في الاقتباس
                $l = $H[$i][$j - 1] - $gap;     // كلمة ناقصة من الاقتباس
                if ($d >= $u && $d >= $l) { $v = $d; $p = 1; } elseif ($u >= $l) { $v = $u; $p = 2; } else { $v = $l; $p = 3; }
                if ($local && $v <= 0) { $v = 0.0; $p = 0; }
                $H[$i][$j] = $v; $P[$i][$j] = $p;
                if ($local && $v > $best[0]) $best = [$v, $i, $j];
            }
        }
        if (!$local) { for ($j = 0; $j <= $n; $j++) if ($H[$m][$j] > $best[0]) $best = [$H[$m][$j], $m, $j]; }
        [$score, $i, $j] = $best;
        $ops = [];
        while ($i > 0 && ($local ? $P[$i][$j] !== 0 && $H[$i][$j] > 0 : true)) {
            $p = $j === 0 ? 2 : $P[$i][$j];
            if ($p === 1) {
                $w = $T[$j - 1]; $s = self::sim($q[$i - 1]['n'], $w);
                $ops[] = ['op' => $s >= 2 ? '=' : 's', 'q' => $q[$i - 1]['r'], 't' => $w[3], 'pos' => $from + $j - 1];
                $i--; $j--;
            } elseif ($p === 2) { $ops[] = ['op' => 'i', 'q' => $q[$i - 1]['r'], 't' => '', 'pos' => null]; $i--; }
            elseif ($p === 3) { $ops[] = ['op' => 'd', 'q' => '', 't' => $T[$j - 1][3], 'pos' => $from + $j - 1]; $j--; }
            else break;
        }
        $ops = array_reverse($ops);
        // حذف الكلمات «الناقصة» في الطرفين (ليست جزءًا من الاقتباس)
        while ($ops && $ops[0]['op'] === 'd') array_shift($ops);
        while ($ops && end($ops)['op'] === 'd') array_pop($ops);
        $eq = count(array_filter($ops, fn ($o) => $o['op'] === '='));
        $errs = count($ops) - $eq;
        $pos = array_values(array_filter(array_column($ops, 'pos'), fn ($p) => $p !== null));
        $range = null;
        if ($pos) {
            $a = $this->words[$k][min($pos)]; $b = $this->words[$k][max($pos)];
            $range = $a[0] === $b[0] ? ['s' => $a[0], 'a1' => $a[1], 'a2' => $b[1]] : null;
        }
        $qLen = $local ? max(1, count(array_filter($ops, fn ($o) => $o['op'] !== 'd'))) : max(1, $m);
        return ['ops' => $ops, 'eq' => $eq, 'errors' => $errs, 'coverage' => $eq / $qLen, 'range' => $range, 'corpus' => $k, 'score' => $score];
    }

    private function better(?array $a, array $b): array
    {
        if (!$a) return $b;
        if (abs($a['coverage'] - $b['coverage']) > 1e-9) return $b['coverage'] > $a['coverage'] ? $b : $a;
        return $b['errors'] < $a['errors'] ? $b : $a;
    }

    /** محاذاة على نطاق آيات محدد (مع هامش) في النسختين */
    private function alignRange(array $q, int $s, int $a1, int $a2, int $margin, bool $local): ?array
    {
        $best = null;
        $max = $this->surahs[$s]['ayas'] ?? 0;
        if (!$max) return null;
        $a1 = max(1, $a1 - $margin); $a2 = min($max, $a2 + $margin);
        foreach (['c', 'u'] as $k) {
            $from = $this->start[$k][$s][$a1] ?? null;
            $to = isset($this->start[$k][$s][$a2 + 1]) ? $this->start[$k][$s][$a2 + 1] - 1 : (isset($this->start[$k][$s + 1][1]) ? $this->start[$k][$s + 1][1] - 1 : count($this->words[$k]) - 1);
            if ($from === null) continue;
            $best = $this->better($best, $this->align($q, $k, $from, $to, $local));
        }
        return $best;
    }

    /** بحث في المصحف كله: مرشحات من الفهرس المقلوب ثم محاذاة */
    private function search(array $q, bool $local): ?array
    {
        $votes = [];
        foreach (['c', 'u'] as $k) {
            $seen = [];
            foreach ($q as $t) {
                $w = $t['n'];
                if (isset($seen[$w]) || !isset($this->index[$k][$w])) continue;
                $seen[$w] = true;
                $list = $this->index[$k][$w];
                $weight = 1 / log(2 + count($list));
                if (count($list) > 1500) continue;
                $hit = [];
                foreach ($list as $idx) { $wd = $this->words[$k][$idx]; $hit[$wd[0] . ':' . $wd[1]] = true; }
                foreach ($hit as $key => $_) $votes[$key] = ($votes[$key] ?? 0) + $weight;
            }
        }
        if (!$votes) return null;
        arsort($votes);
        $best = null; $tried = 0; $spanAyas = max(0, (int) ceil(count($q) / 12)); $this->lastAlts = [];
        foreach (array_keys($votes) as $key) {
            if (++$tried > 8) break;
            [$s, $a] = array_map('intval', explode(':', $key));
            $r = $this->alignRange($q, $s, $a, $a, min(8, 1 + $spanAyas), $local);
            if (!$r) continue;
            if ($r['errors'] === 0 && $r['coverage'] >= 0.999 && $r['range']) $this->lastAlts[$this->refLabel($r['range']['s'], $r['range']['a1'], $r['range']['a2'])] = $r['range'];
            $best = $best ? $this->better($best, $r) : $r;
        }
        return $best;
    }

    /**
     * فحص اقتباس واحد.
     * @param string $quote النص المقتبس كما ورد
     * @param array|null $ref عزو محلل من parseRef
     * @param bool $loose true إن كان النص غير محصور بأقواس (يُحاذى محليًا)
     */
    public function check(string $quote, ?array $ref, bool $loose = false): array
    {
        $this->load();
        $q = $this->tokens($quote);
        $res = ['quote' => trim($quote), 'ref' => $ref, 'words' => count($q), 'status' => self::NOT_FOUND, 'match' => null, 'suggest' => null, 'ops' => []];
        if ($ref && !$ref['valid']) $res['status'] = self::REF_INVALID;
        if (count($q) < 3 && !$ref) { $res['status'] = self::SHORT; return $res; }
        if (!$q) { $res['status'] = self::SHORT; return $res; }

        $atRef = ($ref && $ref['valid']) ? $this->alignRange($q, $ref['s'], $ref['a1'], $ref['a2'], 2, $loose) : null;
        $good = fn ($r) => $r && $r['coverage'] >= ($loose ? 0.9 : 0.85) && $r['eq'] >= min(3, count($q));
        $m = $atRef;
        if (!$good($atRef)) {
            $glob = count($q) >= 3 ? $this->search($q, $loose) : null;
            if ($glob && (!$atRef || $glob['coverage'] > $atRef['coverage'] + 0.15)) $m = $glob;
        }
        if (!$m || $m['coverage'] < 0.5 || $m['eq'] < min(3, count($q))) {
            if ($res['status'] !== self::REF_INVALID) $res['status'] = count($q) < 3 ? self::SHORT : self::NOT_FOUND;
            if ($m && $m['coverage'] >= 0.35 && $m['range']) { $res['suggest'] = $m['range']; $res['ops'] = $m['ops']; }
            return $res;
        }
        $res['match'] = $m['range']; $res['ops'] = $m['ops']; $res['coverage'] = round($m['coverage'], 3); $res['errors'] = $m['errors'];
        $rg = $m['range'];
        $textOk = $m['errors'] === 0;
        if ($loose) { // في الوضع الحر تُهمل الكلمات الزائدة في الطرفين (هي من كلام الباحث)
            $res['quote'] = implode(' ', array_column(array_filter($m['ops'], fn ($o) => $o['op'] !== 'd'), 'q'));
        }
        if (!$ref) {
            $res['status'] = $textOk ? self::REF_MISSING : self::DIFF; $res['suggest'] = $rg; $res['noRef'] = true;
            if (count($this->lastAlts) > 1) $res['alts'] = array_values($this->lastAlts);
            return $res;
        }
        if (!$ref['valid']) { $res['status'] = self::REF_INVALID; $res['suggest'] = $rg; return $res; }
        $refOk = $rg && $rg['s'] === $ref['s'] && $rg['a1'] >= $ref['a1'] && $rg['a2'] <= $ref['a2'];
        $overlap = $rg && $rg['s'] === $ref['s'] && $rg['a1'] <= $ref['a2'] && $rg['a2'] >= $ref['a1'];
        if ($refOk) $res['status'] = $textOk ? self::OK : self::DIFF;
        elseif ($overlap) { $res['status'] = $textOk ? self::REF_PARTIAL : self::DIFF; $res['suggest'] = $rg; }
        else { $res['status'] = self::REF_WRONG; $res['suggest'] = $rg; }
        return $res;
    }

    // ================================================================ استخراج الاقتباسات من نص البحث

    /**
     * يحلل نص البحث كاملًا ويعيد قائمة الاقتباسات القرآنية ونتائجها، وملاحظات التخريج الحديثي.
     * @return array{quran: array, hadith: array, stats: array}
     */
    public function analyze(string $text): array
    {
        $this->load();
        $text = self::digits(str_replace(["\r\n", "\r"], "\n", $text));
        $items = []; $taken = [];
        $overlaps = function (int $a, int $b) use (&$taken): bool { foreach ($taken as [$x, $y]) if ($a < $y && $b > $x) return true; return false; };

        // 1) بين أقواس قرآنية أو معقوفة أو علامات تنصيص
        $patterns = [
            ['/\x{FD3F}([^\x{FD3E}\x{FD3F}]{1,4000})\x{FD3E}/u', 'ornate'],
            ['/\x{FD3E}([^\x{FD3E}\x{FD3F}]{1,4000})\x{FD3F}/u', 'ornate'],
            ['/\{([^{}]{3,4000})\}/u', 'brace'],
            ['/«([^«»]{3,2000})»/u', 'quote'],
            ['/[“"]([^“”"]{3,2000})[”"]/u', 'quote'],
        ];
        foreach ($patterns as [$rx, $kind]) {
            if (!preg_match_all($rx, $text, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) continue;
            foreach ($mm as $m) {
                $a = $m[0][1]; $b = $a + strlen($m[0][0]);
                if ($overlaps($a, $b)) continue;
                $inner = $m[1][0];
                if (!preg_match('/\p{Arabic}/u', $inner)) continue;
                $after = substr($text, $b, 400);
                $ref = $this->parseRef($after, $kind === 'quote' ? 15 : 60);
                if ($kind === 'quote' && !$ref) continue;          // علامات التنصيص العادية: لا تُعد آية إلا مع عزو صريح
                if ($kind === 'brace' && !$ref && count(explode(' ', trim($inner))) < 3) continue;
                $r = $this->check($inner, $ref, false);
                if ($kind !== 'ornate' && !$ref && $r['status'] === self::NOT_FOUND) continue; // نص عادي بين أقواس
                if ($kind === 'quote' && $r['status'] === self::NOT_FOUND) continue;          // تنصيص لغير آية (حديث أو كلام عالم)
                $r['kind'] = $kind; $r['offset'] = $a; $r['context'] = $this->context($text, $a, $b);
                $items[] = $r; $taken[] = [$a, $b + ($ref ? strlen(substr($after, 0, 0)) : 0)];
            }
        }
        // 2) عزو صريح بين معقوفين دون أقواس قرآنية قبله: [البقرة: 255] → يُؤخذ النص السابق
        if (preg_match_all('/[\[\(]([^\[\]\(\)\n]{3,60})[\]\)]/u', $text, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $a = $m[0][1]; $b = $a + strlen($m[0][0]);
                if ($overlaps(max(0, $a - 5), $b)) continue;
                $ref = $this->parseRef($m[1][0], 3);
                if (!$ref || $ref['len'] < mb_strlen(trim($m[1][0])) - 2) continue;
                $prev = substr($text, max(0, $a - 900), min($a, 900));
                $prev = preg_replace('/^.*[\.\n:؛](?=[^\.\n:؛]{12,}$)/us', '', $prev); // من بداية الجملة أو بعد النقطتين
                if ($prev === null || mb_strlen(trim($prev)) < 8) continue;
                $r = $this->check($prev, $ref, true);
                if (in_array($r['status'], [self::NOT_FOUND, self::SHORT], true)) continue;
                $r['kind'] = 'plain'; $r['offset'] = $a; $r['context'] = $this->context($text, max(0, $a - strlen($prev)), $b);
                $items[] = $r; $taken[] = [$a - strlen($prev), $b];
            }
        }
        usort($items, fn ($x, $y) => $x['offset'] <=> $y['offset']);

        $hadith = $this->hadith($text);
        $stats = ['total' => count($items)];
        foreach ([self::OK, self::DIFF, self::REF_WRONG, self::REF_PARTIAL, self::REF_MISSING, self::REF_INVALID, self::NOT_FOUND, self::SHORT] as $st) {
            $stats[$st] = count(array_filter($items, fn ($i) => $i['status'] === $st));
        }
        $stats['problems'] = $stats['total'] - $stats[self::OK] - $stats[self::SHORT];
        $stats['hadith'] = count($hadith);
        $stats['hadithIssues'] = count(array_filter($hadith, fn ($h) => $h['issues']));
        $stats['style'] = count(array_filter($items, fn ($i) => in_array($i['kind'], ['brace', 'quote', 'plain'], true)));
        return ['quran' => $items, 'hadith' => $hadith, 'stats' => $stats];
    }

    private function context(string $text, int $a, int $b): string
    {
        $s = max(0, $a - 90); $e = min(strlen($text), $b + 120);
        $c = mb_convert_encoding(substr($text, $s, $e - $s), 'UTF-8', 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $c));
    }

    // ================================================================ التخريج الحديثي

    /** يرصد عبارات التخريج والأحاديث المذكورة بلا تخريج قريب */
    public function hadith(string $text): array
    {
        $out = [];
        $n = fn ($s) => strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $srcRx = '(' . implode('|', array_map(fn ($x) => preg_quote($x, '/'), self::HADITH_SOURCES)) . ')';
        $seen = [];
        // أ) عبارات التخريج
        if (preg_match_all('/(?:رواه|أخرجه|اخرجه|خرجه|خرّجه|متفق عليه|أخرجاه|رواه الشيخان)[^\n\.⟧؛]{0,260}/u', $text, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [$frag, $off]) {
                $f = $n(preg_replace('/[\x{064B}-\x{065F}]/u', '', $frag));
                preg_match_all('/' . $srcRx . '/u', $f, $src);
                $sources = array_values(array_unique($src[1] ?? []));
                if (str_contains($f, 'متفق عليه') || str_contains($f, 'الشيخان') || str_contains($f, 'اخرجاه')) $sources = array_values(array_unique(array_merge(['البخاري', 'مسلم'], $sources)));
                if (!$sources) continue;
                $hasNum = (bool) preg_match('/(?:رقم|ح|حديث|برقم|ح\.)\s*[:\(\[]?\s*\d+|\(\d{1,5}\)|\[\d{1,5}\]|\d{1,5}\s*\/\s*\d{1,5}|ج\s*\d+\s*،?\s*ص\s*\d+|\d+\s*[\/\\\\]\s*\d+/u', $f);
                $hasGrade = (bool) preg_match('/صحيح|حسن|ضعيف|صححه|حسنه|ضعفه|اسناده|إسناده|الالباني|الأرناؤوط|شعيب|موضوع|منكر|غريب/u', $frag . ' ' . substr($text, $off + strlen($frag), 220));
                $onlySahih = !array_diff($sources, ['البخاري', 'مسلم']);
                $issues = [];
                if (!$hasNum) $issues[] = 'noNumber';
                if (!$onlySahih && !$hasGrade) $issues[] = 'noGrade';
                $out[] = ['type' => 'takhrij', 'text' => trim(mb_substr($frag, 0, 220)), 'sources' => $sources, 'issues' => $issues, 'offset' => $off];
                $seen[] = $off;
            }
        }
        // ب) نص حديث منسوب للنبي ﷺ دون تخريج قريب
        if (preg_match_all('/(?:قال رسول الله|قال النبي|أن رسول الله|أن النبي|عن النبي|سمعت رسول الله|قوله ﷺ|قال ﷺ)[^«"“]{0,60}[«"“]([^»"”]{8,800})[»"”]/u', $text, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $end = $m[0][1] + strlen($m[0][0]);
                $win = substr($text, $end, 300);
                $nl = strpos($win, "\n"); if ($nl !== false) $win = substr($win, 0, $nl); // التخريج في الفقرة نفسها أو حاشيتها
                $near = false;
                foreach ($seen as $o) if ($o >= $m[0][1] && $o <= $end + strlen($win)) { $near = true; break; }
                if (!$near && preg_match('/رواه|أخرجه|اخرجه|متفق عليه|صحيح البخاري|صحيح مسلم|سنن|مسند|الموطأ/u', $win)) $near = true;
                if ($near) continue;
                $out[] = ['type' => 'hadith', 'text' => trim(mb_substr($m[1][0], 0, 200)), 'sources' => [], 'issues' => ['noTakhrij'], 'offset' => $m[0][1]];
            }
        }
        usort($out, fn ($x, $y) => $x['offset'] <=> $y['offset']);
        return $out;
    }
}
