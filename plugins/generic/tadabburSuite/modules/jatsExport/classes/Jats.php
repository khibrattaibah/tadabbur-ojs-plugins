<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/jatsExport/classes/Jats.php
 * بناء ملف JATS 1.3 (Journal Publishing) من بيانات البحث في OJS ومتن Word المحوَّل، مع فحص البنية.
 */
namespace APP\plugins\generic\tadabburSuite\m\jatsExport\classes;

use APP\plugins\generic\tadabburSuite\classes\kit\Meta;

class Jats
{
    private const XLINK = 'http://www.w3.org/1999/xlink';
    private \DOMDocument $d;

    private function el(\DOMNode $parent, string $name, ?string $text = null, array $attrs = []): \DOMElement
    {
        $e = $this->d->createElement($name);
        if ($text !== null && $text !== '') $e->appendChild($this->d->createTextNode($text));
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') continue;
            if (str_starts_with($k, 'xlink:')) $e->setAttributeNS(self::XLINK, $k, $v);
            elseif ($k === 'xml:lang') $e->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $v);
            else $e->setAttribute($k, $v);
        }
        $parent->appendChild($e);
        return $e;
    }

    private function frag(\DOMNode $parent, string $xml): bool
    {
        if (trim($xml) === '') return true;
        $f = $this->d->createDocumentFragment();
        if (!@$f->appendXML($xml)) return false;
        $parent->appendChild($f);
        return true;
    }

    private static function date(\DOMElement $e, string $ymd, $self): void
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return;
        $self->el($e, 'day', $m[3]); $self->el($e, 'month', $m[2]); $self->el($e, 'year', $m[1]);
    }

    /** $m لقطة Meta، $j بيانات المجلة، $body متن JATS (اختياري) */
    public function build(array $m, array $j, array $conv = []): string
    {
        $this->d = new \DOMDocument('1.0', 'UTF-8'); $this->d->formatOutput = true;
        $impl = new \DOMImplementation();
        $this->d = $impl->createDocument(null, '', $impl->createDocumentType('article', '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.3 20210610//EN', 'https://jats.nlm.nih.gov/publishing/1.3/JATS-journalpublishing1-3.dtd'));
        $this->d->encoding = 'UTF-8'; $this->d->formatOutput = true;
        $lang = $m['locale'] !== '' ? substr($m['locale'], 0, 2) : 'ar'; $other = $lang === 'ar' ? 'en' : 'ar';
        $a = $this->d->createElement('article'); $this->d->appendChild($a);
        $a->setAttribute('article-type', 'research-article'); $a->setAttribute('dtd-version', '1.3');
        $a->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xlink', self::XLINK);
        $a->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:mml', 'http://www.w3.org/1998/Math/MathML');
        $a->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $lang);
        $front = $this->el($a, 'front');

        // ---- journal-meta
        $jm = $this->el($front, 'journal-meta');
        $this->el($jm, 'journal-id', $j['acronym'] ?: $j['path'], ['journal-id-type' => 'publisher-id']);
        $jtg = $this->el($jm, 'journal-title-group');
        $this->el($jtg, 'journal-title', Meta::pick($j['names'], [$lang, $other]), ['xml:lang' => $lang]);
        if (!empty($j['names'][$other])) { $t = $this->el($jtg, 'trans-title-group', null, ['xml:lang' => $other]); $this->el($t, 'trans-title', $j['names'][$other]); }
        if ($ab = Meta::pick($j['abbrev'], ['en', $lang])) $this->el($jtg, 'abbrev-journal-title', $ab, ['abbrev-type' => 'publisher']);
        if ($j['issn']) $this->el($jm, 'issn', $j['issn'], ['publication-format' => 'print']);
        if ($j['eissn']) $this->el($jm, 'issn', $j['eissn'], ['publication-format' => 'electronic']);
        if ($j['publisher']) { $p = $this->el($jm, 'publisher'); $this->el($p, 'publisher-name', $j['publisher']); }

        // ---- article-meta
        $am = $this->el($front, 'article-meta');
        $this->el($am, 'article-id', (string) $m['id'], ['pub-id-type' => 'publisher-id']);
        if ($m['doi']) $this->el($am, 'article-id', $m['doi'], ['pub-id-type' => 'doi']);
        if ($sec = Meta::pick($m['section'], [$lang, $other])) { $c = $this->el($am, 'article-categories'); $g = $this->el($c, 'subj-group', null, ['subj-group-type' => 'heading']); $this->el($g, 'subject', $sec); }
        $tg = $this->el($am, 'title-group');
        $this->el($tg, 'article-title', Meta::pick($m['titles'], [$lang, $other]));
        if (!empty($m['subtitles'][$lang])) $this->el($tg, 'subtitle', $m['subtitles'][$lang]);
        if (!empty($m['titles'][$other])) { $t = $this->el($tg, 'trans-title-group', null, ['xml:lang' => $other]); $this->el($t, 'trans-title', $m['titles'][$other]); if (!empty($m['subtitles'][$other])) $this->el($t, 'trans-subtitle', $m['subtitles'][$other]); }

        // المؤلفون والانتماءات
        $cg = $this->el($am, 'contrib-group'); $affs = [];
        foreach ($m['authors'] as $au) {
            $c = $this->el($cg, 'contrib', null, ['contrib-type' => 'author', 'corresp' => $au['primary'] ? 'yes' : null]);
            if ($au['orcidId']) $this->el($c, 'contrib-id', 'https://orcid.org/' . $au['orcidId'], ['contrib-id-type' => 'orcid', 'authenticated' => 'false']);
            $locs = array_values(array_unique(array_merge([$lang], array_keys($au['names']))));
            $names = array_values(array_filter($locs, fn ($l) => !empty($au['names'][$l])));
            $holder = count($names) > 1 ? $this->el($c, 'name-alternatives') : $c;
            foreach ($names as $l) {
                $n = $this->el($holder, 'name', null, ['xml:lang' => $l, 'name-style' => $l === 'ar' ? 'eastern' : 'western']);
                if (!empty($au['family'][$l])) $this->el($n, 'surname', $au['family'][$l]);
                $gn = (string) ($au['given'][$l] ?? '');
                if ($gn === '' && empty($au['family'][$l])) $gn = $au['names'][$l];
                if ($gn !== '') $this->el($n, 'given-names', $gn);
            }
            if ($au['email']) $this->el($c, 'email', $au['email']);
            if ($au['affiliations']) {
                $key = json_encode([$au['affiliations'], $au['ror'], $au['country']]);
                if (!isset($affs[$key])) $affs[$key] = ['id' => 'aff' . (count($affs) + 1), 'a' => $au];
                $this->el($c, 'xref', null, ['ref-type' => 'aff', 'rid' => $affs[$key]['id']]);
            }
        }
        foreach ($affs as $af) {
            $e = $this->el($am, 'aff', null, ['id' => $af['id']]);
            $w = $this->el($e, 'institution-wrap');
            if ($af['a']['ror']) $this->el($w, 'institution-id', $af['a']['ror'], ['institution-id-type' => 'ror']);
            foreach ($af['a']['affiliations'] as $l => $name) $this->el($w, 'institution', $name, ['xml:lang' => $l]);
            if ($af['a']['country']) $this->el($e, 'country', $af['a']['country'], ['country' => $af['a']['country']]);
        }
        // التواريخ والعدد
        if ($m['datePublished'] || ($m['issue']['datePublished'] ?? '')) { $pd = $this->el($am, 'pub-date', null, ['publication-format' => 'electronic', 'date-type' => 'pub']); self::date($pd, $m['datePublished'] ?: $m['issue']['datePublished'], $this); }
        if ($m['issue']) {
            if ($m['issue']['volume'] !== '') $this->el($am, 'volume', $m['issue']['volume']);
            if ($m['issue']['number'] !== '') $this->el($am, 'issue', $m['issue']['number']);
        }
        if ($m['firstPage'] !== '') $this->el($am, 'fpage', $m['firstPage']);
        if ($m['lastPage'] !== '') $this->el($am, 'lpage', $m['lastPage']);
        if ($m['dateSubmitted'] || $m['dateAccepted']) {
            $h = $this->el($am, 'history');
            if ($m['dateSubmitted']) { $x = $this->el($h, 'date', null, ['date-type' => 'received']); self::date($x, $m['dateSubmitted'], $this); }
            if ($m['dateAccepted']) { $x = $this->el($h, 'date', null, ['date-type' => 'accepted']); self::date($x, $m['dateAccepted'], $this); }
        }
        $pm = $this->el($am, 'permissions');
        if ($m['copyrightYear']) $this->el($pm, 'copyright-year', $m['copyrightYear']);
        if ($m['copyrightHolder']) $this->el($pm, 'copyright-holder', $m['copyrightHolder']);
        if ($m['license']) { $li = $this->el($pm, 'license', null, ['xlink:href' => $m['license']]); $this->el($li, 'license-p', $m['license']); }
        if ($m['url']) $this->el($am, 'self-uri', null, ['xlink:href' => $m['url']]);
        foreach ($m['galleys'] as $g) if ($g['pdf'] && $g['download']) $this->el($am, 'self-uri', null, ['xlink:href' => $g['download'], 'content-type' => 'pdf']);
        foreach ([$lang, $other] as $i => $l) {
            if (empty($m['abstracts'][$l])) continue;
            $ab = $this->el($am, $i === 0 ? 'abstract' : 'trans-abstract', null, ['xml:lang' => $l]);
            foreach (preg_split("/\n+/", $m['abstracts'][$l]) as $para) if (trim($para) !== '') $this->el($ab, 'p', trim($para));
        }
        foreach ([$lang, $other] as $l) {
            if (empty($m['keywords'][$l])) continue;
            $kg = $this->el($am, 'kwd-group', null, ['xml:lang' => $l, 'kwd-group-type' => 'author']);
            foreach ($m['keywords'][$l] as $k) $this->el($kg, 'kwd', $k);
        }

        // ---- body
        $body = $this->el($a, 'body');
        if (!empty($conv['body']) && !$this->frag($body, $conv['body'])) $this->el($body, 'p', '[body conversion error]');

        // ---- back
        $refs = $m['citations'] ?: ($conv['refs'] ?? []);
        if ($refs || !empty($conv['footnotes'])) {
            $back = $this->el($a, 'back');
            if ($refs) {
                $rl = $this->el($back, 'ref-list'); $this->el($rl, 'title', $lang === 'ar' ? 'المصادر والمراجع' : 'References');
                foreach (array_values($refs) as $i => $r) {
                    $ref = $this->el($rl, 'ref', null, ['id' => 'R' . ($i + 1)]);
                    $mc = $this->el($ref, 'mixed-citation', $r, ['publication-type' => 'other']);
                    if (preg_match('~(?:doi\.org/|doi:\s*)(10\.\d{4,9}/[^\s<>"]+)~i', $r, $dm)) { $this->el($mc, 'pub-id', rtrim($dm[1], '.,;)'), ['pub-id-type' => 'doi']); }
                }
            }
            if (!empty($conv['footnotes'])) {
                $fg = $this->el($back, 'fn-group');
                foreach ($conv['footnotes'] as $id => $ps) { $fn = $this->el($fg, 'fn', null, ['id' => 'fn' . $id]); $this->el($fn, 'label', (string) $id); foreach ($ps as $p) $this->frag($this->el($fn, 'p'), $p); }
            }
        }
        return $this->d->saveXML();
    }

    /** فحوص البنية والاكتمال: [مفتاح => [ok, مطلوب?]] */
    public static function validate(string $xml): array
    {
        $d = new \DOMDocument();
        $ok = @$d->loadXML($xml, LIBXML_NONET);
        $out = ['wellFormed' => [$ok, true]];
        if (!$ok) return $out;
        $x = new \DOMXPath($d);
        $has = fn ($q) => $x->query($q)->length > 0;
        $out += [
            'journalTitle' => [$has('//journal-meta/journal-title-group/journal-title'), true],
            'issn' => [$has('//journal-meta/issn'), true],
            'articleTitle' => [$has('//article-meta/title-group/article-title[normalize-space()]'), true],
            'transTitle' => [$has('//article-meta/title-group/trans-title-group'), false],
            'contrib' => [$has('//contrib-group/contrib'), true],
            'aff' => [$has('//article-meta/aff'), true],
            'orcid' => [$has('//contrib-id[@contrib-id-type="orcid"]'), false],
            'doi' => [$has('//article-id[@pub-id-type="doi"]'), false],
            'pubDate' => [$has('//article-meta/pub-date'), true],
            'volIssue' => [$has('//article-meta/volume') && $has('//article-meta/issue'), false],
            'pages' => [$has('//article-meta/fpage'), false],
            'abstract' => [$has('//article-meta/abstract'), true],
            'transAbstract' => [$has('//article-meta/trans-abstract'), false],
            'kwd' => [$has('//kwd-group/kwd'), false],
            'license' => [$has('//permissions/license'), false],
            'body' => [$has('//body/*'), false],
            'sections' => [$has('//body/sec'), false],
            'refs' => [$has('//back/ref-list/ref'), true],
            'xrefs' => [self::xrefsOk($x), true],
        ];
        return $out;
    }

    private static function xrefsOk(\DOMXPath $x): bool
    {
        $ids = []; foreach ($x->query('//*[@id]') as $e) $ids[$e->getAttribute('id')] = true;
        foreach ($x->query('//xref[@rid]') as $e) foreach (preg_split('/\s+/', $e->getAttribute('rid')) as $r) if (!isset($ids[$r])) return false;
        return true;
    }
}
