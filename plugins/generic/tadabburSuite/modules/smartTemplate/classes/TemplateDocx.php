<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/smartTemplate/classes/TemplateDocx.php
 * توليد قالب Word للمجلة دون مكتبات: أنماط العناوين (متوافقة مع تحويل JATS)، واتجاه من اليمين،
 * والحواشي السفلية، ونمط الآيات، ونمط المراجع بإزاحة معلّقة، مع تعبئة بيانات البحث إن وُجدت.
 */
namespace APP\plugins\generic\tadabburSuite\m\smartTemplate\classes;

class TemplateDocx
{
    private array $fn = [];
    private string $body = '';

    public function __construct(private array $cfg) {}

    private static function x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    private function run(string $t, array $o = []): string
    {
        $rpr = '';
        if (!empty($o['b'])) $rpr .= '<w:b/><w:bCs/>';
        if (!empty($o['i'])) $rpr .= '<w:i/><w:iCs/>';
        if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        if (empty($o['ltr'])) $rpr .= '<w:rtl/>';
        return '<w:r>' . ($rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . self::x($t) . '</w:t></w:r>';
    }

    /** فقرة: نص أو أجزاء [نص، خيارات] أو ['fn' => نص الحاشية] */
    public function p(string $style, $content, array $o = []): self
    {
        $runs = '';
        foreach (is_array($content) ? $content : [[$content, $o]] as $part) {
            if (isset($part['fn'])) { $id = count($this->fn) + 2; $this->fn[$id] = $part['fn'];
                $runs .= '<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteReference w:id="' . $id . '"/></w:r>'; continue; }
            $runs .= $this->run((string) $part[0], ($part[1] ?? []) + (!empty($o['ltr']) ? ['ltr' => true] : []));
        }
        $ppr = '<w:pStyle w:val="' . $style . '"/>' . (empty($o['ltr']) ? '<w:bidi/>' : '<w:bidi w:val="0"/>' . ($style === 'TitleEn' ? '' : '<w:jc w:val="left"/>'));
        $this->body .= '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $runs . '</w:p>';
        return $this;
    }

    public function hint(string $t): self { return $this->p('Hint', $t); }

    private function styles(): string
    {
        $c = $this->cfg; $ar = self::x($c['fontAr']); $en = self::x($c['fontEn']); $q = self::x($c['fontQuran']);
        $sz = (int) round($c['size'] * 2); $fsz = (int) round($c['fnSize'] * 2);
        $rf = fn ($f, $fen = null) => '<w:rFonts w:ascii="' . ($fen ?? $en) . '" w:hAnsi="' . ($fen ?? $en) . '" w:cs="' . $f . '"/>';
        $st = fn ($id, $name, $ppr, $rpr, $based = 'Normal', $extra = '') => '<w:style w:type="paragraph" w:styleId="' . $id . '"' . ($id === 'Normal' ? ' w:default="1"' : '') . '><w:name w:val="' . $name . '"/>'
            . ($id !== 'Normal' ? '<w:basedOn w:val="' . $based . '"/>' : '') . '<w:next w:val="Normal"/>' . $extra . '<w:qFormat/><w:pPr>' . $ppr . '</w:pPr><w:rPr>' . $rpr . '</w:rPr></w:style>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>' . $rf($ar) . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/><w:lang w:val="en-US" w:bidi="ar-SA"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="' . (int) round(240 * $c['line']) . '" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . $st('Normal', 'Normal', '<w:bidi/><w:jc w:val="both"/><w:ind w:firstLine="567"/>', '')
            . $st('Title', 'Title', '<w:bidi/><w:jc w:val="center"/><w:ind w:firstLine="0"/><w:spacing w:after="80"/>', '<w:b/><w:bCs/><w:sz w:val="' . ($sz + 8) . '"/><w:szCs w:val="' . ($sz + 8) . '"/>')
            . $st('TitleEn', 'Title English', '<w:jc w:val="center"/><w:ind w:firstLine="0"/>', $rf($en, $en) . '<w:b/><w:sz w:val="' . ($sz + 2) . '"/>')
            . $st('Authors', 'Authors', '<w:bidi/><w:jc w:val="center"/><w:ind w:firstLine="0"/><w:spacing w:after="0"/>', '<w:b/><w:bCs/>')
            . $st('Affiliation', 'Affiliation', '<w:bidi/><w:jc w:val="center"/><w:ind w:firstLine="0"/><w:spacing w:after="0"/>', '<w:sz w:val="' . ($sz - 4) . '"/><w:szCs w:val="' . ($sz - 4) . '"/>')
            . $st('Heading1', 'heading 1', '<w:keepNext/><w:bidi/><w:ind w:firstLine="0"/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="0"/>', '<w:b/><w:bCs/><w:sz w:val="' . ($sz + 4) . '"/><w:szCs w:val="' . ($sz + 4) . '"/>')
            . $st('Heading2', 'heading 2', '<w:keepNext/><w:bidi/><w:ind w:firstLine="0"/><w:spacing w:before="200" w:after="80"/><w:outlineLvl w:val="1"/>', '<w:b/><w:bCs/><w:sz w:val="' . ($sz + 2) . '"/><w:szCs w:val="' . ($sz + 2) . '"/>')
            . $st('Heading3', 'heading 3', '<w:keepNext/><w:bidi/><w:ind w:firstLine="0"/><w:spacing w:before="160" w:after="60"/><w:outlineLvl w:val="2"/>', '<w:b/><w:bCs/>')
            . $st('Abstract', 'Abstract', '<w:bidi/><w:jc w:val="both"/><w:ind w:firstLine="0" w:left="284" w:right="284"/>', '<w:sz w:val="' . ($sz - 2) . '"/><w:szCs w:val="' . ($sz - 2) . '"/>')
            . $st('AbstractEn', 'Abstract English', '<w:jc w:val="both"/><w:ind w:firstLine="0" w:left="284" w:right="284"/>', $rf($en, $en) . '<w:sz w:val="' . ($sz - 4) . '"/>')
            . $st('Quran', 'Quran Verse', '<w:bidi/><w:jc w:val="center"/><w:ind w:firstLine="0"/>', $rf($q) . '<w:sz w:val="' . ($sz + 2) . '"/><w:szCs w:val="' . ($sz + 2) . '"/>')
            . $st('Reference', 'Reference', '<w:bidi/><w:jc w:val="both"/><w:ind w:right="567" w:hanging="567" w:firstLine="0"/>', '<w:sz w:val="' . ($sz - 2) . '"/><w:szCs w:val="' . ($sz - 2) . '"/>')
            . $st('Hint', 'Template Hint', '<w:bidi/><w:ind w:firstLine="0"/><w:shd w:val="clear" w:color="auto" w:fill="FFF4DC"/>', '<w:i/><w:iCs/><w:color w:val="8A5A00"/><w:sz w:val="' . ($sz - 4) . '"/><w:szCs w:val="' . ($sz - 4) . '"/>')
            . $st('FootnoteText', 'footnote text', '<w:bidi/><w:jc w:val="both"/><w:ind w:firstLine="0"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/>', '<w:sz w:val="' . $fsz . '"/><w:szCs w:val="' . $fsz . '"/>')
            . '<w:style w:type="character" w:styleId="FootnoteReference"><w:name w:val="footnote reference"/><w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>'
            . '</w:styles>';
    }

    public function build(string $titleProp = '', string $authorProp = ''): string
    {
        $c = $this->cfg;
        $W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $mm = fn ($v) => (int) round($v * 56.7);
        $sect = '<w:sectPr><w:footnotePr><w:numFmt w:val="decimal"/><w:numRestart w:val="eachPage"/></w:footnotePr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="' . $mm($c['margin']) . '" w:right="' . $mm($c['margin']) . '" w:bottom="' . $mm($c['margin']) . '" w:left="' . $mm($c['margin']) . '" w:header="709" w:footer="709" w:gutter="0"/><w:bidi/></w:sectPr>';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $W . '><w:body>' . $this->body . $sect . '</w:body></w:document>';
        $fns = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:footnotes ' . $W . '><w:footnote w:type="separator" w:id="0"><w:p><w:r><w:separator/></w:r></w:p></w:footnote><w:footnote w:type="continuationSeparator" w:id="1"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:footnote>';
        foreach ($this->fn as $id => $t) $fns .= '<w:footnote w:id="' . $id . '"><w:p><w:pPr><w:pStyle w:val="FootnoteText"/><w:bidi/></w:pPr><w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r>' . $this->run(' ' . $t) . '</w:p></w:footnote>';
        $fns .= '</w:footnotes>';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/></Relationships>',
            'word/document.xml' => $doc, 'word/styles.xml' => $this->styles(), 'word/footnotes.xml' => $fns,
            'word/settings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:footnotePr><w:footnote w:id="0"/><w:footnote w:id="1"/></w:footnotePr><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x($titleProp) . '</dc:title><dc:creator>' . self::x($authorProp) . '</dc:creator><cp:lastModifiedBy>' . self::x($authorProp) . '</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'tp'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $n => $cnt) $z->addFromString($n, $cnt);
        $z->close(); $out = (string) file_get_contents($tmp); @unlink($tmp);
        return $out;
    }
}
