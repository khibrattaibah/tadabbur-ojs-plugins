<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/articleImpact/classes/Impact.php
 *
 * - الاستخدام: مشاهدات الملخص وتنزيلات النص الكامل من إحصاءات OJS (COUNTER) شهريًا.
 * - الاستشهادات: عدّاد Crossref (is-referenced-by-count، مفتوح) وOpenAlex (العدد وقائمة الأعمال المستشهِدة).
 * - ذاكرة مؤقتة في جدول article_impact حتى لا تُستدعى الواجهات مع كل زيارة.
 */
namespace APP\plugins\generic\tadabburSuite\m\articleImpact\classes;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Impact extends Migration
{
    public const TABLE = 'article_impact';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->bigInteger('submission_id')->primary();
            $t->bigInteger('context_id');
            $t->string('doi', 255)->default('');
            $t->integer('crossref_count')->nullable();
            $t->string('openalex_id', 64)->default('');
            $t->integer('openalex_count')->nullable();
            $t->text('citing')->nullable();          // JSON: أبرز الأعمال المستشهِدة
            $t->dateTime('fetched_at')->nullable();
            $t->string('error', 64)->default('');
            $t->index(['context_id'], 'article_impact_ctx');
        });
    }

    public function down(): void {}

    public function __construct(private string $mailto = '', private string $openalexKey = '') {}

    // ---------------------------------------------------------------- الاستخدام
    /** @return array{views:int, downloads:int, monthly: array<string, array{v:int,d:int}>} */
    public static function usage(int $contextId, int $submissionId, int $months = 12): array
    {
        $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $rows = DB::table('metrics_submission')->where('context_id', $contextId)->where('submission_id', $submissionId)
            ->selectRaw("assoc_type, SUBSTR(CAST(date AS CHAR(10)), 1, 7) as ym, SUM(metric) as m")->groupBy('assoc_type', 'ym')->get();
        $tot = DB::table('metrics_submission')->where('context_id', $contextId)->where('submission_id', $submissionId)
            ->selectRaw('assoc_type, SUM(metric) as m')->groupBy('assoc_type')->pluck('m', 'assoc_type')->all();
        $monthly = [];
        for ($i = $months - 1; $i >= 0; $i--) $monthly[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = ['v' => 0, 'd' => 0];
        foreach ($rows as $r) {
            if (!isset($monthly[$r->ym]) || $r->ym < substr($from, 0, 7)) continue;
            $k = (int) $r->assoc_type === Application::ASSOC_TYPE_SUBMISSION ? 'v' : 'd';
            $monthly[$r->ym][$k] += (int) $r->m;
        }
        return ['views' => (int) ($tot[Application::ASSOC_TYPE_SUBMISSION] ?? 0), 'downloads' => (int) ($tot[Application::ASSOC_TYPE_SUBMISSION_FILE] ?? 0), 'monthly' => $monthly];
    }

    /** إجمالي الاستخدام لكل البحوث @return array<int, array{v:int,d:int}> */
    public static function usageAll(int $contextId): array
    {
        $out = [];
        foreach (DB::table('metrics_submission')->where('context_id', $contextId)->whereNotNull('submission_id')->selectRaw('submission_id, assoc_type, SUM(metric) as m')->groupBy('submission_id', 'assoc_type')->get() as $r) {
            $k = (int) $r->assoc_type === Application::ASSOC_TYPE_SUBMISSION ? 'v' : 'd';
            $out[(int) $r->submission_id][$k] = ($out[(int) $r->submission_id][$k] ?? 0) + (int) $r->m;
        }
        return $out;
    }

    // ---------------------------------------------------------------- الاستشهادات
    public function get(int $submissionId): ?object
    {
        return DB::table(self::TABLE)->where('submission_id', $submissionId)->first();
    }

    public function all(int $contextId): array
    {
        return DB::table(self::TABLE)->where('context_id', $contextId)->get()->keyBy('submission_id')->all();
    }

    private function http(string $url, float $timeout): ?array
    {
        try {
            $res = Application::get()->getHttpClient()->request('GET', $url, ['timeout' => $timeout, 'connect_timeout' => min(5, $timeout), 'http_errors' => false,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'OJS-articleImpact/1.0 (' . ($this->mailto ?: 'journal') . ')']]);
            if ($res->getStatusCode() === 404) return ['_status' => 404];
            if ($res->getStatusCode() !== 200) return ['_status' => $res->getStatusCode()];
            return json_decode((string) $res->getBody(), true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** يجلب الاستشهادات ويحفظها. @return string رمز النتيجة: ok | nodoi | notfound | error */
    public function refresh(int $contextId, int $submissionId, string $doi, float $timeout = 12): string
    {
        $doi = strtolower(trim($doi));
        $row = ['context_id' => $contextId, 'doi' => $doi, 'fetched_at' => date('Y-m-d H:i:s'), 'error' => ''];
        if ($doi === '') {
            $row['error'] = 'nodoi';
            DB::table(self::TABLE)->updateOrInsert(['submission_id' => $submissionId], $row);
            return 'nodoi';
        }
        $m = $this->mailto !== '' ? '?mailto=' . rawurlencode($this->mailto) : '';
        $cr = $this->http('https://api.crossref.org/works/' . rawurlencode($doi) . $m, $timeout);
        if (isset($cr['message'])) $row['crossref_count'] = (int) ($cr['message']['is-referenced-by-count'] ?? 0);

        $key = $this->openalexKey !== '' ? '&api_key=' . rawurlencode($this->openalexKey) : '';
        $mail = $this->mailto !== '' ? '&mailto=' . rawurlencode($this->mailto) : '';
        $oa = $this->http('https://api.openalex.org/works/doi:' . rawurlencode($doi) . '?select=id,cited_by_count' . $key . $mail, $timeout);
        if (!empty($oa['id'])) {
            $row['openalex_id'] = (string) preg_replace('#^https://openalex.org/#', '', $oa['id']);
            $row['openalex_count'] = (int) ($oa['cited_by_count'] ?? 0);
            if ($row['openalex_count'] > 0) {
                $c = $this->http('https://api.openalex.org/works?filter=cites:' . $row['openalex_id'] . '&per-page=25&sort=publication_year:desc&select=id,doi,display_name,publication_year,primary_location,authorships' . $key . $mail, $timeout);
                if (is_array($c) && isset($c['results'])) $row['citing'] = json_encode(self::parseCiting($c), JSON_UNESCAPED_UNICODE); // عند الخطأ تبقى القائمة السابقة
            }
        }
        $ok = isset($row['crossref_count']) || isset($row['openalex_count']);
        if (!$ok) $row['error'] = (($cr['_status'] ?? 0) === 404 && (($oa['_status'] ?? 0) === 404 || $oa === null)) ? 'notfound' : 'error';
        $prev = $this->get($submissionId);
        if ($prev && !$ok) { // لا تُمسح بيانات سابقة صحيحة بسبب خطأ مؤقت
            DB::table(self::TABLE)->where('submission_id', $submissionId)->update(['fetched_at' => $row['fetched_at'], 'error' => $row['error']]);
        } else {
            DB::table(self::TABLE)->updateOrInsert(['submission_id' => $submissionId], $row);
        }
        return $ok ? 'ok' : $row['error'];
    }

    /** تحويل نتيجة OpenAlex (works?filter=cites:) إلى قائمة مختصرة */
    public static function parseCiting(array $json): array
    {
        $citing = [];
        foreach ((array) ($json['results'] ?? []) as $w) {
            $auth = (array) ($w['authorships'] ?? []);
            $names = array_filter(array_map(fn ($a) => (string) ($a['author']['display_name'] ?? ''), array_slice($auth, 0, 3)));
            $citing[] = ['title' => (string) ($w['display_name'] ?? ''), 'year' => (int) ($w['publication_year'] ?? 0),
                'doi' => (string) preg_replace('#^https://doi.org/#', '', (string) ($w['doi'] ?? '')),
                'source' => (string) ($w['primary_location']['source']['display_name'] ?? ''),
                'authors' => implode('، ', $names) . (count($auth) > 3 ? '…' : '')];
        }
        return $citing;
    }

    public static function best(?object $row): ?int
    {
        if (!$row) return null;
        $v = array_filter([$row->crossref_count, $row->openalex_count], fn ($x) => $x !== null);
        return $v ? max($v) : null;
    }
}
