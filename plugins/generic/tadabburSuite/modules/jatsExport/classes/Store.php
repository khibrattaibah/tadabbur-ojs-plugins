<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/jatsExport/classes/Store.php
 * آخر متن محوَّل لكل بحث ومصدره.
 */
namespace APP\plugins\generic\tadabburSuite\m\jatsExport\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const T = 'jats_sources';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('submission_id');
            $t->bigInteger('context_id');
            $t->string('source_name', 255)->default('');
            $t->bigInteger('submission_file_id')->nullable();
            $t->longText('conv');
            $t->dateTime('updated_at');
            $t->dateTime('attached_at')->nullable();
            $t->unique(['submission_id'], 'jats_sources_pk');
        });
    }

    public function down(): void {}

    public function get(int $sid): ?array
    {
        $r = DB::table(self::T)->where('submission_id', $sid)->first();
        if (!$r) return null;
        return ['name' => $r->source_name, 'fileId' => $r->submission_file_id, 'conv' => json_decode($r->conv, true) ?: [], 'updated' => $r->updated_at, 'attached' => $r->attached_at];
    }

    public function all(int $cid): array { return DB::table(self::T)->where('context_id', $cid)->get()->keyBy('submission_id')->all(); }

    public function put(int $sid, int $cid, string $name, ?int $fileId, array $conv): void
    {
        DB::table(self::T)->updateOrInsert(['submission_id' => $sid], ['context_id' => $cid, 'source_name' => mb_substr($name, 0, 255), 'submission_file_id' => $fileId,
            'conv' => json_encode($conv, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function attached(int $sid): void { DB::table(self::T)->where('submission_id', $sid)->update(['attached_at' => date('Y-m-d H:i:s')]); }
}
