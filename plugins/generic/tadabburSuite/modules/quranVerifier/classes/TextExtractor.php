<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/quranVerifier/classes/TextExtractor.php
 *
 * استخراج نص البحث من ملفات Word (docx) مع إدراج نص الحواشي في موضعها، ومن PDF (عبر pdftotext إن توفر)، ومن النصوص.
 */
namespace APP\plugins\generic\tadabburSuite\m\quranVerifier\classes;

class TextExtractor
{
    public const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @return array{text:string, error:?string, kind:string} */
    public static function fromFile(string $path, string $name, string $mime = ''): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'docx' || str_contains($mime, 'wordprocessingml')) return self::docx($path);
        if ($ext === 'odt' || str_contains($mime, 'opendocument.text')) return self::odt($path);
        if ($ext === 'pdf' || $mime === 'application/pdf') return self::pdf($path);
        if (in_array($ext, ['txt', 'md'], true) || str_starts_with($mime, 'text/plain')) return ['text' => (string) file_get_contents($path), 'error' => null, 'kind' => 'txt'];
        if (in_array($ext, ['html', 'htm', 'xml'], true) || str_contains($mime, 'html') || str_contains($mime, 'xml')) {
            return ['text' => html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h\d)[^>]*>/i', "\n", (string) file_get_contents($path))), ENT_QUOTES, 'UTF-8'), 'error' => null, 'kind' => 'html'];
        }
        return ['text' => '', 'error' => 'unsupported', 'kind' => $ext];
    }

    /** نص فقرة Word: يجمع w:t ويحوّل w:tab/w:br، ويُدرج الحواشي ⟦…⟧ مكان الإحالة */
    private static function paraText(\DOMXPath $xp, \DOMNode $p, array $notes): string
    {
        $out = '';
        foreach ($xp->query('.//w:r | .//w:footnoteReference | .//w:endnoteReference', $p) as $node) {
            if ($node->localName === 'r') {
                foreach ($node->childNodes as $c) {
                    if ($c->localName === 't') $out .= $c->textContent;
                    elseif ($c->localName === 'tab') $out .= ' ';
                    elseif ($c->localName === 'br' || $c->localName === 'cr') $out .= "\n";
                    elseif ($c->localName === 'sym') $out .= '';
                }
            } else {
                $id = $node->getAttributeNS(self::W, 'id');
                $key = ($node->localName === 'footnoteReference' ? 'f' : 'e') . $id;
                if (isset($notes[$key]) && trim($notes[$key]) !== '') $out .= ' ⟦' . trim($notes[$key]) . '⟧ ';
            }
        }
        return $out;
    }

    private static function loadXml(\ZipArchive $z, string $entry): ?\DOMDocument
    {
        $xml = $z->getFromName($entry);
        if ($xml === false || $xml === '') return null;
        $d = new \DOMDocument();
        if (!@$d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) return null;
        return $d;
    }

    public static function docx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) return ['text' => '', 'error' => 'nozip', 'kind' => 'docx'];
        $z = new \ZipArchive();
        if ($z->open($path) !== true) return ['text' => '', 'error' => 'corrupt', 'kind' => 'docx'];
        $notes = [];
        foreach (['f' => ['word/footnotes.xml', 'footnote'], 'e' => ['word/endnotes.xml', 'endnote']] as $k => [$entry, $tag]) {
            $d = self::loadXml($z, $entry);
            if (!$d) continue;
            $xp = new \DOMXPath($d); $xp->registerNamespace('w', self::W);
            foreach ($xp->query('//w:' . $tag) as $fn) {
                $parts = [];
                foreach ($xp->query('.//w:p', $fn) as $p) $parts[] = self::paraText($xp, $p, []);
                $notes[$k . $fn->getAttributeNS(self::W, 'id')] = trim(implode(' ', $parts));
            }
        }
        $d = self::loadXml($z, 'word/document.xml');
        $z->close();
        if (!$d) return ['text' => '', 'error' => 'corrupt', 'kind' => 'docx'];
        $xp = new \DOMXPath($d); $xp->registerNamespace('w', self::W);
        $lines = [];
        foreach ($xp->query('//w:body//w:p') as $p) $lines[] = self::paraText($xp, $p, $notes);
        return ['text' => implode("\n", $lines), 'error' => null, 'kind' => 'docx'];
    }

    public static function odt(string $path): array
    {
        $z = new \ZipArchive();
        if ($z->open($path) !== true) return ['text' => '', 'error' => 'corrupt', 'kind' => 'odt'];
        $xml = (string) $z->getFromName('content.xml'); $z->close();
        $xml = preg_replace('/<text:note-citation[^>]*>.*?<\/text:note-citation>/s', '', $xml);
        $xml = preg_replace('/<text:note-body>(.*?)<\/text:note-body>/s', ' ⟦$1⟧ ', $xml);
        $xml = preg_replace('/<\/text:(p|h)>/', "\n", $xml);
        return ['text' => html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'), 'error' => null, 'kind' => 'odt'];
    }

    public static function pdf(string $path): array
    {
        $bin = null;
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/bin/pdftotext'] as $c) if (@is_executable($c)) { $bin = $c; break; }
        if (!$bin || !function_exists('exec')) return ['text' => '', 'error' => 'nopdftotext', 'kind' => 'pdf'];
        $out = tempnam(sys_get_temp_dir(), 'qv');
        @exec(escapeshellarg($bin) . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>/dev/null', $o, $code);
        $t = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($out);
        if ($code !== 0 || trim($t) === '') return ['text' => '', 'error' => 'pdfempty', 'kind' => 'pdf'];
        if (class_exists(\Normalizer::class)) $t = \Normalizer::normalize($t, \Normalizer::FORM_KC) ?: $t; // أشكال العرض العربية → حروف أساسية
        return ['text' => $t, 'error' => null, 'kind' => 'pdf'];
    }
}
