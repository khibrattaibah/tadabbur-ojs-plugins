<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/abstractAssistant/classes/Drafts.php
 * مسودات الترجمة والتدقيق: تُحفظ للمراجعة ولا تُطبَّق على البحث إلا باعتماد المحرر.
 */
namespace APP\plugins\generic\tadabburSuite\m\abstractAssistant\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Drafts extends Migration
{
    public const TABLE = 'abstract_drafts';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->bigIncrements('draft_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id');
            $t->bigInteger('publication_id');
            $t->string('mode', 20);              // translate | polish
            $t->string('source_locale', 20);
            $t->string('target_locale', 20);
            $t->longText('source');              // JSON: ما أُرسل
            $t->longText('draft');               // JSON: {title, subtitle, abstract, keywords[], notes[]}
            $t->string('model', 80)->default('');
            $t->string('status', 20)->default('draft'); // draft | applied | discarded
            $t->bigInteger('created_by')->nullable();
            $t->bigInteger('applied_by')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('applied_at')->nullable();
            $t->integer('tokens_in')->default(0);
            $t->integer('tokens_out')->default(0);
            $t->index(['context_id', 'submission_id'], 'abstract_drafts_sub');
        });
    }

    public function down(): void {}

    public function add(array $row): int
    {
        return (int) DB::table(self::TABLE)->insertGetId($row + ['created_at' => date('Y-m-d H:i:s')], 'draft_id');
    }

    public function get(int $id, int $cid): ?object
    {
        return DB::table(self::TABLE)->where('draft_id', $id)->where('context_id', $cid)->first();
    }

    public function update(int $id, int $cid, array $row): void
    {
        DB::table(self::TABLE)->where('draft_id', $id)->where('context_id', $cid)->update($row);
    }

    public function forSubmission(int $cid, int $sid): array
    {
        return DB::table(self::TABLE)->where('context_id', $cid)->where('submission_id', $sid)->orderBy('draft_id', 'desc')->get()->all();
    }

    /** آخر مسودة لكل بحث */
    public function latest(int $cid, array $sids): array
    {
        if (!$sids) return [];
        $out = [];
        foreach (DB::table(self::TABLE)->where('context_id', $cid)->whereIn('submission_id', $sids)->orderBy('draft_id', 'desc')->get(['draft_id', 'submission_id', 'status', 'mode', 'target_locale', 'created_at']) as $r) {
            if (!isset($out[(int) $r->submission_id])) $out[(int) $r->submission_id] = $r;
        }
        return $out;
    }

    public function usage(int $cid): array
    {
        $r = DB::table(self::TABLE)->where('context_id', $cid)->where('created_at', '>=', date('Y-m-01'))->selectRaw('COUNT(*) as n, SUM(tokens_in) as i, SUM(tokens_out) as o')->first();
        return ['n' => (int) ($r->n ?? 0), 'in' => (int) ($r->i ?? 0), 'out' => (int) ($r->o ?? 0)];
    }
}
