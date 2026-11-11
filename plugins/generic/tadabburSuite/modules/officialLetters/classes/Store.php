<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/officialLetters/classes/Store.php
 * سجل الخطابات الصادرة: رقم صادر متسلسل، ورمز تحقق عشوائي، ولقطة ثابتة من بيانات البحث وقت الإصدار.
 */
namespace APP\plugins\generic\tadabburSuite\m\officialLetters\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const TABLE = 'official_letters';
    public const TYPES = ['receipt' => 'R', 'acceptance' => 'A', 'publication' => 'P'];

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) return;
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->bigIncrements('letter_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id');
            $t->string('letter_type', 20);
            $t->string('lang', 5)->default('ar');
            $t->string('number', 40);
            $t->string('code', 40)->unique('official_letters_code');
            $t->longText('data');
            $t->bigInteger('issued_by')->nullable();
            $t->dateTime('issued_at');
            $t->dateTime('revoked_at')->nullable();
            $t->string('revoke_reason', 255)->default('');
            $t->index(['context_id', 'submission_id'], 'official_letters_sub');
        });
    }

    public function down(): void {} // الخطابات الصادرة وثائق رسمية لا تُحذف

    public function add(int $cid, int $sid, string $type, string $lang, array $data, ?int $uid): object
    {
        $year = date('Y');
        $prefix = 'TDB/' . self::TYPES[$type] . '/' . $year . '/';
        $seq = (int) DB::table(self::TABLE)->where('context_id', $cid)->where('number', 'like', $prefix . '%')->count() + 1;
        do { $code = strtoupper(bin2hex(random_bytes(10))); } while (DB::table(self::TABLE)->where('code', $code)->exists());
        $id = (int) DB::table(self::TABLE)->insertGetId(['context_id' => $cid, 'submission_id' => $sid, 'letter_type' => $type, 'lang' => $lang,
            'number' => $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT), 'code' => $code, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'issued_by' => $uid, 'issued_at' => date('Y-m-d H:i:s')], 'letter_id');
        return DB::table(self::TABLE)->where('letter_id', $id)->first();
    }

    /** آخر خطاب ساري من النوع واللغة لبحث */
    public function current(int $cid, int $sid, string $type, string $lang): ?object
    {
        return DB::table(self::TABLE)->where('context_id', $cid)->where('submission_id', $sid)->where('letter_type', $type)->where('lang', $lang)
            ->whereNull('revoked_at')->orderBy('letter_id', 'desc')->first();
    }

    public function forSubmission(int $cid, int $sid): array
    {
        return DB::table(self::TABLE)->where('context_id', $cid)->where('submission_id', $sid)->orderBy('letter_id', 'desc')->get()->all();
    }

    public function byCode(string $code): ?object
    {
        return DB::table(self::TABLE)->where('code', strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $code)))->first();
    }

    public function get(int $id, int $cid): ?object
    {
        return DB::table(self::TABLE)->where('letter_id', $id)->where('context_id', $cid)->first();
    }

    public function revoke(int $id, int $cid, string $reason): void
    {
        DB::table(self::TABLE)->where('letter_id', $id)->where('context_id', $cid)->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoke_reason' => mb_substr($reason, 0, 250)]);
    }

    public function recent(int $cid, int $limit = 50): array
    {
        return DB::table(self::TABLE)->where('context_id', $cid)->orderBy('letter_id', 'desc')->limit($limit)->get()->all();
    }
}
