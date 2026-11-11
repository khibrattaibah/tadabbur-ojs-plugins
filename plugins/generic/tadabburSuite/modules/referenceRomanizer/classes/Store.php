<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * جدول مسودات الرومنة ونسخ المراجع الأصلية.
 */
namespace APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const TABLE = 'reference_romanizations';
    private static bool $ready = false;

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->unsignedBigInteger('publication_id')->primary();
            $t->unsignedBigInteger('context_id')->index();
            $t->mediumText('original_raw')->nullable();
            $t->mediumText('rows_json')->nullable();
            $t->smallInteger('applied')->default(0);
            $t->unsignedBigInteger('applied_by')->nullable();
            $t->dateTime('applied_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void {}

    public function ensure(): void
    {
        if (!self::$ready) { $this->up(); self::$ready = true; }
    }

    public function get(int $publicationId): ?object
    {
        $this->ensure();
        return DB::table(self::TABLE)->where('publication_id', $publicationId)->first();
    }

    public function all(int $contextId): array
    {
        $this->ensure();
        return DB::table(self::TABLE)->where('context_id', $contextId)->get()->keyBy('publication_id')->all();
    }

    public function save(int $publicationId, int $contextId, array $values): void
    {
        $this->ensure();
        DB::table(self::TABLE)->updateOrInsert(['publication_id' => $publicationId], $values + ['context_id' => $contextId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
}
