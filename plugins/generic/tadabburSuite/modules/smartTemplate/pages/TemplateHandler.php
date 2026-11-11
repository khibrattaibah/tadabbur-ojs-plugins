<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/smartTemplate/pages/TemplateHandler.php
 * عامة: blank ؛ المؤلف: mine، get ؛ المحرر: index ؛ المدير POST: saveSettings
 */
namespace APP\plugins\generic\tadabburSuite\m\smartTemplate\pages;

use APP\facades\Repo;
use APP\plugins\generic\tadabburSuite\m\smartTemplate\classes\TemplateDocx;
use APP\plugins\generic\tadabburSuite\classes\kit\Meta;
use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;

class TemplateHandler extends ModuleHandler
{
    /** بناء القالب؛ $m لقطة البحث أو null للقالب الفارغ */
    private function compose($request, ?array $m, bool $anon): string
    {
        $c = $this->plugin->conf($this->cid($request)); $j = Meta::journal($request->getContext());
        $d = new TemplateDocx($c); $H = fn ($t) => $d->hint($t);
        $jn = Meta::pick($j['names']);
        $H($this->t('d.banner', ['j' => $jn, 'min' => $c['wordsMin'], 'max' => $c['wordsMax'], 'f' => $c['fontAr'], 's' => $c['size'], 'fs' => $c['fnSize']]));
        $d->p('Title', $m['titles']['ar'] ?? $this->t('d.titleAr'));
        $d->p('TitleEn', $m['titles']['en'] ?? 'Title of the Paper in English', ['ltr' => true]);
        if ($anon) $H($this->t('d.anon'));
        else {
            foreach ($m['authors'] ?? [['names' => ['ar' => $this->t('d.author')], 'affiliations' => ['ar' => $this->t('d.aff')], 'email' => 'email@example.com', 'orcidId' => '0000-0000-0000-0000']] as $a) {
                $d->p('Authors', Meta::pick($a['names']));
                $d->p('Affiliation', trim(Meta::pick($a['affiliations']) . ($a['email'] ? ' · ' . $a['email'] : '') . ($a['orcidId'] ? ' · ORCID: ' . $a['orcidId'] : '')));
            }
        }
        $d->p('Heading1', $this->t('d.abstract'));
        $d->p('Abstract', $m['abstracts']['ar'] ?? $this->t('d.absHint', ['n' => $c['absMax']]));
        $d->p('Abstract', [[$this->t('d.kw') . ' ', ['b' => true]], [implode('، ', $m['keywords']['ar'] ?? []) ?: $this->t('d.kwHint', ['n' => $c['kwMax']])]]);
        $d->p('Heading1', 'Abstract', ['ltr' => true]);
        $d->p('AbstractEn', $m['abstracts']['en'] ?? 'Write the English abstract here (it must match the Arabic abstract).', ['ltr' => true]);
        $d->p('AbstractEn', [['Keywords: ', ['b' => true]], [implode(', ', $m['keywords']['en'] ?? []) ?: 'keyword 1, keyword 2, keyword 3']], ['ltr' => true]);
        $first = true;
        foreach (preg_split('/\r?\n/', (string) $c['headings']) as $line) {
            $line = trim($line); if ($line === '') continue;
            $lvl = str_starts_with($line, '###') ? 3 : (str_starts_with($line, '##') ? 2 : 1);
            $d->p('Heading' . $lvl, trim(ltrim($line, '#')));
            if ($first) {
                $d->p('Normal', [[$this->t('d.sampleText')], ['fn' => $this->t('d.sampleFn')], ['.']]);
                $H($this->t('d.quranHint'));
                $d->p('Quran', '﴿ كِتَٰبٌ أَنزَلۡنَٰهُ إِلَيۡكَ مُبَٰرَكٞ لِّيَدَّبَّرُوٓاْ ءَايَٰتِهِۦ ﴾ [ص: 29]');
                $first = false;
            } else $d->p('Normal', $this->t('d.write'));
        }
        $d->p('Heading1', $this->t('d.refs'));
        $H($this->t('d.refsHint'));
        $d->p('Reference', $this->t('d.ref1'));
        $d->p('Reference', 'Ibn Kathir, Ismāʿīl ibn ʿUmar. Tafsīr al-Qurʾān al-ʿAẓīm. Riyadh: Dār Ṭaybah, 1999. https://doi.org/10.xxxx/xxxx', ['ltr' => true]);
        return $d->build($m ? Meta::pick($m['titles']) : $jn, $anon || !$m ? '' : Meta::pick($m['authors'][0]['names'] ?? []));
    }

    public function blank($args, $request)
    {
        $this->download('template-' . $request->getContext()->getPath() . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $this->compose($request, null, (bool) $request->getUserVar('anon')));
    }

    public function get($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $sid = (int) $request->getUserVar('sid');
        if (!$this->plugin->isEditor($request) && !in_array($sid, $this->plugin->authorSubs($cid, (int) $request->getUser()->getId()), true)) $this->deny();
        $s = Repo::submission()->get($sid); if (!$s || (int) $s->getData('contextId') !== $cid) $this->notFound();
        $anon = (bool) $request->getUserVar('anon');
        $this->download('paper-' . $sid . ($anon ? '-anonymous' : '') . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $this->compose($request, Meta::snapshot($s, $request->getContext(), $request), $anon));
    }

    public function mine($args, $request)
    {
        $this->login($request); $cid = $this->cid($request); $rows = [];
        foreach ($this->plugin->authorSubs($cid, (int) $request->getUser()->getId()) as $sid) {
            $s = Repo::submission()->get($sid); if (!$s) continue;
            $rows[] = [(string) $sid, '<b>' . self::e(strip_tags((string) $s->getCurrentPublication()->getLocalizedFullTitle())) . '</b>',
                $this->btn($this->url($request, 'get', ['sid' => $sid]), '⬇ ' . $this->t('full'), true) . $this->btn($this->url($request, 'get', ['sid' => $sid, 'anon' => 1]), '⬇ ' . $this->t('anonymous'))];
        }
        $h = '<p style="margin-top:0;color:#444">' . $this->t('mineIntro') . '</p>' . $this->box('', $this->table(['#', $this->t('col.title'), ''], $rows))
            . $this->box($this->t('blankTitle'), $this->btn($this->url($request, 'blank'), '⬇ ' . $this->t('blank')));
        $this->show($request, $this->t('myMenu'), $h);
    }

    public function index($args, $request)
    {
        $this->editor($request); $c = $this->plugin->conf($this->cid($request));
        $pub = $this->url($request, 'blank');
        $h = '<p style="margin-top:0;color:#444">' . $this->t('intro') . '</p>' . $this->flash($request)
            . $this->box($this->t('downloads'), $this->btn($pub, '⬇ ' . $this->t('blank'), true) . $this->btn($this->url($request, 'blank', ['anon' => 1]), '⬇ ' . $this->t('blankAnon'))
                . '<p style="font-size:12px;color:#555;margin-bottom:0">' . $this->t('publicLink') . ' <code dir="ltr">' . self::e($pub) . '</code></p>');
        if ($this->plugin->isManager($request)) {
            $in = fn ($k, $w = 90, $type = 'text') => '<label style="display:inline-block;margin:4px 10px 4px 0;font-size:12px">' . $this->t('f.' . $k) . '<br><input type="' . $type . '" step="any" name="' . $k . '" value="' . self::e($c[$k]) . '" style="' . self::S_INP . 'width:' . $w . 'px"></label>';
            $h .= $this->box($this->t('settings'), '<form method="post" action="' . self::e($this->url($request, 'saveSettings')) . '">' . $this->csrf($request)
                . $in('fontAr', 200) . $in('fontEn', 170) . $in('fontQuran', 230) . '<br>' . $in('size', 70, 'number') . $in('fnSize', 70, 'number') . $in('line', 70, 'number') . $in('margin', 70, 'number')
                . '<br>' . $in('wordsMin', 90, 'number') . $in('wordsMax', 90, 'number') . $in('absMax', 80, 'number') . $in('kwMax', 70, 'number')
                . '<label style="display:block;font-size:12px;margin-top:6px">' . $this->t('f.headings') . '<br><textarea name="headings" rows="10" style="' . self::S_INP . 'width:100%">' . self::e($c['headings']) . '</textarea></label>'
                . '<button style="' . self::S_BTNP . '">' . $this->t('save') . '</button></form>');
        }
        $this->show($request, $this->t('menu'), $h);
    }

    public function saveSettings($args, $request)
    {
        $this->manager($request); $out = [];
        foreach (\APP\plugins\generic\tadabburSuite\m\smartTemplate\SmartTemplatePlugin::DEFAULTS as $k => $def) {
            $v = (string) $request->getUserVar($k);
            $out[$k] = is_int($def) ? max(0, (int) $v) : (is_float($def) ? max(0.8, min(3.0, (float) $v)) : mb_substr(trim($v), 0, $k === 'headings' ? 4000 : 80));
            if ($out[$k] === '' || $out[$k] === 0) $out[$k] = $def;
        }
        $this->plugin->updateSetting($this->cid($request), 'conf', json_encode($out, JSON_UNESCAPED_UNICODE), 'string');
        $this->back($request, 'index');
    }
}
