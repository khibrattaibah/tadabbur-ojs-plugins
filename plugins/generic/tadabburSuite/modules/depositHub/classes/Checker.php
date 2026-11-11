<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/depositHub/classes/Checker.php
 * التحقق الحي من الإيداع والربط، مع تخزين آخر نتيجة لكل بحث.
 */
namespace APP\plugins\generic\tadabburSuite\m\depositHub\classes;

use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Checker extends Migration
{
    public const T = 'deposit_checks';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('submission_id');
            $t->bigInteger('context_id');
            $t->string('doi', 255)->default('');
            $t->smallInteger('crossref')->nullable();
            $t->smallInteger('cr_abstract')->nullable();
            $t->smallInteger('cr_refs')->nullable();
            $t->smallInteger('cr_orcid')->nullable();
            $t->string('landing', 1024)->default('');
            $t->smallInteger('landing_ok')->nullable();
            $t->string('openalex_id', 64)->default('');
            $t->integer('cited')->nullable();
            $t->dateTime('checked_at')->nullable();
            $t->unique(['submission_id'], 'deposit_checks_pk');
            $t->index(['context_id'], 'deposit_checks_ctx');
        });
    }

    public function down(): void {}

    public function cached(int $cid): array { return DB::table(self::T)->where('context_id', $cid)->get()->keyBy('submission_id')->all(); }

    public static function norm(string $u): string { return rtrim(preg_replace('~^https?://(www\.)?~i', '', trim($u)), '/'); }

    /** فحص بحث واحد؛ $urls روابط صفحة البحث المقبولة */
    public function check(int $cid, int $sid, string $doi, array $urls, string $mailto, string $oaKey): object
    {
        $row = ['submission_id' => $sid, 'context_id' => $cid, 'doi' => $doi, 'crossref' => null, 'cr_abstract' => null, 'cr_refs' => null, 'cr_orcid' => null,
            'landing' => '', 'landing_ok' => null, 'openalex_id' => '', 'cited' => null, 'checked_at' => date('Y-m-d H:i:s')];
        if ($doi !== '') {
            [$st, $cr] = Services::getJson('https://api.crossref.org/works/' . rawurlencode($doi) . ($mailto ? '?mailto=' . rawurlencode($mailto) : ''), 12, $mailto);
            if ($st === 200 && isset($cr['message'])) {
                $m = $cr['message'];
                $row['crossref'] = 1; $row['cr_abstract'] = !empty($m['abstract']) ? 1 : 0; $row['cr_refs'] = (int) ($m['references-count'] ?? 0) > 0 ? 1 : 0;
                $row['cr_orcid'] = array_filter((array) ($m['author'] ?? []), fn ($a) => !empty($a['ORCID'])) ? 1 : 0;
                $row['cited'] = (int) ($m['is-referenced-by-count'] ?? 0);
            } elseif ($st === 404) $row['crossref'] = 0;
            [$st, $hd] = Services::getJson('https://doi.org/api/handles/' . rawurlencode($doi) . '?type=URL', 10, $mailto);
            if ($st === 200) {
                foreach ((array) ($hd['values'] ?? []) as $v) if (($v['type'] ?? '') === 'URL') $row['landing'] = (string) ($v['data']['value'] ?? '');
                $n = self::norm($row['landing']); $row['landing_ok'] = 0;
                foreach ($urls as $u) if ($u !== '' && ($n === self::norm($u) || str_starts_with($n, self::norm($u) . '/'))) $row['landing_ok'] = 1;
            } elseif ($st === 404) $row['landing_ok'] = 0;
            [$st, $oa] = Services::openalex('works/doi:' . rawurlencode($doi) . '?select=id,cited_by_count', $oaKey, $mailto, 10);
            if ($st === 200 && !empty($oa['id'])) { $row['openalex_id'] = basename((string) $oa['id']); $row['cited'] = max((int) $row['cited'], (int) ($oa['cited_by_count'] ?? 0)); }
        }
        DB::table(self::T)->updateOrInsert(['submission_id' => $sid], $row);
        return (object) $row;
    }

    /** تغطية Crossref للمجلة وحضورها في DOAJ وOpenAlex */
    public static function journal(array $issns, string $mailto, string $oaKey): array
    {
        $out = ['crossref' => null, 'doaj' => null, 'openalex' => null];
        foreach (array_filter($issns) as $issn) {
            if (!$out['crossref']) {
                [$st, $j] = Services::getJson('https://api.crossref.org/journals/' . rawurlencode($issn) . ($mailto ? '?mailto=' . rawurlencode($mailto) : ''), 12, $mailto);
                if ($st === 200 && isset($j['message'])) {
                    $m = $j['message']; $c = (array) ($m['coverage'] ?? []);
                    $out['crossref'] = ['title' => $m['title'] ?? '', 'dois' => (int) ($m['counts']['total-dois'] ?? 0), 'current' => (int) ($m['counts']['current-dois'] ?? 0),
                        'abstracts' => $c['abstracts-current'] ?? null, 'references' => $c['references-current'] ?? null, 'orcids' => $c['orcids-current'] ?? null,
                        'affiliations' => $c['affiliations-current'] ?? null, 'licenses' => $c['licenses-current'] ?? null, 'funders' => $c['funders-current'] ?? null,
                        'ror' => $c['ror-ids-current'] ?? null, 'similarity' => $c['similarity-checking-current'] ?? null];
                }
            }
            if (!$out['doaj']) {
                [$st, $d] = Services::getJson('https://doaj.org/api/search/journals/issn:' . rawurlencode($issn), 12, $mailto);
                if ($st === 200) $out['doaj'] = ['found' => (int) ($d['total'] ?? 0) > 0, 'since' => (string) ($d['results'][0]['created_date'] ?? '')];
            }
            if (!$out['openalex']) {
                [$st, $o] = Services::openalex('sources/issn:' . rawurlencode($issn), $oaKey, $mailto);
                if ($st === 200 && !empty($o['id'])) $out['openalex'] = ['id' => basename((string) $o['id']), 'works' => (int) ($o['works_count'] ?? 0), 'cited' => (int) ($o['cited_by_count'] ?? 0), 'h' => (int) ($o['summary_stats']['h_index'] ?? 0)];
            }
        }
        return $out;
    }
}
