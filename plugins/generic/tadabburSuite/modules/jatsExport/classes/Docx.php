<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/jatsExport/classes/Docx.php
 * تحويل ملف Word (DOCX) إلى متن JATS: العناوين أقسامًا متداخلة، والفقرات، والخط العريض والمائل والفوقي،
 * والحواشي السفلية، والجداول، واستخراج قائمة المراجع من قسمها. دون أي مكتبات خارجية.
 */
namespace APP\plugins\generic\tadabburSuite\m\jatsExport\classes;

class Docx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const REFS = '/^\s*(قائمة\s+)?(المصادر|المراجع|المصادر\s+والمراجع|ثبت\s+المصادر(\s+والمراجع)?|فهرس\s+المصادر(\s+والمراجع)?|References|Bibliography|Works\s+Cited|Sources)\s*[:：]?\s*$/iu';
    private const SKIP = '/^\s*(الملخص|ملخص\s+البحث|المستخلص|مستخلص\s+البحث|Abstract|الكلمات\s+المفتاحية|الكلمات\s+الدالة|Keywords?|Key\s+words)\s*[:：]?/iu';
    private const INTRO = '/^\s*(ال)?مقدمة|^\s*Introduction/iu';

    private array $styles = [];
    private \DOMXPath $xp;
    public array $stats = ['sections' => 0, 'paragraphs' => 0, 'tables' => 0, 'footnotes' => 0, 'refs' => 0, 'skipped' => 0];

    /** @return array{body:string, refs:array, footnotes:array, stats:array} */
    public function convert(string $file): array
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('ZipArchive');
        $z = new \ZipArchive();
        if ($z->open($file) !== true) throw new \RuntimeException('notDocx');
        $doc = $z->getFromName('word/document.xml');
        if ($doc === false) throw new \RuntimeException('notDocx');
        $this->loadStyles((string) $z->getFromName('word/styles.xml'));
        $fns = $this->footnotes((string) $z->getFromName('word/footnotes.xml'));
        $z->close();

        $d = new \DOMDocument();
        if (!@$d->loadXML($doc, LIBXML_NONET | LIBXML_COMPACT)) throw new \RuntimeException('notDocx');
        $this->xp = new \DOMXPath($d); $this->xp->registerNamespace('w', self::W);
        $bodyEl = $this->xp->query('//w:body')->item(0);

        // تجميع الكتل: [type, level, html]
        $blocks = [];
        foreach ($bodyEl->childNodes as $n) {
            if (!$n instanceof \DOMElement) continue;
            if ($n->localName === 'p') {
                $lvl = $this->level($n); $xml = $this->runs($n); $text = trim(strip_tags($xml));
                if ($text === '') continue;
                $blocks[] = [$lvl ? 'h' : 'p', $lvl, $xml, $text, $this->styleName($n)];
            } elseif ($n->localName === 'tbl') {
                $blocks[] = ['t', 0, $this->table($n), '', ''];
            }
        }
        // تخطي ما قبل المقدمة (العنوان، المؤلفون، الملخصات) إذا وُجد عنوان «المقدمة»
        $start = 0;
        foreach ($blocks as $i => $b) if ($b[0] === 'h' && preg_match(self::INTRO, $b[3])) { $start = $i; break; }
        $this->stats['skipped'] = $start;
        $blocks = array_slice($blocks, $start);

        $out = ''; $stack = []; $refs = []; $mode = 'body'; $modeLevel = 0;
        foreach ($blocks as $b) {
            [$type, $lvl, $xml, $text, $style] = $b;
            if ($type === 'h') {
                if ($mode !== 'body' && $lvl <= $modeLevel) $mode = 'body';
                if (preg_match(self::REFS, $text)) { $mode = 'refs'; $modeLevel = $lvl; continue; }
                if (preg_match(self::SKIP, $text)) { $mode = 'skip'; $modeLevel = $lvl; continue; }
                if ($mode !== 'body') continue;
                while ($stack && end($stack) >= $lvl) { $out .= '</sec>'; array_pop($stack); }
                $this->stats['sections']++;
                $out .= '<sec id="s' . $this->stats['sections'] . '"><title>' . $xml . '</title>';
                $stack[] = $lvl;
                continue;
            }
            // فقرة بنص «المراجع» دون نمط عنوان
            if ($type === 'p' && mb_strlen($text) < 40 && preg_match(self::REFS, $text)) { $mode = 'refs'; $modeLevel = 1; continue; }
            if ($mode === 'refs') { if ($type === 'p') { $refs[] = preg_replace('/^\s*(\[\d+\]|\d+[\.\-\)])\s*/u', '', $text); } continue; }
            if ($mode === 'skip') continue;
            if ($type === 't') { $this->stats['tables']++; $out .= $xml; continue; }
            $this->stats['paragraphs']++;
            $out .= '<p>' . $xml . '</p>';
        }
        while ($stack) { $out .= '</sec>'; array_pop($stack); }
        // الفقرات قبل أول قسم تُلف في قسم بلا عنوان ليكون المتن صالحًا (JATS يسمح بـ p ثم sec)
        $this->stats['refs'] = count($refs);
        $used = [];
        preg_match_all('/rid="fn(\d+)"/', $out, $mm); foreach ($mm[1] as $id) $used[$id] = true;
        $fns = array_intersect_key($fns, $used);
        $this->stats['footnotes'] = count($fns);
        return ['body' => $out, 'refs' => $refs, 'footnotes' => $fns, 'stats' => $this->stats];
    }

    private function loadStyles(string $xml): void
    {
        if ($xml === '') return;
        $d = new \DOMDocument(); if (!@$d->loadXML($xml, LIBXML_NONET)) return;
        $x = new \DOMXPath($d); $x->registerNamespace('w', self::W);
        foreach ($x->query('//w:style[@w:type="paragraph"]') as $s) {
            $id = $s->getAttributeNS(self::W, 'styleId');
            $name = (string) ($x->query('w:name/@w:val', $s)->item(0)?->nodeValue ?? '');
            $ol = $x->query('w:pPr/w:outlineLvl/@w:val', $s)->item(0)?->nodeValue;
            $based = (string) ($x->query('w:basedOn/@w:val', $s)->item(0)?->nodeValue ?? '');
            $lvl = null;
            if ($ol !== null && (int) $ol < 9) $lvl = (int) $ol + 1;
            elseif (preg_match('/^(heading|titre|überschrift|عنوان)\s*(\d)$/iu', trim($name), $m)) $lvl = (int) $m[2];
            $this->styles[$id] = ['name' => $name, 'level' => $lvl, 'based' => $based];
        }
    }

    private function styleName(\DOMElement $p): string
    {
        $id = (string) ($this->xp->query('w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue ?? '');
        return $this->styles[$id]['name'] ?? $id;
    }

    private function level(\DOMElement $p): int
    {
        $ol = $this->xp->query('w:pPr/w:outlineLvl/@w:val', $p)->item(0)?->nodeValue;
        if ($ol !== null && (int) $ol < 9) return (int) $ol + 1;
        $id = (string) ($this->xp->query('w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue ?? '');
        for ($i = 0; $id !== '' && $i < 5; $i++) {
            $s = $this->styles[$id] ?? null; if (!$s) break;
            if ($s['level']) return min(6, (int) $s['level']);
            $id = $s['based'];
        }
        if (preg_match('/^heading\s*(\d)$/i', (string) ($this->xp->query('w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue ?? ''), $m)) return (int) $m[1];
        return 0;
    }

    private static function x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    private function on(\DOMElement $r, string $tag): bool
    {
        $e = $this->xp->query('w:rPr/w:' . $tag, $r)->item(0);
        if (!$e instanceof \DOMElement) return false;
        $v = $e->getAttributeNS(self::W, 'val');
        return $v === '' || !in_array($v, ['0', 'false', 'none'], true);
    }

    /** نص الفقرة مع التنسيق والحواشي */
    private function runs(\DOMElement $p): string
    {
        $out = '';
        foreach ($this->xp->query('.//w:r', $p) as $r) {
            $t = '';
            foreach ($r->childNodes as $c) {
                if (!$c instanceof \DOMElement) continue;
                if ($c->localName === 't') $t .= self::x($c->textContent);
                elseif ($c->localName === 'tab' || $c->localName === 'br') $t .= ' ';
                elseif ($c->localName === 'footnoteReference') $t .= '<xref ref-type="fn" rid="fn' . (int) $c->getAttributeNS(self::W, 'id') . '">' . (int) $c->getAttributeNS(self::W, 'id') . '</xref>';
            }
            if ($t === '') continue;
            if (str_contains($t, '<xref')) { $out .= $t; continue; }
            $va = (string) ($this->xp->query('w:rPr/w:vertAlign/@w:val', $r)->item(0)?->nodeValue ?? '');
            if ($va === 'superscript') $t = '<sup>' . $t . '</sup>'; elseif ($va === 'subscript') $t = '<sub>' . $t . '</sub>';
            if ($this->on($r, 'i') || $this->on($r, 'iCs')) $t = '<italic>' . $t . '</italic>';
            if ($this->on($r, 'b') || $this->on($r, 'bCs')) $t = '<bold>' . $t . '</bold>';
            $out .= $t;
        }
        // دمج الوسوم المتجاورة المتطابقة
        $out = preg_replace('~</(bold|italic)><\1>~', '', $out);
        return trim(preg_replace('/\s+/u', ' ', $out));
    }

    private function table(\DOMElement $tbl): string
    {
        $rows = '';
        foreach ($this->xp->query('w:tr', $tbl) as $i => $tr) {
            $cells = '';
            foreach ($this->xp->query('w:tc', $tr) as $tc) {
                $parts = [];
                foreach ($this->xp->query('w:p', $tc) as $p) { $x = $this->runs($p); if ($x !== '') $parts[] = $x; }
                $tag = $i === 0 ? 'th' : 'td';
                $cells .= '<' . $tag . '>' . implode('<break/>', $parts) . '</' . $tag . '>';
            }
            $rows .= '<tr>' . $cells . '</tr>';
        }
        $n = $this->stats['tables'] + 1;
        return '<table-wrap id="T' . $n . '"><table frame="box" rules="all">' . $rows . '</table></table-wrap>';
    }

    private function footnotes(string $xml): array
    {
        $out = [];
        if ($xml === '') return $out;
        $d = new \DOMDocument(); if (!@$d->loadXML($xml, LIBXML_NONET)) return $out;
        $x = new \DOMXPath($d); $x->registerNamespace('w', self::W);
        $save = $this->xp ?? null; $this->xp = $x;
        foreach ($x->query('//w:footnote') as $fn) {
            $type = $fn->getAttributeNS(self::W, 'type');
            if ($type === 'separator' || $type === 'continuationSeparator') continue;
            $id = (int) $fn->getAttributeNS(self::W, 'id');
            $ps = [];
            foreach ($x->query('w:p', $fn) as $p) { $t = $this->runs($p); if ($t !== '') $ps[] = $t; }
            if ($ps) $out[$id] = $ps;
        }
        if ($save) $this->xp = $save;
        return $out;
    }
}
