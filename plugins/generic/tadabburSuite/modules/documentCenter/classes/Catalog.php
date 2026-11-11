<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/documentCenter/classes/Catalog.php
 * فهرس أنواع المستندات وأهليتها وإعداداتها، والألقاب، والمستلمون.
 */
namespace APP\plugins\generic\tadabburSuite\m\documentCenter\classes;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\Modules;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;

class Catalog
{
    /**
     * النوع => [الجمهور، النطاق (review|submission)، الصنف (cert|pdf|letter|kit)، الاختصار، افتراضيًا: يستخرجه المستلم بنفسه؟]
     * cert = شهادة المحكّم الموثّقة (reviewerCertificate)، letter = خطابات officialLetters، pdf = يولّده المركز، kit = نماذج HTML تفاعلية/مطبوعة.
     */
    public const TYPES = [
        'rev_forms'       => ['reviewer', 'review', 'kit', 'FRM', true],
        'rev_invite'      => ['reviewer', 'review', 'kit', 'INV', true],
        'rev_certificate' => ['reviewer', 'review', 'cert', 'CRT', false],
        'rev_thanks'      => ['reviewer', 'review', 'pdf', 'THX', false],
        'au_receipt'      => ['author', 'submission', 'letter', 'RCP', true],
        'au_acceptance'   => ['author', 'submission', 'letter', 'ACC', true],
        'au_publication'  => ['author', 'submission', 'letter', 'PUB', true],
        'au_apology'      => ['author', 'submission', 'pdf', 'APL', false],
        'au_thanks'       => ['author', 'submission', 'pdf', 'ATH', false],
        'au_reviews'      => ['author', 'submission', 'pdf', 'RVW', false],
        'au_revision'     => ['author', 'submission', 'kit', 'REV', false],
        'au_matrix'       => ['author', 'submission', 'kit', 'MTX', false],
    ];

    public static function aud(string $type): string { return self::TYPES[$type][0]; }
    public static function scope(string $type): string { return self::TYPES[$type][1]; }
    public static function kind(string $type): string { return self::TYPES[$type][2]; }
    public static function abbr(string $type): string { return self::TYPES[$type][3]; }
    public static function ofAudience(string $aud): array { return array_keys(array_filter(self::TYPES, fn ($t) => $t[0] === $aud)); }
    public static function valid(string $type): bool { return isset(self::TYPES[$type]); }

    // ---------------------------------------------------------------- الإعدادات
    /** @return array{enabled:bool,self:bool,auto:bool,lang:string,subject:string,body:string} */
    public static function cfg($plugin, int $cid, string $type): array
    {
        $raw = $plugin->getSetting($cid, 't_' . $type);
        $v = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        return ['enabled' => (bool) ($v['enabled'] ?? true), 'self' => (bool) ($v['self'] ?? self::TYPES[$type][4]), 'auto' => (bool) ($v['auto'] ?? false),
            'lang' => ($v['lang'] ?? 'ar') === 'en' ? 'en' : 'ar', 'subject' => (string) ($v['subject'] ?? ''), 'body' => (string) ($v['body'] ?? '')];
    }

    public static function saveCfg($plugin, int $cid, string $type, array $v): void
    {
        $plugin->updateSetting($cid, 't_' . $type, json_encode($v, JSON_UNESCAPED_UNICODE), 'string');
    }

    /** إعدادات عامة للمركز */
    public static function general($plugin, int $cid): array
    {
        $g = fn ($k, $d) => ($x = $plugin->getSetting($cid, $k)) === null ? $d : $x;
        return ['signature' => (bool) $g('signature', true), 'cc' => (string) $g('cc', ''), 'prefix' => (string) $g('prefix', 'TDB'), 'attach' => (bool) $g('attach', true)];
    }

    // ---------------------------------------------------------------- الألقاب
    public static function honorifics(): array
    {
        static $c = null;
        if ($c === null) {
            $f = Modules::root() . '/reviewerCertificate/config.php';
            $cfg = is_file($f) ? (require $f) : [];
            $c = ['list' => $cfg['honorifics'] ?? ['none' => '', 'dr_m' => 'الدكتور', 'dr_f' => 'الدكتورة'], 'fem' => $cfg['feminine_honorifics'] ?? ['dr_f'],
                'dm' => $cfg['default_honorific_male'] ?? 'dr_m', 'df' => $cfg['default_honorific_female'] ?? 'dr_f'];
        }
        return $c;
    }

    public static function honorificLabel(string $key): string { return (string) (self::honorifics()['list'][$key] ?? ''); }
    public static function isFeminine(string $key, $user = null): bool
    {
        if (in_array($key, self::honorifics()['fem'], true)) return true;
        return $key === 'none' && $user && strtoupper((string) $user->getData('gender')) === 'F';
    }

    public static function guessHonorific($user): string
    {
        $sal = mb_strtolower((string) $user->getData('salutation'));
        $f = strtoupper((string) $user->getData('gender')) === 'F';
        if ($sal !== '' && (str_contains($sal, 'prof') || str_contains($sal, 'أ.د') || str_contains($sal, 'أستاذ'))) return $f ? 'prof_f' : 'prof_m';
        if ($sal !== '' && (str_contains($sal, 'dr') || str_contains($sal, 'د.'))) return $f ? 'dr_f' : 'dr_m';
        return 'none';
    }

    // ---------------------------------------------------------------- مهام التحكيم والأهلية
    public static function review(int $cid, int $rid): ?object
    {
        return DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $cid)->where('ra.review_id', $rid)->where('ra.cancelled', 0)->select('ra.*')->first();
    }

    public static function reviewsOf(int $cid, int $sid): array
    {
        return DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $cid)->where('ra.submission_id', $sid)->where('ra.cancelled', 0)->where('ra.declined', 0)->select('ra.*')->orderBy('ra.review_id')->get()->all();
    }

    public static function reviewsByReviewer(int $cid, int $uid): array
    {
        return DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $cid)->where('ra.reviewer_id', $uid)->where('ra.cancelled', 0)->where('ra.declined', 0)->select('ra.*')->orderByDesc('ra.review_id')->limit(200)->get()->all();
    }

    public static function completed(?object $r): bool { return $r && $r->date_completed && !(int) $r->declined && !(int) $r->cancelled; }

    public static function declined($sub): bool
    {
        if ((int) $sub->getData('status') === 4) return true;
        return false;
    }

    public static function hasDecision(int $sid, array $decisions): bool { return DB::table('edit_decisions')->where('submission_id', $sid)->whereIn('decision', $decisions)->exists(); }

    public static function reviewComments(int $reviewId): array
    {
        return DB::table('submission_comments')->where('comment_type', 1)->where('assoc_id', $reviewId)->where('viewable', 1)->orderBy('comment_id')->pluck('comments')->all();
    }

    /** هل يصلح النوع لهذا الهدف الآن؟ يعيد null إن صلح، أو مفتاح سبب عدم الأهلية */
    public static function why(string $type, $sub, ?object $review = null): ?string
    {
        if (!self::valid($type)) return 'unknown';
        if (self::scope($type) === 'review') {
            if (!$review) return 'noReview';
            if (in_array($type, ['rev_certificate', 'rev_thanks'], true) && !self::completed($review)) return 'notCompleted';
            return null;
        }
        $sid = (int) $sub->getId();
        $submitted = (string) $sub->getData('submissionProgress') === '' && $sub->getData('dateSubmitted');
        switch ($type) {
            case 'au_receipt': return $submitted ? null : 'notSubmitted';
            case 'au_acceptance': return \APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Letters::acceptedDate($sub) !== null && (int) $sub->getData('status') !== 4 ? null : 'notAccepted';
            case 'au_publication': return (int) $sub->getData('status') === 3 ? null : 'notPublished';
            case 'au_apology': return self::declined($sub) ? null : 'notDeclined';
            case 'au_thanks': return (\APP\plugins\generic\tadabburSuite\m\officialLetters\classes\Letters::acceptedDate($sub) !== null || (int) $sub->getData('status') === 3) && (int) $sub->getData('status') !== 4 ? null : 'notAccepted';
            case 'au_reviews':
                foreach (DB::table('review_assignments')->where('submission_id', $sid)->where('cancelled', 0)->where('declined', 0)->whereNotNull('date_completed')->pluck('review_id') as $rid) if (self::reviewComments((int) $rid)) return null;
                return 'noReviews';
            case 'au_revision': return self::hasDecision($sid, [4, 5]) ? null : 'noRevision';
            case 'au_matrix': return DB::table('review_assignments')->where('submission_id', $sid)->where('cancelled', 0)->where('declined', 0)->whereNotNull('date_completed')->exists() ? null : 'noReviews';
        }
        return 'unknown';
    }

    // ---------------------------------------------------------------- المستلمون
    public static function user(int $id) { static $c = []; return $c[$id] ??= Repo::user()->get($id, true); }

    /** مستخدمو OJS الذين لهم دور مؤلف في البحث: [[id, name, email]] */
    public static function authorUsers(int $sid): array
    {
        $ids = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $sid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->pluck('sa.user_id')->unique()->all();
        $out = [];
        foreach ($ids as $id) if ($u = self::user((int) $id)) $out[] = ['id' => (int) $u->getId(), 'name' => $u->getFullName(true, false, 'ar'), 'email' => (string) $u->getEmail()];
        return $out;
    }

    /** الباحث المخاطَب (المراسل أو الأول) */
    public static function primaryAuthor($sub): array
    {
        $pub = $sub->getCurrentPublication();
        $a = $pub?->getPrimaryAuthor() ?? $pub?->getData('authors')?->first();
        if (!$a) return ['name' => '', 'name_en' => '', 'email' => ''];
        return ['name' => $a->getFullName(true, false, 'ar'), 'name_en' => $a->getFullName(true, false, 'en'), 'email' => (string) $a->getData('email')];
    }

    public static function isAuthorOf(int $sid, int $uid): bool
    {
        return DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $sid)->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->exists();
    }

    public static function authorSubmissions(int $cid, int $uid): array
    {
        return DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
            ->where('s.context_id', $cid)->where('sa.user_id', $uid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->orderByDesc('s.submission_id')->pluck('s.submission_id')->unique()->map(fn ($x) => (int) $x)->all();
    }

    public static function title($sub, string $lang = 'ar'): string
    {
        $pub = $sub->getCurrentPublication();
        return $pub ? trim(strip_tags((string) ($pub->getData('title', $lang) ?: $pub->getLocalizedFullTitle()))) : '';
    }
}
