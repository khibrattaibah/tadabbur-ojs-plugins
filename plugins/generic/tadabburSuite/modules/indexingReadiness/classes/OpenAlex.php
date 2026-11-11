<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/indexingReadiness/classes/OpenAlex.php
 * قراءة مؤشرات الاستشهاد المفتوحة من OpenAlex (https://openalex.org، بيانات CC0): للمجلة عبر ISSN، ولأعضاء هيئة التحرير عبر ORCID.
 */
namespace APP\plugins\generic\tadabburSuite\m\indexingReadiness\classes;

class OpenAlex
{
    public function __construct(private string $mailto = '', private string $apiKey = '') {}

    private function get(string $path): ?array
    {
        $url = 'https://api.openalex.org/' . $path . (str_contains($path, '?') ? '&' : '?') . ($this->mailto !== '' ? 'mailto=' . rawurlencode($this->mailto) : '') . ($this->apiKey !== '' ? '&api_key=' . rawurlencode($this->apiKey) : '');
        try {
            $res = \APP\core\Application::get()->getHttpClient()->request('GET', $url, ['timeout' => 15, 'http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
            if ($res->getStatusCode() !== 200) return null;
            return json_decode((string) $res->getBody(), true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{ok:bool, source?:array, board?:array, fetched:string, error?:string} */
    public function fetch(array $issns, array $orcids): array
    {
        $out = ['ok' => false, 'fetched' => date('Y-m-d H:i')];
        $src = null;
        foreach (array_filter($issns) as $issn) {
            $src = $this->get('sources/issn:' . rawurlencode(trim($issn)));
            if ($src && !empty($src['id'])) break;
        }
        if (!$src) { $out['error'] = 'notFound'; return $out; }
        $out['ok'] = true;
        $out['source'] = [
            'id' => $src['id'] ?? '', 'name' => $src['display_name'] ?? '', 'works' => (int) ($src['works_count'] ?? 0), 'cited' => (int) ($src['cited_by_count'] ?? 0),
            'meanCitedness2y' => round((float) ($src['summary_stats']['2yr_mean_citedness'] ?? 0), 3), 'h' => (int) ($src['summary_stats']['h_index'] ?? 0),
            'i10' => (int) ($src['summary_stats']['i10_index'] ?? 0), 'isInDoaj' => (bool) ($src['is_in_doaj'] ?? false),
            'counts' => array_slice(array_map(fn ($c) => ['year' => (int) $c['year'], 'works' => (int) $c['works_count'], 'cited' => (int) $c['cited_by_count']], (array) ($src['counts_by_year'] ?? [])), 0, 6),
        ];
        // أعضاء الهيئة: دفعة واحدة بمرشح ORCID (حتى 50)
        $orcids = array_slice(array_values(array_filter($orcids)), 0, 50);
        if ($orcids) {
            $r = $this->get('authors?per-page=50&filter=orcid:' . implode('|', array_map('rawurlencode', $orcids)));
            $board = [];
            foreach ((array) ($r['results'] ?? []) as $a) {
                $board[] = ['name' => $a['display_name'] ?? '', 'orcid' => preg_replace('#^https?://orcid.org/#', '', (string) ($a['orcid'] ?? '')),
                    'works' => (int) ($a['works_count'] ?? 0), 'cited' => (int) ($a['cited_by_count'] ?? 0), 'h' => (int) ($a['summary_stats']['h_index'] ?? 0)];
            }
            usort($board, fn ($x, $y) => $y['h'] <=> $x['h']);
            $out['board'] = $board;
        }
        return $out;
    }
}
