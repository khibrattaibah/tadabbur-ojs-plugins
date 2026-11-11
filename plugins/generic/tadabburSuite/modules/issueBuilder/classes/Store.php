<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/issueBuilder/classes/Store.php
 * عدد صفحات كل بحث (لحساب الترقيم)، وبداية ترقيم كل عدد.
 */
namespace APP\plugins\generic\tadabburSuite\m\issueBuilder\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const T = 'issue_builder_pages';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('publication_id');
            $t->integer('page_count')->default(0);
            $t->unique(['publication_id'], 'issue_builder_pages_pk');
        });
    }

    public function down(): void {}

    public function counts(array $pubIds): array { return $pubIds ? DB::table(self::T)->whereIn('publication_id', $pubIds)->pluck('page_count', 'publication_id')->all() : []; }

    public function setCount(int $pubId, int $n): void
    {
        DB::table(self::T)->updateOrInsert(['publication_id' => $pubId], ['page_count' => max(0, $n)]);
    }
}
