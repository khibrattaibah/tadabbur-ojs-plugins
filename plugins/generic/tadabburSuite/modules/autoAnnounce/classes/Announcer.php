<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/autoAnnounce/classes/Announcer.php
 * إنشاء إعلانات OJS ونصوص وسائل التواصل، مع سجل يمنع التكرار.
 */
namespace APP\plugins\generic\tadabburSuite\m\autoAnnounce\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\announcement\Announcement;
use PKP\core\PKPApplication;

class Announcer extends Migration
{
    public const T = 'auto_announce_log';

    public function up(): void
    {
        if (!Schema::hasTable(self::T)) Schema::create(self::T, function (Blueprint $t): void {
            $t->bigInteger('context_id');
            $t->string('kind', 20);
            $t->bigInteger('ref_id');
            $t->bigInteger('announcement_id')->nullable();
            $t->dateTime('created_at');
            $t->unique(['context_id', 'kind', 'ref_id'], 'auto_announce_log_pk');
        });
    }

    public function down(): void {}

    public static function done(int $cid, string $kind, int $ref): ?object { return DB::table(self::T)->where('context_id', $cid)->where('kind', $kind)->where('ref_id', $ref)->first(); }

    private function t($plugin, string $k, array $p, string $locale): string { return __($plugin->prefix() . '.' . $k, $p, $locale); }

    private function locales($ctx): array { return array_values(array_intersect(['ar', 'en'], (array) $ctx->getSupportedFormLocales())) ?: [$ctx->getPrimaryLocale()]; }

    private function save($ctx, string $kind, int $ref, array $title, array $short, array $desc): ?int
    {
        if (self::done((int) $ctx->getId(), $kind, $ref)) return null;
        $a = Announcement::create(['assocType' => Application::get()->getContextAssocType(), 'assocId' => (int) $ctx->getId(), 'title' => $title, 'descriptionShort' => $short, 'description' => $desc]);
        DB::table(self::T)->insert(['context_id' => (int) $ctx->getId(), 'kind' => $kind, 'ref_id' => $ref, 'announcement_id' => (int) $a->id, 'created_at' => date('Y-m-d H:i:s')]);
        return (int) $a->id;
    }

    public static function articleIds($issue): array
    {
        return DB::table('publications as p')->join('submissions as s', 's.current_publication_id', '=', 'p.publication_id')->leftJoin('sections as sec', 'sec.section_id', '=', 'p.section_id')
            ->where('p.issue_id', $issue->getId())->where('p.status', 3)->orderBy('sec.seq')->orderBy('p.seq')->pluck('s.submission_id')->map(fn ($x) => (int) $x)->all();
    }

    public function issue($plugin, $ctx, $request, $issue, bool $force = false): ?int
    {
        if ($force) DB::table(self::T)->where('context_id', $ctx->getId())->where('kind', 'issue')->where('ref_id', $issue->getId())->delete();
        $url = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $ctx->getPath(), 'issue', 'view', [$issue->getBestIssueId()]);
        $j = Meta::journal($ctx); $title = []; $short = []; $desc = [];
        $metas = array_filter(array_map(fn ($id) => ($s = Repo::submission()->get($id)) ? Meta::snapshot($s, $ctx, $request) : null, self::articleIds($issue)));
        foreach ($this->locales($ctx) as $l) {
            $o = $l === 'ar' ? ['ar', 'en'] : ['en', 'ar'];
            $p = ['v' => $issue->getVolume(), 'n' => $issue->getNumber(), 'y' => $issue->getYear(), 'j' => Meta::pick($j['names'], $o), 'c' => count($metas)];
            $title[$l] = $this->t($plugin, 'tpl.issueTitle', $p, $l);
            $short[$l] = '<p>' . htmlspecialchars($this->t($plugin, 'tpl.issueShort', $p, $l)) . '</p><p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($this->t($plugin, 'tpl.readIssue', [], $l)) . '</a></p>';
            $list = '';
            foreach ($metas as $m) $list .= '<li><a href="' . htmlspecialchars($m['url']) . '">' . htmlspecialchars(Meta::pick($m['titles'], $o)) . '</a> — ' . htmlspecialchars(implode($l === 'ar' ? '، ' : ', ', array_map(fn ($a) => Meta::pick($a['names'], $o), $m['authors']))) . '</li>';
            $desc[$l] = $short[$l] . ($list ? '<h3>' . htmlspecialchars($this->t($plugin, 'tpl.toc', [], $l)) . '</h3><ol>' . $list . '</ol>' : '');
        }
        return $this->save($ctx, 'issue', (int) $issue->getId(), $title, $short, $desc);
    }

    public function article($plugin, $ctx, $request, $sub, bool $force = false): ?int
    {
        if ($force) DB::table(self::T)->where('context_id', $ctx->getId())->where('kind', 'article')->where('ref_id', $sub->getId())->delete();
        $m = Meta::snapshot($sub, $ctx, $request); $j = Meta::journal($ctx); $title = []; $short = []; $desc = [];
        foreach ($this->locales($ctx) as $l) {
            $o = $l === 'ar' ? ['ar', 'en'] : ['en', 'ar'];
            $p = ['t' => Meta::pick($m['titles'], $o), 'a' => implode($l === 'ar' ? '، ' : ', ', array_map(fn ($a) => Meta::pick($a['names'], $o), $m['authors'])), 'j' => Meta::pick($j['names'], $o)];
            $title[$l] = $this->t($plugin, 'tpl.articleTitle', $p, $l);
            $short[$l] = '<p>' . htmlspecialchars($this->t($plugin, 'tpl.articleShort', $p, $l)) . '</p><p><a href="' . htmlspecialchars($m['url']) . '">' . htmlspecialchars($this->t($plugin, 'tpl.readArticle', [], $l)) . '</a></p>';
            $desc[$l] = $short[$l] . (!empty($m['abstracts'][$l]) ? '<p>' . nl2br(htmlspecialchars($m['abstracts'][$l])) . '</p>' : '');
        }
        return $this->save($ctx, 'article', (int) $sub->getId(), $title, $short, $desc);
    }

    /** نصوص وسائل التواصل */
    public static function social(array $m, array $j, string $tags, bool $ar = true): array
    {
        $o = $ar ? ['ar', 'en'] : ['en', 'ar'];
        $title = Meta::pick($m['titles'], $o); $jn = Meta::pick($j['names'], $o);
        $auth = implode($ar ? '، ' : ', ', array_map(fn ($a) => Meta::pick($a['names'], $o), $m['authors']));
        $link = $m['doi'] ? 'https://doi.org/' . $m['doi'] : $m['url'];
        $x = ($ar ? '📄 بحث جديد في ' : '📄 New in ') . $jn . ":\n«" . $title . "»\n" . $auth . "\n" . $link . "\n" . $tags;
        if (mb_strlen($x) > 280) $x = ($ar ? '📄 بحث جديد: «' : '📄 New paper: "') . mb_strimwidth($title, 0, 150, '…') . ($ar ? '»' : '"') . "\n" . $link . "\n" . $tags;
        $abs = Meta::pick($m['abstracts'], $o);
        $li = ($ar ? 'يسرّ ' . $jn . ' أن تعلن نشر بحث جديد بعنوان:' : $jn . ' is pleased to announce a new paper:') . "\n«" . $title . "»\n" . ($ar ? 'للباحث/الباحثين: ' : 'by ') . $auth . "\n\n" . mb_strimwidth($abs, 0, 600, '…') . "\n\n" . ($ar ? 'للقراءة والتنزيل: ' : 'Read and download: ') . $link . "\n\n" . $tags;
        $wa = '*' . $title . "*\n" . $auth . "\n" . $jn . "\n" . $link;
        return ['x' => $x, 'linkedin' => $li, 'whatsapp' => $wa];
    }
}
