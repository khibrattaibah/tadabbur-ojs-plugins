<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/authorResponse/classes/Store.php
 * جدولا الردود وبنودها، واستعلامات الأهلية والصلاحية.
 */
namespace APP\plugins\generic\tadabburSuite\m\authorResponse\classes;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\security\Role;

class Store extends Migration
{
    public const R = 'author_responses';
    public const I = 'author_response_items';
    /** قرارات تستدعي ردًّا: تعديلات مطلوبة، إعادة تقديم */
    public const DECISIONS = [4, 5];
    public const ACTIONS = ['done', 'partial', 'declined'];

    public function up(): void
    {
        if (!Schema::hasTable(self::R)) Schema::create(self::R, function (Blueprint $t): void {
            $t->bigIncrements('response_id');
            $t->bigInteger('context_id');
            $t->bigInteger('submission_id');
            $t->bigInteger('review_round_id');
            $t->integer('round')->default(1);
            $t->bigInteger('user_id')->nullable();
            $t->string('status', 20)->default('draft');
            $t->text('intro')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->unique(['submission_id', 'review_round_id'], 'author_responses_round');
            $t->index(['context_id', 'status'], 'author_responses_ctx');
        });
        if (!Schema::hasTable(self::I)) Schema::create(self::I, function (Blueprint $t): void {
            $t->bigIncrements('item_id');
            $t->bigInteger('response_id');
            $t->bigInteger('review_id')->default(0);
            $t->integer('seq')->default(0);
            $t->text('comment');
            $t->text('reply')->nullable();
            $t->string('location', 255)->default('');
            $t->string('action', 20)->default('done');
            $t->index(['response_id', 'seq'], 'author_response_items_r');
        });
    }

    public function down(): void {}

    /** جولات التحكيم التي صدر فيها قرار تعديل لبحوث هذا المؤلف */
    public function rounds(int $cid, ?int $uid = null, ?int $sid = null): array
    {
        $q = DB::table('edit_decisions as d')->join('review_rounds as rr', 'rr.review_round_id', '=', 'd.review_round_id')->join('submissions as s', 's.submission_id', '=', 'd.submission_id')
            ->where('s.context_id', $cid)->whereIn('d.decision', self::DECISIONS)->whereIn('s.status', [1]);
        if ($sid) $q->where('s.submission_id', $sid);
        if ($uid) $q->whereExists(fn ($x) => $x->select(DB::raw(1))->from('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->whereColumn('sa.submission_id', 's.submission_id')->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR));
        return $q->groupBy('rr.review_round_id', 'rr.submission_id', 'rr.round')->selectRaw('rr.review_round_id, rr.submission_id, rr.round, MAX(d.date_decided) as decided')
            ->orderByDesc('decided')->get()->all();
    }

    public function eligibleFor(int $cid, int $uid): bool { return (bool) $this->rounds($cid, $uid); }

    public function isAuthor(int $sid, int $uid): bool
    {
        return DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $sid)->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->exists();
    }

    /** محكّم في جولة لاحقة لنفس البحث يرى الرد */
    public function isLaterReviewer(int $sid, int $round, int $uid): bool
    {
        return DB::table('review_assignments as ra')->join('review_rounds as rr', 'rr.review_round_id', '=', 'ra.review_round_id')
            ->where('ra.submission_id', $sid)->where('ra.reviewer_id', $uid)->where('rr.round', '>', $round)->where('ra.declined', 0)->exists();
    }

    /** المحكّمون المكتملون في الجولة بترتيب ثابت، مع ملاحظاتهم المرئية للمؤلف */
    public function reviewers(int $roundId): array
    {
        $out = [];
        $ras = DB::table('review_assignments')->where('review_round_id', $roundId)->whereNotNull('date_completed')->where('declined', 0)->orderBy('review_id')->get();
        foreach ($ras as $i => $ra) {
            $comments = DB::table('submission_comments')->where('comment_type', 1)->where('assoc_id', $ra->review_id)->where('viewable', 1)->orderBy('comment_id')->pluck('comments')->all();
            $out[] = ['review_id' => (int) $ra->review_id, 'label' => $i, 'recommendation' => (int) $ra->recommendation, 'comments' => $comments];
        }
        return $out;
    }

    public function get(int $id, int $cid): ?object { return DB::table(self::R)->where('response_id', $id)->where('context_id', $cid)->first(); }
    public function forRound(int $roundId): ?object { return DB::table(self::R)->where('review_round_id', $roundId)->first(); }
    public function items(int $rid): array { return DB::table(self::I)->where('response_id', $rid)->orderBy('review_id')->orderBy('seq')->orderBy('item_id')->get()->all(); }

    public function list(int $cid, ?string $status = null): array
    {
        $q = DB::table(self::R)->where('context_id', $cid);
        if ($status) $q->where('status', $status);
        return $q->orderByDesc('updated_at')->limit(200)->get()->all();
    }

    /** إنشاء الرد مع تقسيم ملاحظات كل محكّم إلى بنود (فقرات أو نقاط مرقمة) */
    public function create(int $cid, object $round, int $uid): int
    {
        $rid = (int) DB::table(self::R)->insertGetId(['context_id' => $cid, 'submission_id' => $round->submission_id, 'review_round_id' => $round->review_round_id,
            'round' => $round->round, 'user_id' => $uid, 'status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')], 'response_id');
        $rows = [];
        foreach ($this->reviewers((int) $round->review_round_id) as $r) {
            $seq = 0;
            foreach ($r['comments'] as $c) foreach (self::split($c) as $p) $rows[] = ['response_id' => $rid, 'review_id' => $r['review_id'], 'seq' => ++$seq, 'comment' => $p, 'reply' => '', 'location' => '', 'action' => 'done'];
        }
        if ($rows) DB::table(self::I)->insert($rows);
        return $rid;
    }

    public static function split(string $html): array
    {
        $t = html_entity_decode(strip_tags(preg_replace(['~</(p|li|div)>~i', '~<br\s*/?>~i'], ["\n\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\r/", '', $t);
        // نقاط مرقمة: 1. / 1) / - / • / (أ)
        $parts = preg_split('/\n\s*\n|\n(?=\s*(?:\d+|[٠-٩]+)\s*[\.\)\-–]|\n?\s*[-•▪]\s)/u', $t) ?: [$t];
        return array_values(array_filter(array_map(fn ($p) => trim(preg_replace('/[ \t]+/u', ' ', $p)), $parts), fn ($p) => mb_strlen($p) > 2));
    }

    public function saveItems(int $rid, array $items, string $intro, ?string $status = null): void
    {
        $ids = DB::table(self::I)->where('response_id', $rid)->pluck('item_id')->all();
        foreach ($items as $it) {
            $d = ['review_id' => (int) $it['review_id'], 'seq' => (int) $it['seq'], 'comment' => $it['comment'], 'reply' => $it['reply'], 'location' => mb_substr($it['location'], 0, 255),
                'action' => in_array($it['action'], self::ACTIONS, true) ? $it['action'] : 'done'];
            if (!empty($it['id']) && in_array((int) $it['id'], $ids, true)) {
                if ($it['delete'] ?? false) DB::table(self::I)->where('item_id', (int) $it['id'])->delete();
                else DB::table(self::I)->where('item_id', (int) $it['id'])->update($d);
            } elseif (trim($d['comment']) !== '' && empty($it['delete'])) DB::table(self::I)->insert($d + ['response_id' => $rid]);
        }
        $u = ['intro' => $intro, 'updated_at' => date('Y-m-d H:i:s')];
        if ($status) { $u['status'] = $status; if ($status === 'submitted') $u['submitted_at'] = date('Y-m-d H:i:s'); }
        DB::table(self::R)->where('response_id', $rid)->update($u);
    }

    public function setStatus(int $rid, string $s): void { DB::table(self::R)->where('response_id', $rid)->update(['status' => $s, 'updated_at' => date('Y-m-d H:i:s')]); }

    public static function stats(array $items): array
    {
        $s = ['total' => count($items), 'answered' => 0, 'done' => 0, 'partial' => 0, 'declined' => 0];
        foreach ($items as $i) { if (trim((string) $i->reply) !== '') $s['answered']++; $s[$i->action] = ($s[$i->action] ?? 0) + 1; }
        return $s;
    }
}
