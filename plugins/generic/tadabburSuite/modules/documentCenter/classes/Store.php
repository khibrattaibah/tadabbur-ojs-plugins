<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/documentCenter/classes/Store.php
 * سجل المستندات الصادرة (وإتاحتها وإرسالها) وتفضيلات الألقاب.
 */
namespace APP\plugins\generic\tadabburSuite\m\documentCenter\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const T = 'tdb_documents';
    public const P = 'tdb_doc_prefs';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigIncrements('doc_id');
            $t->bigInteger('context_id');
            $t->string('doc_type', 30);
            $t->bigInteger('submission_id')->nullable();
            $t->bigInteger('review_id')->nullable();
            $t->bigInteger('user_id')->nullable();
            $t->string('email', 255)->nullable();
            $t->string('honorific', 20)->default('none');
            $t->string('lang', 4)->default('ar');
            $t->string('number', 70)->nullable();
            $t->string('code', 40)->nullable();
            $t->text('data')->nullable();
            $t->text('note')->nullable();
            $t->tinyInteger('released')->default(0);
            $t->string('status', 10)->default('valid');
            $t->bigInteger('issued_by')->nullable();
            $t->dateTime('issued_at');
            $t->integer('sent_count')->default(0);
            $t->dateTime('last_sent_at')->nullable();
            $t->string('last_send_status', 12)->nullable();
            $t->text('last_send_error')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->string('revoke_reason', 500)->nullable();
            $t->index(['context_id', 'doc_type'], 'tdb_documents_ctx');
            $t->index(['submission_id'], 'tdb_documents_sub');
            $t->index(['review_id'], 'tdb_documents_rev');
            $t->index(['code'], 'tdb_documents_code');
        });
        if (!Schema::hasTable(self::P)) Schema::create(self::P, function (Blueprint $t): void {
            $t->bigInteger('user_id')->primary();
            $t->string('honorific', 20)->default('none');
            $t->string('lang', 4)->default('ar');
        });
    }

    public function down(): void {}

    // ---------------------------------------------------------------- قراءة
    public function get(int $id, int $cid): ?object { return DB::table(self::T)->where('doc_id', $id)->where('context_id', $cid)->first(); }
    public function byCode(string $code): ?object { return DB::table(self::T)->where('code', $code)->first(); }

    /** أحدث مستند صالح من النوع لهذا الهدف (بحث أو مهمة تحكيم) */
    public function current(int $cid, string $type, ?int $sid, ?int $rid, string $lang = ''): ?object
    {
        $q = DB::table(self::T)->where('context_id', $cid)->where('doc_type', $type)->where('status', 'valid');
        $rid ? $q->where('review_id', $rid) : $q->where('submission_id', $sid);
        if ($lang !== '') $q->where('lang', $lang);
        return $q->orderByDesc('doc_id')->first();
    }

    public function forSubmission(int $cid, int $sid): array
    {
        return DB::table(self::T)->where('context_id', $cid)->where('submission_id', $sid)->orderByDesc('doc_id')->get()->all();
    }

    public function recent(int $cid, int $limit = 40, ?string $type = null): array
    {
        $q = DB::table(self::T)->where('context_id', $cid)->orderByDesc('doc_id')->limit($limit);
        if ($type) $q->where('doc_type', $type);
        return $q->get()->all();
    }

    public function stats(int $cid): array
    {
        $q = fn () => DB::table(self::T)->where('context_id', $cid);
        return ['issued' => $q()->where('status', 'valid')->count(), 'sent' => $q()->where('last_send_status', 'accepted')->count(),
            'failed' => $q()->where('last_send_status', 'failed')->count(), 'month' => $q()->where('issued_at', '>=', gmdate('Y-m-01 00:00:00'))->count()];
    }

    // ---------------------------------------------------------------- كتابة
    public function nextNumber(int $cid, string $type, string $prefix, string $abbr): string
    {
        $year = gmdate('Y');
        $n = DB::table(self::T)->where('context_id', $cid)->where('doc_type', $type)->where('issued_at', '>=', $year . '-01-01 00:00:00')->count() + 1;
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'TDB') . '-' . $abbr . '-' . $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function add(array $row): object
    {
        $row += ['honorific' => 'none', 'lang' => 'ar', 'released' => 0, 'status' => 'valid', 'issued_at' => gmdate('Y-m-d H:i:s'), 'code' => strtoupper(bin2hex(random_bytes(10)))];
        $id = DB::table(self::T)->insertGetId($row, 'doc_id');
        return DB::table(self::T)->where('doc_id', $id)->first();
    }

    public function release(int $id, bool $on = true): void { DB::table(self::T)->where('doc_id', $id)->update(['released' => $on ? 1 : 0]); }

    public function markSent(int $id, string $to, string $status, ?string $err = null): void
    {
        DB::table(self::T)->where('doc_id', $id)->update(['email' => $to, 'sent_count' => DB::raw('sent_count + 1'), 'last_sent_at' => gmdate('Y-m-d H:i:s'),
            'last_send_status' => $status, 'last_send_error' => $err !== null ? mb_substr($err, 0, 500) : null]);
    }

    public function revoke(int $id, int $cid, string $reason): void
    {
        DB::table(self::T)->where('doc_id', $id)->where('context_id', $cid)->where('status', 'valid')
            ->update(['status' => 'revoked', 'released' => 0, 'revoked_at' => gmdate('Y-m-d H:i:s'), 'revoke_reason' => mb_substr($reason, 0, 500)]);
    }

    // ---------------------------------------------------------------- التفضيلات
    public function pref(int $uid): ?object { return DB::table(self::P)->where('user_id', $uid)->first(); }

    public function setPref(int $uid, string $honorific, string $lang): void
    {
        $has = DB::table(self::P)->where('user_id', $uid)->exists();
        $has ? DB::table(self::P)->where('user_id', $uid)->update(['honorific' => $honorific, 'lang' => $lang]) : DB::table(self::P)->insert(['user_id' => $uid, 'honorific' => $honorific, 'lang' => $lang]);
    }
}
