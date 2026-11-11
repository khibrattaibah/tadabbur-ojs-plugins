<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * جدول سجل الشهادات. يُنشأ عند التثبيت، ويُرقّى تلقائيًا إن وُجد جدول الإصدار 1.1 (Import/Export)
 * دون حذف أي سجل سابق.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CertificateMigration extends Migration
{
    public const TABLE = 'reviewer_certificate_records';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('code', 90)->unique();
                $t->unsignedBigInteger('context_id')->index();
                $t->unsignedBigInteger('review_id');
                $t->unsignedBigInteger('submission_id');
                $t->unsignedBigInteger('reviewer_id');
                $t->string('reviewer_name', 320);
                $t->string('honorific', 120)->default('');
                $t->unsignedInteger('revision')->default(1);
                $t->unsignedBigInteger('issued_by');
                $t->dateTime('issued_at');
                $t->string('cert_date', 20)->nullable();
                $t->string('status', 12)->default('valid');
                $t->dateTime('revoked_at')->nullable();
                $t->unsignedBigInteger('revoked_by')->nullable();
                $t->string('revoke_reason', 500)->nullable();
                $t->string('replaces', 90)->nullable();
                $t->string('replaced_by', 90)->nullable();
                $t->string('email_status', 24)->default('not_sent');
                $t->string('email_address', 320)->nullable();
                $t->dateTime('last_email_attempt_at')->nullable();
                $t->dateTime('email_accepted_at')->nullable();
                $t->unsignedInteger('email_attempts')->default(0);
                $t->string('email_error', 500)->nullable();
                $t->unique(['context_id', 'review_id', 'revision'], 'rc_ctx_review_rev_unique');
                $t->index(['context_id', 'submission_id'], 'rc_submission_idx');
                $t->index(['context_id', 'reviewer_id'], 'rc_reviewer_idx');
            });
            return;
        }

        // ترقية جدول الإصدار 1.1.x
        $add = [
            'revision' => fn (Blueprint $t) => $t->unsignedInteger('revision')->default(1),
            'cert_date' => fn (Blueprint $t) => $t->string('cert_date', 20)->nullable(),
            'revoked_by' => fn (Blueprint $t) => $t->unsignedBigInteger('revoked_by')->nullable(),
            'revoke_reason' => fn (Blueprint $t) => $t->string('revoke_reason', 500)->nullable(),
            'replaces' => fn (Blueprint $t) => $t->string('replaces', 90)->nullable(),
            'replaced_by' => fn (Blueprint $t) => $t->string('replaced_by', 90)->nullable(),
        ];
        foreach ($add as $col => $def) {
            if (!Schema::hasColumn(self::TABLE, $col)) {
                Schema::table(self::TABLE, function (Blueprint $t) use ($def): void { $def($t); });
            }
        }
        if (Schema::hasIndex(self::TABLE, 'rc_context_review_unique')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropUnique('rc_context_review_unique'));
        }
        if (!Schema::hasIndex(self::TABLE, 'rc_ctx_review_rev_unique')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unique(['context_id', 'review_id', 'revision'], 'rc_ctx_review_rev_unique'));
        }
    }

    public function down(): void
    {
        // لا يُحذف سجل الشهادات تلقائيًا حفاظًا على حجية الشهادات الصادرة.
    }
}
