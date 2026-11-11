<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/surveys/classes/Engine.php
 * صيغة الأسئلة (سطر لكل سؤال)، والقوالب الافتراضية، والدعوات بالبريد، وتجميع النتائج.
 *
 * صيغة السطر:  [*]النوع | نص السؤال | خيار1; خيار2
 * الأنواع: rating (1–5)، choice (خيار واحد)، multi (عدة خيارات)، yesno، text. النجمة = إجباري.
 */
namespace APP\plugins\generic\tadabburSuite\m\surveys\classes;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\kit\Services;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\security\Role;

class Engine
{
    public const TYPES = ['rating', 'choice', 'multi', 'yesno', 'text'];

    public static function parse(string $src): array
    {
        $out = []; $i = 0;
        foreach (preg_split('/\r\n|\r|\n/', $src) as $line) {
            $line = trim($line); if ($line === '') continue;
            $req = str_starts_with($line, '*'); if ($req) $line = ltrim(substr($line, 1));
            $p = array_map('trim', explode('|', $line, 3));
            $type = strtolower($p[0]);
            if (!in_array($type, self::TYPES, true) || ($p[1] ?? '') === '') continue;
            $opts = isset($p[2]) ? array_values(array_filter(array_map('trim', preg_split('/[;؛]/u', $p[2])))) : [];
            if (in_array($type, ['choice', 'multi'], true) && count($opts) < 2) continue;
            $out[] = ['id' => 'q' . (++$i), 'type' => $type, 'label' => mb_substr($p[1], 0, 400), 'options' => $opts, 'required' => $req];
        }
        return $out;
    }

    public static function unparse(array $qs): string
    {
        return implode("\n", array_map(fn ($q) => ($q['required'] ? '*' : '') . $q['type'] . ' | ' . $q['label'] . ($q['options'] ? ' | ' . implode('; ', $q['options']) : ''), $qs));
    }

    public static function defaults(): array
    {
        return [
            ['title' => 'استبانة رضا الباحثين', 'audience' => 'author', 'trigger_on' => 'decision',
                'intro' => 'شكرًا لتقديمكم بحثكم إلى المجلة. نرجو تخصيص دقيقتين لتقييم تجربتكم؛ فإجاباتكم تُستعمل لتحسين خدماتنا ولا تؤثر في قرار بحثكم.',
                'q' => "*rating | وضوح دليل المؤلفين وسهولة التقديم\n*rating | سرعة الإجراءات ومدة التحكيم\n*rating | جودة ملحوظات المحكّمين وفائدتها\n*rating | التواصل مع أمانة التحرير\n*rating | سهولة استعمال المنصة الإلكترونية\n*choice | هل تنصح زملاءك بالنشر في المجلة؟ | نعم بالتأكيد; ربما; لا\ntext | مقترحاتكم لتحسين خدمات المجلة"],
            ['title' => 'استبانة رضا المحكّمين', 'audience' => 'reviewer', 'trigger_on' => 'review',
                'intro' => 'جزاكم الله خيرًا على تحكيمكم. يسعدنا أن تشاركونا تقييم تجربة التحكيم في المجلة.',
                'q' => "*rating | وضوح خطاب الدعوة ونماذج التحكيم\n*rating | ملاءمة المدة المتاحة للتحكيم\n*rating | سهولة رفع التقرير عبر المنصة\n*yesno | هل ترغبون في التحكيم للمجلة مستقبلًا؟\nmulti | مجالات تخصصكم الدقيق | التفسير وعلومه; علوم القرآن; القراءات; التدبر; الإعجاز; أخرى\ntext | ملحوظات أو مقترحات"],
            ['title' => 'استبانة قرّاء المجلة', 'audience' => 'public', 'trigger_on' => 'open',
                'intro' => 'رأيكم يهمنا في تطوير المجلة ومحتواها.',
                'q' => "*choice | صفتكم | باحث; عضو هيئة تدريس; طالب دراسات عليا; قارئ مهتم\n*rating | جودة البحوث المنشورة\n*rating | سهولة الوصول إلى البحوث وتنزيلها\nmulti | الموضوعات التي تودون قراءتها أكثر | التدبر; التفسير; علوم القرآن; القراءات; المناهج; الدراسات المعاصرة\ntext | اقتراحاتكم"],
        ];
    }

    public static function seed(Store $st, int $cid): void
    {
        if ($st->all($cid)) return;
        foreach (self::defaults() as $d) $st->save($cid, null, ['title' => $d['title'], 'intro' => $d['intro'], 'audience' => $d['audience'], 'trigger_on' => $d['trigger_on'],
            'questions' => json_encode(self::parse($d['q']), JSON_UNESCAPED_UNICODE), 'active' => 1]);
    }

    public static function link($request, $context, object $inv): string
    {
        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'surveys', 'fill', [$inv->token]);
    }

    /** يرسل دعوة (مرة واحدة لكل هدف) */
    public static function send($request, $context, Store $st, object $s, ?int $uid, string $email, string $name, ?int $subId = null, ?int $revId = null): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $inv = $st->invite($s, $uid, $email, $name, $subId, $revId);
        if (!$inv) return false;
        $journal = (string) $context->getLocalizedName();
        $url = self::link($request, $context, $inv);
        $html = '<p>' . htmlspecialchars(__('plugins.generic.surveys.mail.hello', ['name' => $name ?: __('plugins.generic.surveys.mail.colleague')])) . '</p>'
            . '<p>' . htmlspecialchars((string) $s->intro) . '</p>'
            . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#5a3a1e;color:#fff;padding:10px 22px;border-radius:999px;text-decoration:none;font-weight:bold">' . htmlspecialchars(__('plugins.generic.surveys.mail.button')) . '</a></p>'
            . '<p style="color:#666;font-size:13px">' . htmlspecialchars(__('plugins.generic.surveys.mail.note')) . '</p><p>' . htmlspecialchars($journal) . '</p>';
        $ok = Services::mail($context, $email, $name, $s->title . ' — ' . $journal, $html);
        if ($ok) DB::table(Store::I)->where('invite_id', $inv->invite_id)->update(['sent_at' => date('Y-m-d H:i:s')]);
        return $ok;
    }

    /** مستخدمو المؤلفين في بحث */
    public static function authors(int $sid): array
    {
        $out = [];
        foreach (DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')->where('sa.submission_id', $sid)->where('ug.role_id', Role::ROLE_ID_AUTHOR)->pluck('sa.user_id')->unique() as $id)
            if ($u = Repo::user()->get((int) $id, true)) $out[] = [(int) $u->getId(), (string) $u->getEmail(), $u->getFullName()];
        if (!$out && ($sub = Repo::submission()->get($sid)) && ($pub = $sub->getCurrentPublication())) {
            $a = $pub->getPrimaryAuthor() ?? $pub->getData('authors')?->first();
            if ($a && filter_var((string) $a->getData('email'), FILTER_VALIDATE_EMAIL)) $out[] = [null, (string) $a->getData('email'), $a->getFullName()];
        }
        return $out;
    }

    /** تجميع النتائج لكل سؤال */
    public static function aggregate(array $qs, array $rows): array
    {
        $agg = [];
        foreach ($qs as $q) $agg[$q['id']] = ['q' => $q, 'n' => 0, 'sum' => 0, 'dist' => [], 'texts' => []];
        foreach ($rows as $r) {
            $a = json_decode((string) $r->answers, true) ?: [];
            foreach ($qs as $q) {
                $v = $a[$q['id']] ?? null;
                if ($v === null || $v === '' || $v === []) continue;
                $g = &$agg[$q['id']]; $g['n']++;
                if ($q['type'] === 'rating') { $g['sum'] += (int) $v; $g['dist'][(int) $v] = ($g['dist'][(int) $v] ?? 0) + 1; }
                elseif ($q['type'] === 'text') $g['texts'][] = (string) $v;
                else foreach ((array) $v as $x) $g['dist'][(string) $x] = ($g['dist'][(string) $x] ?? 0) + 1;
                unset($g);
            }
        }
        return $agg;
    }

    /** مؤشر الرضا العام: متوسط كل أسئلة التقييم على 100 */
    public static function score(array $agg): ?int
    {
        $s = 0; $n = 0;
        foreach ($agg as $g) if ($g['q']['type'] === 'rating' && $g['n']) { $s += $g['sum']; $n += $g['n']; }
        return $n ? (int) round(($s / $n - 1) / 4 * 100) : null;
    }
}
