<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * سجل الشهادات الدائم والمقيّد بسياق المجلة. طلبات التحقق العامة لا تُعدّل قاعدة البيانات.
 */
namespace APP\plugins\generic\tadabburSuite\m\reviewerCertificate\classes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CertificateRepository
{
    private const TABLE = CertificateMigration::TABLE;
    private static bool $ready = false;

    /** يضمن وجود الجدول وأعمدته (احتياط إن لم تُنفَّذ الترحيلة عند الرفع) */
    public function ensureSchema(): void
    {
        if (self::$ready) return;
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'revision') || !Schema::hasColumn(self::TABLE, 'replaced_by')) {
            (new CertificateMigration())->up();
        }
        self::$ready = true;
    }

    public function byCode(int $contextId, string $code): ?object
    {
        return DB::table(self::TABLE)->where('context_id', $contextId)->where('code', $code)->first();
    }

    /** أحدث إصدار من شهادة مهمة التحكيم */
    public function current(int $contextId, int $reviewId): ?object
    {
        return DB::table(self::TABLE)->where('context_id', $contextId)->where('review_id', $reviewId)
            ->orderByDesc('revision')->first();
    }

    /** @return array<int,object> مفهرسة بـ review_id (أحدث إصدار) */
    public function currentForContext(int $contextId): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('context_id', $contextId)->orderBy('revision')->get() as $r) {
            $out[(int) $r->review_id] = $r;
        }
        return $out;
    }

    public function byReviewer(int $contextId, int $reviewerId): array
    {
        return DB::table(self::TABLE)->where('context_id', $contextId)->where('reviewer_id', $reviewerId)
            ->where('status', 'valid')->orderByDesc('issued_at')->get()->all();
    }

    public function counts(int $contextId): array
    {
        $q = DB::table(self::TABLE)->where('context_id', $contextId);
        return [
            'valid' => (clone $q)->where('status', 'valid')->count(),
            'revoked' => (clone $q)->where('status', 'revoked')->count(),
            'sent' => (clone $q)->where('status', 'valid')->where('email_status', 'accepted')->count(),
        ];
    }

    public static function newCode(string $prefix): string
    {
        $prefix = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($prefix)) ?: 'JOURNAL', 0, 20);
        return $prefix . '-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(16)));
    }

    /** يصدر شهادة لمهمة لا شهادة سارية لها؛ وإن وُجدت يعيدها دون تكرار */
    public function issue(int $contextId, object $review, string $reviewerName, string $honorific, int $issuedBy, string $prefix, string $certDate, ?object $replacing = null): object
    {
        $existing = $this->current($contextId, (int) $review->review_id);
        if ($existing && $existing->status === 'valid' && !$replacing) return $existing;
        $revision = $existing ? ((int) $existing->revision + 1) : 1;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $code = self::newCode($prefix);
            try {
                DB::table(self::TABLE)->insert([
                    'code' => $code, 'context_id' => $contextId, 'review_id' => (int) $review->review_id,
                    'submission_id' => (int) $review->submission_id, 'reviewer_id' => (int) $review->reviewer_id,
                    'reviewer_name' => mb_substr($reviewerName, 0, 320), 'honorific' => mb_substr($honorific, 0, 120),
                    'revision' => $revision, 'issued_by' => $issuedBy, 'issued_at' => gmdate('Y-m-d H:i:s'),
                    'cert_date' => $certDate, 'status' => 'valid', 'replaces' => $replacing?->code,
                    'email_status' => 'not_sent', 'email_attempts' => 0,
                ]);
                if ($replacing) {
                    DB::table(self::TABLE)->where('context_id', $contextId)->where('code', $replacing->code)->update(['replaced_by' => $code]);
                }
                return $this->byCode($contextId, $code);
            } catch (\Illuminate\Database\QueryException $e) {
                $again = $this->current($contextId, (int) $review->review_id);
                if ($again && $again->status === 'valid' && (int) $again->revision >= $revision) return $again;
                if ($attempt === 3) throw $e;
            }
        }
        throw new \RuntimeException('تعذر إصدار الشهادة.');
    }

    public function revoke(int $contextId, string $code, int $by, string $reason): void
    {
        DB::table(self::TABLE)->where('context_id', $contextId)->where('code', $code)->where('status', 'valid')
            ->update(['status' => 'revoked', 'revoked_at' => gmdate('Y-m-d H:i:s'), 'revoked_by' => $by, 'revoke_reason' => mb_substr($reason, 0, 500)]);
    }

    public function markEmailAttempt(int $contextId, string $code, string $email, string $status, ?string $error = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        DB::table(self::TABLE)->where('context_id', $contextId)->where('code', $code)->update([
            'email_attempts' => DB::raw('email_attempts + 1'), 'email_address' => $email,
            'last_email_attempt_at' => $now, 'email_accepted_at' => $status === 'accepted' ? $now : null,
            'email_status' => $status, 'email_error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }
}
