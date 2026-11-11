<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/arabicIndexes/pages/IndexesHandler.php
 * المحرر: index (مصفوفة المتابعة)، cell، export، refs، pdfs ؛ POST: saveCell ؛ المدير: settings، saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\arabicIndexes\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\arabicIndexes\classes\Fields;
use APP\plugins\generic\tadabburSuite\m\arabicIndexes\classes\Store;
use APP\plugins\generic\tadabburSuite\m\arabicIndexes\classes\Xlsx;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use Illuminate\Support\Facades\DB;

class IndexesHandler extends ModuleHandler
{
    /** القواعد المفعّلة: مفتاح => [الاسم، الأعمدة[]] */
    private function dbs(int $cid): array
    {
        $cfg = json_decode((string) $this->plugin->getSetting($cid, 'dbs'), true) ?: [];
        $out = [];
        foreach (Fields::PRESETS as $k => [$name, $cols]) {
            $c = $cfg[$k] ?? [];
            if (($c['off'] ?? false)) continue;
            $keys = array_values(array_filter(array_map('trim', explode(',', (string) ($c['cols'] ?? $cols))), fn ($x) => isset(Fields::ALL[$x])));
            $out[$k] = [(string) ($c['name'] ?? '') !== '' ? $c['name'] : $name, $keys ?: explode(',', $cols)];
        }
        return $out;
    }

    private function issueLabel($is): string { return $this->t('issueLabel', ['v' => $is->getVolume(), 'n' => $is->getNumber(), 'y' => $is->getYear()]); }

    private function getIssue($request)
    {
        $is = Repo::issue()->get((int) $request->getUserVar('issue'));
        if (!$is || (int) $is->getJournalId() !== $this->cid($request)) $this->notFound();
        return $is;
    }

    /** لقطات بحوث العدد مرتبة */
    private function metas($request, $is): array
    {
        $ctx = $request->getContext();
        $ids = DB::table('publications as p')->join('submissions as s', 's.current_publication_id', '=', 'p.publication_id')->leftJoin('sections as sec', 'sec.section_id', '=', 'p.section_id')
            ->where('p.issue_id', $is->getId())->where('s.context_id', $ctx->getId())->when($is->getPublished(), fn ($q) => $q->where('p.status', 3))
            ->orderBy('sec.seq')->orderBy('p.seq')->orderBy('p.publication_id')->pluck('s.submission_id')->all();
        $out = [];
        foreach ($ids as $id) if ($s = Repo::submission()->get((int) $id)) $out[] = Meta::snapshot($s, $ctx, $request);
        return $out;
    }

    private function base($is): string { return 'v' . $is->getVolume() . '-n' . $is->getNumber() . '-' . $is->getYear(); }

    private function pdfName($is, array $m): string { return $this->base($is) . '-' . $m['id'] . '.pdf'; }

    public function index($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request);
        $dbs = $this->dbs($cid); $mx = (new Store())->matrix($cid);
        $issues = Repo::issue()->getCollector()->filterByContextIds([$cid])->filterByPublished(true)->orderBy(\APP\issue\Collector::ORDERBY_PUBLISHED_ISSUES)->limit(30)->getMany();
        $colors = ['pending' => ['#f1f3f5', '#555'], 'sent' => ['#e8f0fb', '#0b5394'], 'indexed' => ['#e3f4ea', '#1e7b4a'], 'rejected' => ['#fbe9e9', '#b02a2a']];
        $rows = [];
        foreach ($issues as $is) {
            $r = ['<b>' . self::e($this->issueLabel($is)) . '</b><div style="margin-top:4px">' . $this->btn($this->url($request, 'pdfs', ['issue' => $is->getId()]), '⬇ PDF.zip') . $this->btn($this->url($request, 'refs', ['issue' => $is->getId()]), '⬇ ' . $this->t('refsFile')) . '</div>'];
            foreach ($dbs as $k => [$name, $cols]) {
                $c = $mx[$is->getId()][$k] ?? null; $st = $c->status ?? 'pending'; [$bg, $fg] = $colors[$st];
                $r[] = '<a href="' . self::e($this->url($request, 'cell', ['issue' => $is->getId(), 'db' => $k])) . '" style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;background:' . $bg . ';color:' . $fg . '">' . $this->t('st.' . $st) . '</a>'
                    . ($c && $c->sent_at ? '<div style="font-size:11px;color:#777" dir="ltr">' . self::e($c->sent_at) . '</div>' : '')
                    . '<div style="margin-top:3px">' . $this->btn($this->url($request, 'export', ['issue' => $is->getId(), 'db' => $k, 'fmt' => 'xlsx']), 'Excel') . $this->btn($this->url($request, 'export', ['issue' => $is->getId(), 'db' => $k, 'fmt' => 'csv']), 'CSV') . '</div>';
            }
            $rows[] = $r;
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request)
            . $this->box($this->t('matrix'), $this->table(array_merge([$this->t('col.issue')], array_map(fn ($d) => $d[0], $dbs)), $rows, $this->t('noIssues')));
        if ($this->plugin->isManager($request)) $h .= $this->btn($this->url($request, 'settings'), '⚙ ' . $this->t('settings'));
        $this->show($request, $this->t('menu'), $h);
    }

    public function cell($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $is = $this->getIssue($request);
        $dbs = $this->dbs($cid); $db = (string) $request->getUserVar('db'); if (!isset($dbs[$db])) $this->notFound();
        $c = (new Store())->matrix($cid)[$is->getId()][$db] ?? null;
        $opts = implode('', array_map(fn ($s) => '<option value="' . $s . '"' . (($c->status ?? 'pending') === $s ? ' selected' : '') . '>' . $this->t('st.' . $s) . '</option>', Store::STATES));
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->box(self::e($dbs[$db][0] . ' — ' . $this->issueLabel($is)),
            '<form method="post" action="' . self::e($this->url($request, 'saveCell')) . '">' . $this->csrf($request) . '<input type="hidden" name="issue" value="' . (int) $is->getId() . '"><input type="hidden" name="db" value="' . self::e($db) . '">'
            . '<label style="font-size:12px">' . $this->t('f.status') . '<br><select name="status" style="' . self::S_INP . '">' . $opts . '</select></label> '
            . '<label style="font-size:12px">' . $this->t('f.sent') . '<br><input type="date" name="sent" value="' . self::e($c->sent_at ?? '') . '" style="' . self::S_INP . '"></label> '
            . '<label style="font-size:12px;display:block;margin-top:6px">' . $this->t('f.note') . '<br><input name="note" value="' . self::e($c->note ?? '') . '" style="' . self::S_INP . 'width:100%"></label>'
            . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>');
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveCell($args, $request)
    {
        $this->editor($request); $this->postOk($request); $cid = $this->cid($request); $is = $this->getIssue($request);
        $db = (string) $request->getUserVar('db'); if (!isset($this->dbs($cid)[$db])) $this->notFound();
        $st = in_array($this->v($request, 'status'), Store::STATES, true) ? $this->v($request, 'status') : 'pending';
        $sent = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->v($request, 'sent')) ? $this->v($request, 'sent') : null;
        if ($st === 'sent' && !$sent) $sent = date('Y-m-d');
        (new Store())->set($cid, $db, (int) $is->getId(), $st, $sent, $this->v($request, 'note'));
        $this->back($request, 'index');
    }

    public function export($args, $request)
    {
        $this->editor($request); $cid = $this->cid($request); $is = $this->getIssue($request);
        $dbs = $this->dbs($cid); $db = (string) $request->getUserVar('db'); if (!isset($dbs[$db])) $this->notFound();
        [$name, $cols] = $dbs[$db]; $j = Meta::journal($request->getContext());
        $head = array_map(fn ($k) => Fields::ALL[$k][0], $cols);
        $rows = [];
        foreach ($this->metas($request, $is) as $m) { $all = Fields::row($m, $j, $this->pdfName($is, $m)); $rows[] = array_map(fn ($k) => $all[$k] ?? '', $cols); }
        $file = $db . '-' . $this->base($is);
        if ($request->getUserVar('fmt') === 'csv') {
            $f = fopen('php://temp', 'w+'); fwrite($f, "\xEF\xBB\xBF"); fputcsv($f, $head); foreach ($rows as $r) fputcsv($f, $r); rewind($f);
            $this->download($file . '.csv', 'text/csv; charset=utf-8', stream_get_contents($f));
        }
        $this->download($file . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Xlsx::build($head, $rows, true, $name));
    }

    /** ملف المراجع لكل بحث (يطلبه معامل التأثير العربي وغيره) */
    public function refs($args, $request)
    {
        $this->editor($request); $is = $this->getIssue($request);
        $rows = [];
        foreach ($this->metas($request, $is) as $m) foreach ($m['citations'] as $i => $c) $rows[] = [$m['id'], Meta::pick($m['titles']), $i + 1, $c];
        $this->download('references-' . $this->base($is) . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            Xlsx::build([$this->t('x.id'), $this->t('x.title'), $this->t('x.n'), $this->t('x.ref')], $rows, true, 'References'));
    }

    /** ملفات PDF للعدد في ملف مضغوط بأسماء موحّدة */
    public function pdfs($args, $request)
    {
        $this->editor($request); $is = $this->getIssue($request);
        @set_time_limit(300);
        $fs = app()->get('file')->fs;
        $tmp = tempnam(sys_get_temp_dir(), 'pz'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE);
        $n = 0; $list = [];
        foreach ($this->metas($request, $is) as $m) {
            foreach ($m['galleys'] as $g) {
                if (!$g['pdf'] || $g['remote'] !== '') continue;
                $path = DB::table('publication_galleys as g')->join('submission_files as sf', 'sf.submission_file_id', '=', 'g.submission_file_id')->join('files as f', 'f.file_id', '=', 'sf.file_id')->where('g.galley_id', $g['id'])->value('f.path');
                if (!$path || !$fs->has($path)) continue;
                $name = $this->pdfName($is, $m); if ($g['locale'] !== '' && count(array_filter($m['galleys'], fn ($x) => $x['pdf'])) > 1) $name = str_replace('.pdf', '-' . substr($g['locale'], 0, 2) . '.pdf', $name);
                $z->addFromString($name, $fs->read($path)); $n++; $list[] = $name . "\t" . Meta::pick($m['titles']);
                break;
            }
        }
        $z->addFromString('index.txt', "\xEF\xBB\xBF" . implode("\n", $list) . "\n");
        $z->close();
        $body = (string) file_get_contents($tmp); @unlink($tmp);
        if (!$n) { $this->back($request, 'index', [], 'errNoPdf'); return; }
        $this->download('pdfs-' . $this->base($is) . '.zip', 'application/zip', $body);
    }

    public function settings($args, $request)
    {
        $this->manager($request, false); $cid = $this->cid($request);
        $cfg = json_decode((string) $this->plugin->getSetting($cid, 'dbs'), true) ?: [];
        $f = '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">' . $this->csrf($request);
        foreach (Fields::PRESETS as $k => [$name, $cols]) {
            $c = $cfg[$k] ?? [];
            $f .= '<div style="border:1px solid #e1e4e8;border-radius:8px;padding:10px;margin-bottom:10px"><label><input type="checkbox" name="on[' . $k . ']" value="1"' . (empty($c['off']) ? ' checked' : '') . '> <b>' . self::e($name) . '</b></label>'
                . '<div style="margin-top:6px"><label style="font-size:12px">' . $this->t('f.name') . ' <input name="name[' . $k . ']" value="' . self::e($c['name'] ?? '') . '" placeholder="' . self::e($name) . '" style="' . self::S_INP . 'width:260px"></label></div>'
                . '<div style="margin-top:6px"><label style="font-size:12px">' . $this->t('f.cols') . '<br><input name="cols[' . $k . ']" value="' . self::e($c['cols'] ?? $cols) . '" dir="ltr" style="' . self::S_INP . 'width:100%;font-family:monospace;font-size:12px"></label></div></div>';
        }
        $keys = $this->table([$this->t('x.key'), $this->t('x.label')], array_map(fn ($k, $v) => ['<code>' . $k . '</code>', self::e($v[0])], array_keys(Fields::ALL), Fields::ALL));
        $h = $this->btn($this->url($request, 'index'), '← ' . $this->t('back')) . $this->box($this->t('settings'), '<p style="margin-top:0;font-size:13px;color:#555">' . $this->t('settingsHelp') . '</p>' . $f . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>')
            . $this->box($this->t('fieldsTitle'), $keys);
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveSettings($args, $request)
    {
        $this->manager($request); $cid = $this->cid($request); $out = [];
        $on = (array) $request->getUserVar('on'); $names = (array) $request->getUserVar('name'); $cols = (array) $request->getUserVar('cols');
        foreach (Fields::PRESETS as $k => [$n, $def]) {
            $c = implode(',', array_values(array_filter(array_map('trim', explode(',', (string) ($cols[$k] ?? ''))), fn ($x) => isset(Fields::ALL[$x]))));
            $out[$k] = ['off' => empty($on[$k]), 'name' => mb_substr(trim((string) ($names[$k] ?? '')), 0, 100), 'cols' => $c !== '' ? $c : $def];
        }
        $this->plugin->updateSetting($cid, 'dbs', json_encode($out, JSON_UNESCAPED_UNICODE), 'string');
        $this->back($request, 'settings');
    }
}
