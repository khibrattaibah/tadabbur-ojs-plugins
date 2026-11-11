<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/arabicIndexes/classes/Store.php
 * سجل الإرسال والفهرسة لكل قاعدة ولكل عدد.
 */
namespace APP\plugins\generic\tadabburSuite\m\arabicIndexes\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const T = 'arabic_index_log';
    public const STATES = ['pending', 'sent', 'indexed', 'rejected'];

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('context_id');
            $t->string('db', 40);
            $t->bigInteger('issue_id');
            $t->string('status', 20)->default('pending');
            $t->date('sent_at')->nullable();
            $t->string('note', 500)->default('');
            $t->dateTime('updated_at')->nullable();
            $t->unique(['context_id', 'db', 'issue_id'], 'arabic_index_log_pk');
        });
    }

    public function down(): void {}

    public function matrix(int $cid): array
    {
        $out = [];
        foreach (DB::table(self::T)->where('context_id', $cid)->get() as $r) $out[$r->issue_id][$r->db] = $r;
        return $out;
    }

    public function set(int $cid, string $db, int $issueId, string $status, ?string $sent, string $note): void
    {
        DB::table(self::T)->updateOrInsert(['context_id' => $cid, 'db' => $db, 'issue_id' => $issueId],
            ['status' => $status, 'sent_at' => $sent, 'note' => mb_substr($note, 0, 500), 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
