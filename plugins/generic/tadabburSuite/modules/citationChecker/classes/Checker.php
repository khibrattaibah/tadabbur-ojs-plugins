<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/citationChecker/classes/Checker.php
 * فحص المراجع واحدًا واحدًا، مع التخزين المؤقت للنتائج.
 */
namespace APP\plugins\generic\tadabburSuite\m\citationChecker\classes;

use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Checker extends Migration
{
    public const T = 'citation_checks';
    private const AR_DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('submission_id');
            $t->bigInteger('context_id');
            $t->longText('data');
            $t->dateTime('checked_at');
            $t->unique(['submission_id'], 'citation_checks_pk');
        });
    }

    public function down(): void {}

    public function cached(int $sid): ?array
    {
        $r = DB::table(self::T)->where('submission_id', $sid)->first();
        return $r ? ['items' => json_decode($r->data, true) ?: [], 'at' => $r->checked_at] : null;
    }

    public function save(int $sid, int $cid, array $items): void
    {
        DB::table(self::T)->updateOrInsert(['submission_id' => $sid], ['context_id' => $cid, 'data' => json_encode($items, JSON_UNESCAPED_UNICODE), 'checked_at' => date('Y-m-d H:i:s')]);
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(strtr($s, self::AR_DIGITS));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s); // التشكيل والتطويل
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
    }

    /** نسبة الكلمات المشتركة (للمطابقة بين المرجع وعنوان Crossref) */
    public static function overlap(string $needle, string $hay): float
    {
        $w = array_filter(explode(' ', self::norm($needle)), fn ($x) => mb_strlen($x) > 2);
        if (!$w) return 0.0;
        $h = ' ' . self::norm($hay) . ' ';
        return count(array_filter($w, fn ($x) => str_contains($h, ' ' . $x . ' '))) / count($w);
    }

    public static function isArabic(string $s): bool { return preg_match_all('/\p{Arabic}/u', $s) > preg_match_all('/[A-Za-z]/', $s); }

    /** فحص قائمة كاملة؛ $online لإجراء طلبات Crossref */
    public function run(array $refs, string $mailto, bool $online = true, int $limit = 80): array
    {
        $out = []; $seen = [];
        foreach (array_slice(array_values($refs), 0, 200) as $i => $raw) {
            $raw = trim(preg_replace('/\s+/u', ' ', (string) $raw));
            $it = ['n' => $i + 1, 'text' => $raw, 'issues' => [], 'ok' => [], 'doi' => '', 'suggest' => null, 'retracted' => false, 'lang' => self::isArabic($raw) ? 'ar' : 'la'];
            $key = self::norm($raw);
            foreach ($seen as $j => $k) if ($k === $key || (mb_strlen($key) > 30 && similar_text($k, $key) / max(1, max(strlen($k), strlen($key))) > 0.92)) $it['issues'][] = ['dup', ['n' => $j + 1]];
            $seen[$i] = $key;
            $plain = strtr($raw, self::AR_DIGITS);
            if (preg_match('~(?:doi\.org/|doi:\s*|\b)(10\.\d{4,9}/[^\s<>"]+)~i', $raw, $m)) $it['doi'] = rtrim($m[1], '.,;)]');
            if (!preg_match('/(?<!\d)(1[3-4]\d\d|19\d\d|20\d\d)(?!\d)|د\.?\s?ت|n\.d\./u', $plain)) $it['issues'][] = ['noYear', []];
            if ($it['lang'] === 'ar') {
                $parts = preg_split('/[،,\.]\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) < 3) $it['issues'][] = ['tooShort', []];
                if (!preg_match('/(دار|مكتبة|مؤسسة|مطبعة|جامعة|المكتب|مركز|الناشر|مجلة|وزارة|الهيئة|عالم الكتب|منشورات)/u', $raw) && !preg_match('~https?://~', $raw)) $it['issues'][] = ['noPublisher', []];
                if (preg_match('/^\s*(\(|\d)/u', $raw)) $it['issues'][] = ['startsOdd', []];
            } else {
                if (mb_strlen($raw) < 40) $it['issues'][] = ['tooShort', []];
            }
            if ($online && $limit > 0) {
                if ($it['doi'] !== '') {
                    $limit--;
                    [$st, $cr] = Services::getJson('https://api.crossref.org/works/' . rawurlencode($it['doi']) . ($mailto ? '?mailto=' . rawurlencode($mailto) : ''), 10, $mailto);
                    if ($st === 404) $it['issues'][] = ['doiNotFound', []];
                    elseif ($st === 200 && isset($cr['message'])) {
                        $msg = $cr['message']; $title = (string) ($msg['title'][0] ?? '');
                        $it['ok'][] = 'doiOk'; $it['found'] = ['title' => $title, 'year' => (string) ($msg['issued']['date-parts'][0][0] ?? ''), 'journal' => (string) ($msg['container-title'][0] ?? '')];
                        if ($title !== '' && self::overlap($title, $raw) < 0.5 && $it['lang'] === 'la') $it['issues'][] = ['doiMismatch', ['t' => mb_substr($title, 0, 120)]];
                        foreach ((array) ($msg['updated-by'] ?? []) as $u) if (in_array($u['type'] ?? '', ['retraction', 'withdrawal'], true)) $it['retracted'] = true;
                        if (preg_match('/^(retracted|withdrawn)\b/i', $title)) $it['retracted'] = true;
                        if ($it['retracted']) $it['issues'][] = ['retracted', []];
                    }
                } elseif ($it['lang'] === 'la' && mb_strlen($raw) >= 40) {
                    $limit--;
                    [$st, $cr] = Services::getJson('https://api.crossref.org/works?rows=1&select=DOI,title,score,issued,container-title&query.bibliographic=' . rawurlencode(mb_substr($raw, 0, 300)) . ($mailto ? '&mailto=' . rawurlencode($mailto) : ''), 10, $mailto);
                    $hit = $cr['message']['items'][0] ?? null;
                    if ($st === 200 && $hit && (float) ($hit['score'] ?? 0) >= 60 && self::overlap((string) ($hit['title'][0] ?? ''), $raw) >= 0.7)
                        $it['suggest'] = ['doi' => (string) $hit['DOI'], 'title' => (string) ($hit['title'][0] ?? ''), 'year' => (string) ($hit['issued']['date-parts'][0][0] ?? '')];
                }
            }
            $out[] = $it;
        }
        return $out;
    }

    /** هل القائمة مرتبة هجائيًا (بالعربية ثم اللاتينية)؟ يعيد رقم أول مرجع خارج الترتيب أو null */
    public static function orderBreak(array $items): ?int
    {
        $prev = null;
        foreach ($items as $it) {
            $k = self::norm(preg_replace('/^(ال|al-|el-)/iu', '', ltrim($it['text'], '"\'«( ')));
            $k = preg_replace('/^(ابن|ابو|بن) /u', '$1', $k);
            if ($prev !== null && $it['lang'] === $prev[1] && strcmp($k, $prev[0]) < 0) return $it['n'];
            $prev = [$k, $it['lang']];
        }
        return null;
    }
}
