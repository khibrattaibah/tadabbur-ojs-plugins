<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/jatsExport/pages/JatsHandler.php
 * المحرر: index، article، xml، html ؛ POST: convert، upload، attach (المدير)
 */
namespace APP\plugins\generic\tadabburSuite\m\jatsExport\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\jatsExport\classes\Docx;
use APP\plugins\generic\tadabburSuite\m\jatsExport\classes\Jats;
use APP\plugins\generic\tadabburSuite\m\jatsExport\classes\Render;
use APP\plugins\generic\tadabburSuite\m\jatsExport\classes\Store;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;

class JatsHandler extends ModuleHandler
{
    /** أولوية مراحل الملفات: الجاهز للإنتاج، ثم المحرَّر لغويًا، ثم النهائي، ثم المعدَّل، ثم الأصلي */
    private const STAGES = [11, 9, 6, 15, 4, 2];

    private function sub($request)
    {
        $s = Repo::submission()->get((int) $request->getUserVar('sid'));
        if (!$s || (int) $s->getData('contextId') !== $this->cid($request)) $this->notFound();
        return $s;
    }

    /** ملفات Word للبحث مرتبة بالأولوية */
    private function docxFiles(int $sid): array
    {
        $rows = DB::table('submission_files as sf')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('sf.submission_id', $sid)
            ->whereIn('sf.file_stage', self::STAGES)->where(fn ($q) => $q->where('f.mimetype', 'like', '%wordprocessingml%')->orWhere('f.path', 'like', '%.docx'))
            ->select('sf.submission_file_id', 'sf.file_stage', 'sf.file_id', 'f.path', 'sf.updated_at')->get()->all();
        usort($rows, fn ($a, $b) => [array_search((int) $a->file_stage, self::STAGES), $b->updated_at] <=> [array_search((int) $b->file_stage, self::STAGES), $a->updated_at]);
        return $rows;
    }

    private function build($request, $sub): string
    {
        $ctx = $request->getContext();
        $src = (new Store())->get((int) $sub->getId());
        return (new Jats())->build(Meta::snapshot($sub, $ctx, $request), Meta::journal($ctx), $src['conv'] ?? []);
    }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request);
        $ids = DB::table('submissions')->where('context_id', $cid)->where(fn ($q) => $q->where('status', 3)->orWhere(fn ($x) => $x->where('status', 1)->where('stage_id', '>=', 4)))
            ->orderByDesc('submission_id')->limit(60)->pluck('submission_id')->all();
        $srcs = (new Store())->all($cid); $rows = [];
        foreach ($ids as $id) {
            $s = Repo::submission()->get((int) $id); if (!$s) continue;
            $src = $srcs[$id] ?? null; $st = $src ? (json_decode($src->conv, true)['stats'] ?? []) : [];
            $rows[] = [(string) $id, '<b>' . self::e(mb_strimwidth(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle()), 0, 90, '…')) . '</b>',
                $this->t((int) $s->getData('status') === 3 ? 'published' : 'production'),
                $src ? $this->badge(true, $this->t('bodyStats', ['s' => $st['sections'] ?? 0, 'p' => $st['paragraphs'] ?? 0, 'f' => $st['footnotes'] ?? 0])) . '<div style="font-size:11px;color:#777">' . self::e($src->source_name) . '</div>' : '<span style="color:#888;font-size:12px">' . $this->t('metaOnly') . '</span>',
                $src && $src->attached_at ? $this->badge(true, $this->t('attached')) : '—',
                $this->btn($this->url($request, 'article', ['sid' => $id]), $this->t('openBtn'), true) . $this->btn($this->url($request, 'html', ['sid' => $id]), 'HTML', false, ' target="_blank"') . $this->btn($this->url($request, 'xml', ['sid' => $id]), 'XML')];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request)
            . $this->box('', $this->table(['#', $this->t('col.title'), $this->t('col.state'), $this->t('col.body'), 'OJS JATS', ''], $rows));
        $this->show($request, $this->t('menu'), $h);
    }

    public function article($args, $request)
    {
        $this->editor($request); $sub = $this->sub($request); $sid = (int) $sub->getId(); $mgr = $this->plugin->isManager($request);
        $src = (new Store())->get($sid); $xml = $this->build($request, $sub); $chk = Jats::validate($xml);
        $ok = 0; $req = 0; $list = '';
        foreach ($chk as $k => [$pass, $must]) { if ($must) { $req++; if ($pass) $ok++; }
            $list .= '<div style="display:inline-block;min-width:230px;margin:3px 0">' . ($pass ? '<span style="color:#1e7b4a">✔</span>' : '<span style="color:' . ($must ? '#b02a2a' : '#b07a00') . '">' . ($must ? '✖' : '!') . '</span>') . ' ' . $this->t('v.' . $k) . ($must ? '' : ' <span style="color:#888;font-size:11px">(' . $this->t('optional') . ')</span>') . '</div>'; }
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->flash($request)
            . $this->box(self::e(strip_tags((string) $sub->getCurrentPublication()->getLocalizedFullTitle())) . ' · #' . $sid,
                '<div style="margin-bottom:8px"><b style="font-size:20px">' . $ok . '/' . $req . '</b> ' . $this->t('requiredOk') . '</div>' . $list
                . '<div style="margin-top:10px">' . $this->btn($this->url($request, 'html', ['sid' => $sid]), '👁 ' . $this->t('preview'), true, ' target="_blank"') . $this->btn($this->url($request, 'xml', ['sid' => $sid]), '⬇ JATS XML')
                . $this->btn($this->url($request, 'html', ['sid' => $sid, 'dl' => 1]), '⬇ HTML') . '</div>');
        // المتن
        $files = $this->docxFiles($sid);
        $b = '';
        if ($src) { $st = $src['conv']['stats'] ?? []; $b .= '<p>' . $this->badge(true, self::e($src['name'])) . ' ' . $this->t('statsFull', ['s' => $st['sections'] ?? 0, 'p' => $st['paragraphs'] ?? 0, 't' => $st['tables'] ?? 0, 'f' => $st['footnotes'] ?? 0, 'r' => $st['refs'] ?? 0, 'k' => $st['skipped'] ?? 0]) . '</p>'; }
        else $b .= '<p style="color:#8a5a00">' . $this->t('noBody') . '</p>';
        if ($files) {
            $opts = implode('', array_map(fn ($f) => '<option value="' . (int) $f->submission_file_id . '">' . self::e($this->t('stage.' . $f->file_stage) . ' · ' . basename($f->path) . ' · ' . substr((string) $f->updated_at, 0, 10)) . '</option>', $files));
            $b .= '<form method="post" action="' . self::e($this->url($request, 'convert')) . '" style="margin:6px 0">' . $this->csrf($request) . '<input type="hidden" name="sid" value="' . $sid . '"><select name="fileId" style="' . self::S_INP . '">' . $opts . '</select> <button style="' . self::S_BTNP . '">' . $this->t('convert') . '</button></form>';
        }
        $b .= '<form method="post" enctype="multipart/form-data" action="' . self::e($this->url($request, 'upload')) . '" style="margin:6px 0">' . $this->csrf($request) . '<input type="hidden" name="sid" value="' . $sid . '"><span style="font-size:12px">' . $this->t('orUpload') . '</span> <input type="file" name="docx" accept=".docx" required> <button style="' . self::S_BTN . '">' . $this->t('uploadConvert') . '</button></form>'
            . '<p style="font-size:12px;color:#666">' . $this->t('docxHelp') . '</p>';
        $h .= $this->box($this->t('bodyTitle'), $b);
        if ($mgr) $h .= $this->box($this->t('attachTitle'), '<p style="margin-top:0;font-size:13px">' . $this->t('attachHelp') . '</p>' . ($src && $src['attached'] ? '<p>' . $this->badge(true, $this->t('attachedOn', ['d' => substr($src['attached'], 0, 16)])) . '</p>' : '')
            . $this->postBtn($request, 'attach', ['sid' => $sid], $this->t('attach'), true, $this->t('confirmAttach')));
        $this->show($request, $this->t('menu'), $h);
    }

    private function convertFile($request, $sub, string $path, string $name, ?int $fileId): void
    {
        try { $conv = (new Docx())->convert($path); }
        catch (\Throwable $e) { $this->back($request, 'article', ['sid' => $sub->getId()], 'errDocx'); return; }
        (new Store())->put((int) $sub->getId(), $this->cid($request), $name, $fileId, $conv);
        $this->back($request, 'article', ['sid' => $sub->getId()], 'converted');
    }

    public function convert($args, $request)
    {
        $this->editor($request); $this->postOk($request); $sub = $this->sub($request);
        $fid = (int) $request->getUserVar('fileId'); $row = null;
        foreach ($this->docxFiles((int) $sub->getId()) as $f) if ((int) $f->submission_file_id === $fid) $row = $f;
        if (!$row) $this->notFound();
        $fs = app()->get('file')->fs;
        $tmp = tempnam(sys_get_temp_dir(), 'jx'); file_put_contents($tmp, $fs->read($row->path));
        try { $this->convertFile($request, $sub, $tmp, basename($row->path), $fid); } finally { @unlink($tmp); }
    }

    public function upload($args, $request)
    {
        $this->editor($request); $this->postOk($request); $sub = $this->sub($request);
        $f = $_FILES['docx'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 30 * 1024 * 1024 || !is_uploaded_file($f['tmp_name'])) { $this->back($request, 'article', ['sid' => $sub->getId()], 'errDocx'); return; }
        $this->convertFile($request, $sub, $f['tmp_name'], (string) $f['name'], null);
    }

    public function xml($args, $request)
    {
        $this->editor($request); $sub = $this->sub($request);
        $this->download('jats-' . $sub->getId() . '.xml', 'application/xml; charset=utf-8', $this->build($request, $sub));
    }

    public function html($args, $request)
    {
        $this->editor($request); $sub = $this->sub($request);
        $out = Render::html($this->build($request, $sub));
        if ($request->getUserVar('dl')) $this->download('article-' . $sub->getId() . '.html', 'text/html; charset=utf-8', $out);
        header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex'); echo $out; exit;
    }

    /** إرفاق الملف بتبويب JATS في النشرة (يستبدل الملف السابق المرفوع) */
    public function attach($args, $request)
    {
        $this->manager($request); $sub = $this->sub($request); $ctx = $request->getContext();
        $pub = $sub->getCurrentPublication();
        $xml = $this->build($request, $sub);
        if (!Jats::validate($xml)['wellFormed'][0]) { $this->back($request, 'article', ['sid' => $sub->getId()], 'errXml'); return; }
        try {
            $genreDao = DAORegistry::getDAO('GenreDAO');
            $genres = $genreDao->getEnabledByContextId($ctx->getId())->toArray();
            $existing = Repo::jats()->getJatsFile((int) $pub->getId(), (int) $sub->getId(), $genres);
            if (!$existing->isDefaultContent && $existing->submissionFile) Repo::submissionFile()->delete($existing->submissionFile);
            $genre = $genreDao->getByKey('SUBMISSION', (int) $ctx->getId());
            $tmp = tempnam(sys_get_temp_dir(), 'jx'); file_put_contents($tmp, $xml);
            Repo::jats()->addJatsFile($tmp, 'jats-' . $sub->getId() . '.xml', (int) $pub->getId(), (int) $sub->getId(), SubmissionFile::SUBMISSION_FILE_JATS, $genre ? ['genreId' => (int) $genre->getId()] : []);
            @unlink($tmp);
            (new Store())->attached((int) $sub->getId());
        } catch (\Throwable $e) {
            error_log('[jatsExport] attach: ' . $e->getMessage());
            $this->back($request, 'article', ['sid' => $sub->getId()], 'errAttach'); return;
        }
        $this->back($request, 'article', ['sid' => $sub->getId()], 'attachedOk');
    }
}
