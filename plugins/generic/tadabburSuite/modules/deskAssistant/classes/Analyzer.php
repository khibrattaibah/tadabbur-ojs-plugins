<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/deskAssistant/classes/Analyzer.php
 * تحليل اختياري عبر Claude لمساعدة المحرر في القرار الأولي، وجدول حفظ التحليلات.
 * القرار للمحرر دائمًا؛ التحليل مسودة رأي تُعرض عليه.
 */
namespace APP\plugins\generic\tadabburSuite\m\deskAssistant\classes;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Analyzer extends Migration
{
    public const TABLE = 'desk_analyses';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->bigIncrements('analysis_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id');
            $t->bigInteger('user_id')->nullable();
            $t->string('model', 80)->default('');
            $t->smallInteger('with_text')->default(0);
            $t->longText('result');
            $t->integer('tokens_in')->default(0);
            $t->integer('tokens_out')->default(0);
            $t->dateTime('created_at');
            $t->index(['context_id', 'submission_id'], 'desk_analyses_sub');
        });
    }

    public function down(): void {}

    public static function latest(int $cid, int $sid): ?object
    {
        return DB::table(self::TABLE)->where('context_id', $cid)->where('submission_id', $sid)->orderBy('analysis_id', 'desc')->first();
    }

    public static function latestFor(int $cid, array $sids): array
    {
        if (!$sids) return [];
        $out = [];
        foreach (DB::table(self::TABLE)->where('context_id', $cid)->whereIn('submission_id', $sids)->orderBy('analysis_id', 'desc')->get(['submission_id', 'result']) as $r) {
            if (!isset($out[(int) $r->submission_id])) $out[(int) $r->submission_id] = json_decode((string) $r->result, true) ?: [];
        }
        return $out;
    }

    public static function save(array $row): int
    {
        return (int) DB::table(self::TABLE)->insertGetId($row + ['created_at' => date('Y-m-d H:i:s')], 'analysis_id');
    }

    /** @return array{data:array, model:string, in:int, out:int} */
    public static function run(array $cfg, string $journal, string $scope, array $meta, string $text): array
    {
        $system = "You are an experienced editor-in-chief assistant for the peer-reviewed journal \"$journal\". "
            . "You help the handling editor make an initial (desk) assessment of a new submission before peer review. Be fair, specific and evidence-based; "
            . "never invent content that is not in the material; if information is missing, say so. Write all free-text fields in Arabic (Modern Standard Arabic), concise and professional. "
            . "Output JSON only.";
        $task = "Journal aims and scope:\n" . ($scope !== '' ? $scope : '(not provided)') . "\n\n"
            . "Submission metadata:\n" . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
            . ($text !== '' ? "Manuscript text (beginning, may be truncated):\n" . $text . "\n\n" : "Only the metadata is available (the manuscript text was not shared).\n\n")
            . "Return exactly this JSON:\n"
            . '{"summary": string (3-5 sentences), "question": string (research question/problem), "method": string, "contribution": string, '
            . '"scopeFit": "high"|"medium"|"low", "scopeReason": string, "strengths": [string], "concerns": [string], '
            . '"decision": "review"|"revise_first"|"desk_reject", "decisionReason": string, "reviewerExpertise": [string] (3-5 areas, in Arabic), "questionsForAuthor": [string]}';
        $res = Application::get()->getHttpClient()->request('POST', $cfg['endpoint'], [
            'headers' => ['x-api-key' => $cfg['apiKey'], 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
            'json' => ['model' => $cfg['model'], 'max_tokens' => 3000, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $task]]],
            'timeout' => 150, 'http_errors' => false,
        ]);
        $body = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() !== 200) throw new \RuntimeException(mb_substr((string) ($body['error']['message'] ?? ('HTTP ' . $res->getStatusCode())), 0, 300));
        $txt = '';
        foreach ((array) ($body['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $txt .= $c['text'];
        $s = strpos($txt, '{'); $e = strrpos($txt, '}');
        $d = $s !== false && $e !== false ? json_decode(substr($txt, $s, $e - $s + 1), true) : null;
        if (!is_array($d)) throw new \RuntimeException('Unexpected AI response.');
        $str = fn ($k) => trim(strip_tags((string) ($d[$k] ?? '')));
        $arr = fn ($k) => array_values(array_filter(array_map(fn ($x) => trim(strip_tags((string) $x)), (array) ($d[$k] ?? []))));
        $data = ['summary' => $str('summary'), 'question' => $str('question'), 'method' => $str('method'), 'contribution' => $str('contribution'),
            'scopeFit' => in_array($d['scopeFit'] ?? '', ['high', 'medium', 'low'], true) ? $d['scopeFit'] : 'medium', 'scopeReason' => $str('scopeReason'),
            'strengths' => $arr('strengths'), 'concerns' => $arr('concerns'),
            'decision' => in_array($d['decision'] ?? '', ['review', 'revise_first', 'desk_reject'], true) ? $d['decision'] : 'review', 'decisionReason' => $str('decisionReason'),
            'reviewerExpertise' => $arr('reviewerExpertise'), 'questionsForAuthor' => $arr('questionsForAuthor')];
        return ['data' => $data, 'model' => (string) ($body['model'] ?? $cfg['model']), 'in' => (int) ($body['usage']['input_tokens'] ?? 0), 'out' => (int) ($body['usage']['output_tokens'] ?? 0)];
    }
}
