<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/integrityLog/classes/Store.php
 * جداول: الحالات (سرية)، وأحداث كل حالة (الخط الزمني)، والإشعارات العامة (تصحيح، قلق، سحب).
 */
namespace APP\plugins\generic\tadabburSuite\m\integrityLog\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Store extends Migration
{
    public const CASES = 'integrity_cases', EVENTS = 'integrity_case_events', NOTICES = 'integrity_notices';

    public const TYPES = ['plagiarism', 'duplicate', 'authorship', 'fabrication', 'reviewerMisconduct', 'coi', 'citationManipulation', 'paperMill', 'ethics', 'other'];
    public const SOURCES = ['reader', 'reviewer', 'editor', 'author', 'institution', 'screening', 'anonymous'];
    public const STATUSES = ['open', 'investigating', 'awaitingResponse', 'referred', 'closed'];
    public const OUTCOMES = ['', 'noAction', 'educated', 'correction', 'concern', 'retraction', 'rejected', 'referred', 'other'];
    public const NOTICE_TYPES = ['correction', 'concern', 'retraction', 'removal'];

    public function up(): void
    {
        if (!Schema::hasTable(self::CASES)) {
            Schema::create(self::CASES, function (Blueprint $t): void {
                $t->bigIncrements('case_id');
                $t->bigInteger('context_id');
                $t->bigInteger('submission_id')->nullable();
                $t->string('title', 255);
                $t->string('case_type', 40);
                $t->string('source', 40)->default('editor');
                $t->string('status', 30)->default('open');
                $t->string('outcome', 30)->default('');
                $t->bigInteger('assigned_to')->nullable();
                $t->bigInteger('created_by')->nullable();
                $t->text('summary')->nullable();
                $t->text('steps')->nullable();      // JSON: خطوات COPE المنجزة
                $t->dateTime('date_opened');
                $t->dateTime('date_updated');
                $t->dateTime('date_closed')->nullable();
                $t->index(['context_id', 'status'], 'integrity_cases_ctx');
            });
        }
        if (!Schema::hasTable(self::EVENTS)) {
            Schema::create(self::EVENTS, function (Blueprint $t): void {
                $t->bigIncrements('event_id');
                $t->bigInteger('case_id');
                $t->bigInteger('user_id')->nullable();
                $t->string('kind', 30)->default('note');
                $t->text('body');
                $t->dateTime('date_created');
                $t->index(['case_id'], 'integrity_events_case');
            });
        }
        if (!Schema::hasTable(self::NOTICES)) {
            Schema::create(self::NOTICES, function (Blueprint $t): void {
                $t->bigIncrements('notice_id');
                $t->bigInteger('context_id');
                $t->bigInteger('submission_id');
                $t->bigInteger('case_id')->nullable();
                $t->string('notice_type', 30);
                $t->date('date_notice');
                $t->text('body');                    // JSON متعدد اللغات {"ar": "...", "en": "..."}
                $t->string('notice_doi', 255)->default('');
                $t->smallInteger('is_public')->default(1);
                $t->dateTime('date_created');
                $t->index(['context_id', 'submission_id'], 'integrity_notices_sub');
            });
        }
    }

    public function down(): void {} // سجل النزاهة لا يُحذف آليًا

    // ---------------------------------------------------------------- الحالات
    public function cases(int $cid, ?string $status = null): array
    {
        $q = DB::table(self::CASES)->where('context_id', $cid)->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")->orderBy('date_updated', 'desc');
        if ($status === 'active') $q->where('status', '<>', 'closed'); elseif ($status) $q->where('status', $status);
        return $q->get()->all();
    }

    public function getCase(int $id, int $cid): ?object
    {
        return DB::table(self::CASES)->where('case_id', $id)->where('context_id', $cid)->first();
    }

    public function addCase(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) DB::table(self::CASES)->insertGetId($row + ['date_opened' => $now, 'date_updated' => $now, 'status' => 'open', 'outcome' => '', 'steps' => '[]'], 'case_id');
    }

    public function updateCase(int $id, int $cid, array $row): void
    {
        $row['date_updated'] = date('Y-m-d H:i:s');
        DB::table(self::CASES)->where('case_id', $id)->where('context_id', $cid)->update($row);
    }

    public function events(int $caseId): array
    {
        return DB::table(self::EVENTS)->where('case_id', $caseId)->orderBy('date_created', 'desc')->orderBy('event_id', 'desc')->get()->all();
    }

    public function addEvent(int $caseId, ?int $userId, string $kind, string $body): void
    {
        DB::table(self::EVENTS)->insert(['case_id' => $caseId, 'user_id' => $userId, 'kind' => $kind, 'body' => $body, 'date_created' => date('Y-m-d H:i:s')]);
    }

    // ---------------------------------------------------------------- الإشعارات
    public function notices(int $cid, ?int $submissionId = null, bool $publicOnly = false): array
    {
        $q = DB::table(self::NOTICES)->where('context_id', $cid)->orderBy('date_notice', 'desc')->orderBy('notice_id', 'desc');
        if ($submissionId) $q->where('submission_id', $submissionId);
        if ($publicOnly) $q->where('is_public', 1);
        return $q->get()->all();
    }

    public function noticesForCase(int $caseId): array
    {
        return DB::table(self::NOTICES)->where('case_id', $caseId)->orderBy('date_notice', 'desc')->get()->all();
    }

    public function addNotice(array $row): int
    {
        return (int) DB::table(self::NOTICES)->insertGetId($row + ['date_created' => date('Y-m-d H:i:s')], 'notice_id');
    }

    public function updateNotice(int $id, int $cid, array $row): void
    {
        DB::table(self::NOTICES)->where('notice_id', $id)->where('context_id', $cid)->update($row);
    }

    public function stats(int $cid): array
    {
        $rows = DB::table(self::CASES)->where('context_id', $cid)->get(['status', 'case_type', 'outcome']);
        return [
            'total' => $rows->count(),
            'active' => $rows->where('status', '<>', 'closed')->count(),
            'closed' => $rows->where('status', 'closed')->count(),
            'byType' => $rows->countBy('case_type')->all(),
            'byOutcome' => $rows->where('status', 'closed')->countBy('outcome')->all(),
            'notices' => DB::table(self::NOTICES)->where('context_id', $cid)->where('is_public', 1)->get(['notice_type'])->countBy('notice_type')->all(),
        ];
    }
}
