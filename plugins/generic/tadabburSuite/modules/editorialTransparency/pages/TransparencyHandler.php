<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/editorialTransparency/pages/TransparencyHandler.php
 * المسارات العامة: /transparency (الصفحة)، /transparency/json (بيانات مفتوحة CC0)
 */
namespace APP\plugins\generic\tadabburSuite\m\editorialTransparency\pages;

use APP\handler\Handler;
use APP\plugins\generic\tadabburSuite\m\editorialTransparency\classes\StatsBuilder;
use APP\plugins\generic\tadabburSuite\m\editorialTransparency\EditorialTransparencyPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Role;

class TransparencyHandler extends Handler
{
    private const TTL = 43200; // 12 ساعة

    public function __construct(private EditorialTransparencyPlugin $plugin) { parent::__construct(); }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function t(string $k, array $p = []): string { return __('plugins.generic.editorialTransparency.' . $k, $p); }

    private function periods(): array
    {
        $y = (int) date('Y');
        $p = ['last12' => ['start' => date('Y-m-d', strtotime('-12 months')), 'end' => date('Y-m-d')], 'all' => []];
        for ($i = 0; $i < 5; $i++) $p[(string) ($y - $i)] = ['start' => ($y - $i) . '-01-01', 'end' => ($y - $i) . '-12-31'];
        return $p;
    }

    private function isManager($request): bool
    {
        $u = $request->getUser(); $c = $request->getContext();
        return $u && $c && ($u->hasRole([Role::ROLE_ID_MANAGER], (int) $c->getId()) || $u->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID));
    }

    /** JSON مفتوح (CC0): /transparency/json?period=last12 */
    public function json($args, $request)
    {
        $periodKey = array_key_exists((string) $request->getUserVar('period'), $this->periods()) ? (string) $request->getUserVar('period') : 'last12';
        $d = $this->dataFor($request, $periodKey, 'en');
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');
        $s = $d['stats'];
        unset($s['countries']);
        echo json_encode([
            'journal' => $request->getContext()->getLocalizedName(), 'issn' => array_values(array_filter([(string) $request->getContext()->getData('onlineIssn'), (string) $request->getContext()->getData('printIssn')])),
            'period' => $d['period'], 'range' => $d['range'], 'generatedAt' => gmdate('c', $d['generatedAt']), 'license' => 'CC0-1.0',
            'indicators' => $s, 'countries' => array_map(fn ($c) => ['code' => $c['code'], 'count' => $c['count']], $d['stats']['countries']), 'trend' => $d['trend'],
            'methodology' => 'Rates follow OJS editorial statistics (submissions in period with a final decision). Times are medians in days; P80 = 80th percentile.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** يحسب البيانات مع تخزين مؤقت 12 ساعة في إعدادات الإضافة */
    private function dataFor($request, string $periodKey, string $locale): array
    {
        $cid = (int) $request->getContext()->getId();
        $key = 'cache_' . md5($periodKey . '|' . $locale);
        $cached = json_decode((string) $this->plugin->getSetting($cid, $key), true);
        $refresh = $request->getUserVar('refresh') && $this->isManager($request);
        if (!$refresh && is_array($cached) && ($cached['generatedAt'] ?? 0) > time() - self::TTL) return $cached;
        $b = new StatsBuilder($cid);
        $d = ['period' => $periodKey, 'range' => $this->periods()[$periodKey], 'stats' => $b->build($this->periods()[$periodKey], $locale), 'trend' => $b->trend(5, $locale), 'generatedAt' => time()];
        $this->plugin->updateSetting($cid, $key, json_encode($d, JSON_UNESCAPED_UNICODE));
        return $d;
    }

    // ------------------------------------------------------------------ الصفحة العامة
    public function index($args, $request)
    {
        $locale = Locale::getLocale();
        $periods = $this->periods();
        $periodKey = array_key_exists((string) $request->getUserVar('period'), $periods) ? (string) $request->getUserVar('period') : 'last12';
        $d = $this->dataFor($request, $periodKey, $locale);
        $s = $d['stats'];
        $u = fn (array $p) => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, EditorialTransparencyPlugin::PAGE, 'index', null, $p);
        $pc = fn ($v) => $v === null ? '—' : round($v * 100) . '%';
        $dy = fn ($v) => $v === null ? '—' : $this->t('days', ['n' => (string) (int) round((float) $v)]);
        $n = fn ($v) => $v === null ? '—' : number_format((int) $v);

        $h = '<style>' . $this->css() . '</style><div class="etp">';
        $h .= '<p class="etp_lead">' . $this->t('lead') . '</p>';
        // الفترات
        $h .= '<nav class="etp_periods" aria-label="' . self::e($this->t('period')) . '">';
        foreach (array_keys($periods) as $k) {
            $h .= '<a href="' . self::e($u(['period' => $k])) . '"' . ($k === $periodKey ? ' aria-current="page" class="is-current"' : '') . '>' . self::e($k === 'last12' ? $this->t('last12') : ($k === 'all' ? $this->t('allTime') : $k)) . '</a>';
        }
        $h .= '</nav>';

        // البطاقات
        $card = fn ($val, $label, $sub = '', $cls = '') => '<div class="etp_card ' . $cls . '"><div class="etp_val">' . $val . '</div><div class="etp_lbl">' . $label . '</div>' . ($sub !== '' ? '<div class="etp_sub">' . $sub . '</div>' : '') . '</div>';
        $h .= '<h2>' . $this->t('h.decisions') . '</h2><div class="etp_grid">'
            . $card($n($s['received']), $this->t('received'))
            . $card($pc($s['acceptanceRate']), $this->t('acceptanceRate'), $this->t('acceptedN', ['n' => $s['accepted']]), 'is-accent')
            . $card($pc($s['deskRate']), $this->t('deskRate'), $this->t('declinedN', ['n' => $s['declinedDesk']]))
            . $card($pc($s['reviewRejectRate']), $this->t('reviewRejectRate'), $this->t('declinedN', ['n' => $s['declinedReview']]))
            . '</div>';
        $h .= '<h2>' . $this->t('h.time') . '</h2><div class="etp_grid">'
            . $card($dy($s['daysFirstMedian']), $this->t('daysFirst'), $s['daysFirstP80'] !== null ? $this->t('p80', ['v' => $dy($s['daysFirstP80'])]) : '')
            . $card($dy($s['reviewTurnMedian']), $this->t('reviewTurn'), $s['reviewTurnP80'] !== null ? $this->t('p80', ['v' => $dy($s['reviewTurnP80'])]) : '')
            . $card($dy($s['daysAcceptMedian']), $this->t('daysAccept'), $s['daysAcceptP80'] !== null ? $this->t('p80', ['v' => $dy($s['daysAcceptP80'])]) : '')
            . $card($dy($s['daysAcceptToPubMedian']), $this->t('daysAcceptToPub'), $s['daysSubToPubMedian'] !== null ? $this->t('subToPub', ['v' => $dy($s['daysSubToPubMedian'])]) : '')
            . '</div>';
        $h .= '<h2>' . $this->t('h.review') . '</h2><div class="etp_grid">'
            . $card($n($s['reviewsCompleted']), $this->t('reviewsCompleted'))
            . $card($s['reviewsPerArticle'] ?? '—', $this->t('reviewsPerArticle'))
            . $card($n($s['reviewers']), $this->t('reviewers'), $s['reviewerCountries'] ? $this->t('fromCountries', ['n' => $s['reviewerCountries']]) : '')
            . $card($pc($s['invitationAcceptRate']), $this->t('invitationAccept'))
            . '</div>';
        $h .= '<h2>' . $this->t('h.output') . '</h2><div class="etp_grid">'
            . $card($n($s['published']), $this->t('published'), $this->t('issuesN', ['n' => $s['issues']]))
            . $card($n($s['countryCount']), $this->t('authorCountries'))
            . ($s['views'] ? $card($n($s['views']), $this->t('views')) . $card($n($s['downloads']), $this->t('downloads')) : '')
            . '</div>';

        // الاتجاه السنوي
        $h .= '<h2>' . $this->t('h.trend') . '</h2>' . $this->trendChart($d['trend']);
        // الجغرافيا
        if ($s['countries']) $h .= '<h2>' . $this->t('h.geo') . '</h2>' . $this->geoChart($s['countries']);

        // المنهجية
        $range = $d['range'] ? $d['range']['start'] . ' — ' . $d['range']['end'] : $this->t('allTime');
        $json = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, EditorialTransparencyPlugin::PAGE, 'json', null, ['period' => $periodKey]);
        $h .= '<details class="etp_method"><summary>' . $this->t('h.method') . '</summary><ul>'
            . '<li>' . $this->t('m.source') . '</li><li>' . $this->t('m.rates') . '</li><li>' . $this->t('m.times') . '</li><li>' . $this->t('m.review') . '</li><li>' . $this->t('m.geo') . '</li><li>' . $this->t('m.metrics') . '</li></ul></details>'
            . '<p class="etp_meta">' . $this->t('meta', ['range' => self::e($range), 'date' => gmdate('Y-m-d H:i', $d['generatedAt']) . ' UTC'])
            . ' · <a href="' . self::e($json) . '">JSON (CC0)</a></p>'
            . '<p class="etp_meta">' . $this->t('credit') . ' <a href="https://github.com/mostafamr2011" rel="noopener" target="_blank">' . $this->t('developer') . '</a> · <a href="https://khibrattaibah.com" rel="noopener" target="_blank">' . $this->t('office') . '</a></p></div>';

        $tm = TemplateManager::getManager($request);
        $this->setupTemplate($request);
        $tm->assign(['etpTitle' => $this->t('title'), 'etpHtml' => $h]);
        $tm->display($this->plugin->getTemplateResource('public.tpl'));
    }

    private function trendChart(array $trend): string
    {
        $series = [['received', 'var(--etp-c3,#3d4170)'], ['accepted', 'var(--etp-c2,#c5a158)'], ['published', 'var(--etp-c1,#7a4a1e)']];
        $max = max(1, ...array_map(fn ($r) => max($r['received'], $r['accepted'], $r['published']), $trend));
        $W = 640; $H = 240; $pad = 30; $gw = ($W - 2 * $pad) / max(1, count($trend)); $bw = min(26, ($gw - 18) / 3);
        $svg = '<svg viewBox="0 0 ' . $W . ' ' . ($H + 30) . '" role="img" aria-label="' . self::e($this->t('h.trend')) . '" class="etp_svg" direction="ltr">';
        for ($g = 0; $g <= 4; $g++) {
            $y = $pad + ($H - 2 * $pad) * $g / 4; $v = round($max * (1 - $g / 4));
            $svg .= '<line x1="' . $pad . '" x2="' . ($W - $pad) . '" y1="' . $y . '" y2="' . $y . '" class="etp_grid_l"/><text x="' . ($pad - 6) . '" y="' . ($y + 4) . '" text-anchor="end" class="etp_axis">' . $v . '</text>';
        }
        foreach ($trend as $i => $r) {
            $x0 = $pad + $gw * $i + ($gw - 3 * $bw - 8) / 2;
            foreach ($series as $k => [$key, $col]) {
                $hgt = ($H - 2 * $pad) * $r[$key] / $max; $x = $x0 + $k * ($bw + 4); $y = $H - $pad - $hgt;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round(max(0, $hgt), 1) . '" rx="3" fill="' . $col . '"><title>' . $r['year'] . ' — ' . self::e($this->t($key)) . ': ' . $r[$key] . '</title></rect>';
                if ($r[$key]) $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 4, 1) . '" text-anchor="middle" class="etp_num">' . $r[$key] . '</text>';
            }
            $svg .= '<text x="' . round($pad + $gw * $i + $gw / 2, 1) . '" y="' . ($H - $pad + 18) . '" text-anchor="middle" class="etp_axis">' . $r['year'] . '</text>';
        }
        $svg .= '</svg>';
        $legend = '<ul class="etp_legend">';
        foreach ($series as [$key, $col]) $legend .= '<li><span style="background:' . $col . '"></span>' . self::e($this->t($key)) . '</li>';
        $legend .= '</ul>';
        // جدول مكافئ لسهولة الوصول
        $tbl = '<details class="etp_tbl"><summary>' . $this->t('asTable') . '</summary><table><thead><tr><th>' . $this->t('year') . '</th><th>' . $this->t('received') . '</th><th>' . $this->t('accepted') . '</th><th>' . $this->t('declined') . '</th><th>' . $this->t('published') . '</th><th>' . $this->t('acceptanceRate') . '</th></tr></thead><tbody>';
        foreach ($trend as $r) $tbl .= '<tr><td>' . $r['year'] . '</td><td>' . $r['received'] . '</td><td>' . $r['accepted'] . '</td><td>' . $r['declined'] . '</td><td>' . $r['published'] . '</td><td>' . round($r['acceptanceRate'] * 100) . '%</td></tr>';
        $tbl .= '</tbody></table></details>';
        return '<div class="etp_chart">' . $legend . $svg . '</div>' . $tbl;
    }

    private function geoChart(array $countries): string
    {
        $top = array_slice($countries, 0, 10);
        $rest = array_sum(array_map(fn ($c) => $c['count'], array_slice($countries, 10)));
        if ($rest) $top[] = ['name' => $this->t('others'), 'count' => $rest, 'code' => ''];
        $total = array_sum(array_map(fn ($c) => $c['count'], $countries)); $max = max(1, ...array_map(fn ($c) => $c['count'], $top));
        $o = '<ul class="etp_bars">';
        foreach ($top as $c) {
            $o .= '<li><span class="etp_bar_l">' . self::e($c['name']) . '</span><span class="etp_bar_t"><span class="etp_bar_f" style="width:' . round(100 * $c['count'] / $max) . '%"></span></span><span class="etp_bar_v">' . $c['count'] . ' <small>(' . round(100 * $c['count'] / $total) . '%)</small></span></li>';
        }
        return $o . '</ul>';
    }

    private function css(): string
    {
        return '.etp{--etp-c1:#7a4a1e;--etp-c2:#c5a158;--etp-c3:#3d4170;--etp-line:#e7dfd2;--etp-soft:#faf7f1}'
            . '.etp_lead{font-size:1.05rem;color:#4a423b;max-width:75ch}.etp h2{font-size:1.2rem;margin:1.8rem 0 .7rem;padding-bottom:.35rem;border-bottom:2px solid var(--etp-line)}'
            . '.etp_periods{display:flex;flex-wrap:wrap;gap:.4rem;margin:1rem 0}.etp_periods a{padding:.35rem .9rem;border:1px solid var(--etp-line);border-radius:999px;text-decoration:none;color:inherit;background:#fff}'
            . '.etp_periods a.is-current{background:var(--etp-c1);border-color:var(--etp-c1);color:#fff;font-weight:700}.etp_periods a:focus-visible{outline:3px solid var(--etp-c2);outline-offset:2px}'
            . '.etp_grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem}.etp_card{background:var(--etp-soft);border:1px solid var(--etp-line);border-top:3px solid var(--etp-c3);border-radius:10px;padding:.85rem 1rem}'
            . '.etp_card.is-accent{border-top-color:var(--etp-c2)}.etp_val{font-size:1.7rem;font-weight:700;line-height:1.2;color:#1f1a17}.etp_lbl{font-weight:700;color:#3a322b;margin-top:.15rem}.etp_sub{font-size:.85rem;color:#6b625a}'
            . '.etp_chart{background:#fff;border:1px solid var(--etp-line);border-radius:10px;padding:.8rem}.etp_svg{width:100%;height:auto;display:block;direction:ltr}.etp_grid_l{stroke:#eee}.etp_axis{font-size:11px;fill:#6b625a}.etp_num{font-size:10px;fill:#3a322b}'
            . '.etp_legend{list-style:none;margin:0 0 .4rem;padding:0;display:flex;gap:1rem;flex-wrap:wrap;font-size:.9rem}.etp_legend span{display:inline-block;width:.8rem;height:.8rem;border-radius:3px;margin-inline-end:.35rem;vertical-align:middle}'
            . '.etp_tbl{margin:.6rem 0}.etp_tbl table{width:100%;border-collapse:collapse;font-size:.9rem}.etp_tbl th,.etp_tbl td{border:1px solid var(--etp-line);padding:.35rem .5rem;text-align:center}'
            . '.etp_bars{list-style:none;margin:0;padding:0}.etp_bars li{display:grid;grid-template-columns:minmax(110px,180px) 1fr auto;gap:.6rem;align-items:center;padding:.3rem 0}'
            . '.etp_bar_t{height:.75rem;background:#f1ece3;border-radius:999px;overflow:hidden}.etp_bar_f{display:block;height:100%;background:var(--etp-c1);border-radius:999px}.etp_bar_v{font-weight:700;white-space:nowrap}'
            . '.etp_method{margin-top:1.8rem;background:var(--etp-soft);border:1px solid var(--etp-line);border-radius:10px;padding:.8rem 1rem}.etp_method summary{font-weight:700;cursor:pointer}.etp_method li{margin:.35rem 0}'
            . '.etp_meta{font-size:.85rem;color:#6b625a;margin-top:1rem}@media print{.etp_periods{display:none}}';
    }
}
