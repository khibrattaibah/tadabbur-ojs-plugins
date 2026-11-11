<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/jatsExport/classes/Render.php
 * عرض ملف JATS صفحةَ HTML للقراءة والمراجعة (وتصلح نسخةً HTML للبحث).
 */
namespace APP\plugins\generic\tadabburSuite\m\jatsExport\classes;

class Render
{
    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function inline(\DOMNode $n): string
    {
        $h = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMText) { $h .= self::e($c->nodeValue); continue; }
            if (!$c instanceof \DOMElement) continue;
            $in = self::inline($c);
            $h .= match ($c->localName) {
                'bold' => '<b>' . $in . '</b>', 'italic' => '<i>' . $in . '</i>', 'sup' => '<sup>' . $in . '</sup>', 'sub' => '<sub>' . $in . '</sub>',
                'break' => '<br>', 'xref' => $c->getAttribute('ref-type') === 'fn' ? '<sup><a href="#' . self::e($c->getAttribute('rid')) . '" id="r-' . self::e($c->getAttribute('rid')) . '">' . $in . '</a></sup>' : '<a href="#' . self::e($c->getAttribute('rid')) . '">' . $in . '</a>',
                'ext-link', 'uri' => '<a href="' . self::e($c->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: $c->textContent) . '">' . $in . '</a>',
                default => $in,
            };
        }
        return $h;
    }

    private static function block(\DOMElement $n, int $depth): string
    {
        $h = '';
        foreach ($n->childNodes as $c) {
            if (!$c instanceof \DOMElement) continue;
            switch ($c->localName) {
                case 'sec': $h .= '<section>' . self::block($c, $depth + 1) . '</section>'; break;
                case 'title': $t = min(6, $depth); $h .= '<h' . $t . '>' . self::inline($c) . '</h' . $t . '>'; break;
                case 'p': $h .= '<p>' . self::inline($c) . '</p>'; break;
                case 'table-wrap':
                    $h .= '<div class="tw"><table>';
                    foreach ($c->getElementsByTagName('tr') as $tr) { $h .= '<tr>'; foreach ($tr->childNodes as $td) if ($td instanceof \DOMElement) $h .= '<' . $td->localName . '>' . self::inline($td) . '</' . $td->localName . '>'; $h .= '</tr>'; }
                    $h .= '</table></div>'; break;
                default: $h .= self::block($c, $depth);
            }
        }
        return $h;
    }

    public static function html(string $xml): string
    {
        $d = new \DOMDocument(); if (!@$d->loadXML($xml, LIBXML_NONET)) return '<p>XML error</p>';
        $x = new \DOMXPath($d);
        $lang = $d->documentElement->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: 'ar';
        $q = fn ($p) => $x->query($p)->item(0);
        $txt = fn ($p) => ($e = $q($p)) ? trim($e->textContent) : '';
        $h = '<div class="jm">' . self::e($txt('//journal-title'));
        if ($v = $txt('//article-meta/volume')) $h .= ' · ' . self::e(($lang === 'ar' ? 'المجلد ' : 'Vol. ') . $v . ($lang === 'ar' ? '، العدد ' : ', No. ') . $txt('//article-meta/issue'));
        if ($fp = $txt('//article-meta/fpage')) $h .= ' · ' . self::e(($lang === 'ar' ? 'ص ' : 'pp. ') . $fp . '–' . $txt('//article-meta/lpage'));
        $h .= '</div><h1>' . self::inline($q('//article-meta/title-group/article-title')) . '</h1>';
        if ($tt = $q('//article-meta/title-group/trans-title-group/trans-title')) $h .= '<div class="tt" dir="auto">' . self::inline($tt) . '</div>';
        $au = [];
        foreach ($x->query('//contrib-group/contrib') as $c) {
            $n = $x->query('.//name', $c)->item(0);
            $name = $n ? trim($x->query('given-names', $n)->item(0)?->textContent . ' ' . $x->query('surname', $n)->item(0)?->textContent) : '';
            $orcid = $x->query('contrib-id[@contrib-id-type="orcid"]', $c)->item(0)?->textContent;
            $au[] = self::e($name) . ($orcid ? ' <a class="orcid" href="' . self::e($orcid) . '">iD</a>' : '');
        }
        $h .= '<div class="au">' . implode('، ', $au) . '</div><ul class="aff">';
        foreach ($x->query('//article-meta/aff') as $a) $h .= '<li>' . self::e($x->query('.//institution', $a)->item(0)?->textContent ?? '') . '</li>';
        $h .= '</ul>';
        if ($doi = $txt('//article-id[@pub-id-type="doi"]')) $h .= '<div class="doi" dir="ltr"><a href="https://doi.org/' . self::e($doi) . '">https://doi.org/' . self::e($doi) . '</a></div>';
        foreach ($x->query('//article-meta/abstract | //article-meta/trans-abstract') as $ab) {
            $l = $ab->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang');
            $h .= '<div class="abs" lang="' . self::e($l) . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '"><h3>' . ($l === 'ar' ? 'الملخص' : 'Abstract') . '</h3>' . self::block($ab, 2);
            foreach ($x->query('//kwd-group') as $kg) if ($kg->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') === $l)
                $h .= '<p class="kw"><b>' . ($l === 'ar' ? 'الكلمات المفتاحية: ' : 'Keywords: ') . '</b>' . self::e(implode($l === 'ar' ? '، ' : ', ', array_map(fn ($k) => trim($k->textContent), iterator_to_array($x->query('kwd', $kg))))) . '</p>';
            $h .= '</div>';
        }
        if ($b = $q('//body')) $h .= '<main>' . self::block($b, 1) . '</main>';
        if ($x->query('//back/ref-list/ref')->length) {
            $h .= '<h2>' . self::e($txt('//back/ref-list/title')) . '</h2><ol class="refs">';
            foreach ($x->query('//back/ref-list/ref') as $r) $h .= '<li id="' . self::e($r->getAttribute('id')) . '">' . self::e(trim($x->query('mixed-citation', $r)->item(0)?->firstChild?->nodeValue ?? $r->textContent)) . '</li>';
            $h .= '</ol>';
        }
        if ($x->query('//back/fn-group/fn')->length) {
            $h .= '<hr><ol class="fns">';
            foreach ($x->query('//back/fn-group/fn') as $f) { $id = $f->getAttribute('id'); $h .= '<li id="' . self::e($id) . '" value="' . (int) substr($id, 2) . '">' . implode(' ', array_map(fn ($p) => self::inline($p), iterator_to_array($x->query('p', $f)))) . ' <a href="#r-' . self::e($id) . '">↩</a></li>'; }
            $h .= '</ol>';
        }
        return '<!doctype html><html lang="' . self::e($lang) . '" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($txt('//article-meta/title-group/article-title')) . '</title>'
            . '<style>body{font-family:"Amiri","IBM Plex Sans Arabic","Segoe UI",Tahoma,serif;max-width:820px;margin:28px auto;padding:0 16px;line-height:1.9;color:#1b1b1b;font-size:17px}h1{font-size:26px;line-height:1.5;margin:8px 0}.jm{color:#6b4f2a;font-size:14px;border-bottom:2px solid #6b4f2a;padding-bottom:6px}.tt{color:#444;font-size:18px;direction:ltr;text-align:left}.au{font-weight:700;margin-top:8px}.aff{color:#555;font-size:14px;margin:4px 0;padding-inline-start:18px}.orcid{font-size:11px;background:#a6ce39;color:#fff;border-radius:50%;padding:0 4px;text-decoration:none}.doi{font-size:14px}.abs{background:#f7f3ec;border-radius:8px;padding:10px 16px;margin:14px 0}.abs h3{margin:0}.kw{font-size:15px}h2{font-size:21px;color:#6b4f2a;margin-top:28px}h3{font-size:18px}.tw{overflow-x:auto}table{border-collapse:collapse;margin:10px 0}td,th{border:1px solid #aaa;padding:4px 8px}th{background:#eee}.refs li,.fns li{font-size:15px;margin-bottom:6px}</style></head><body>' . $h . '</body></html>';
    }
}
