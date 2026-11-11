<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/tadabburSuite/modules/surveys/pages/SurveyHandler.php
 */
namespace APP\plugins\generic\tadabburSuite\m\surveys\pages;

use APP\plugins\generic\tadabburSuite\classes\kit\ModuleHandler;
use APP\plugins\generic\tadabburSuite\m\surveys\classes\Engine;
use APP\plugins\generic\tadabburSuite\m\surveys\classes\Store;
use Illuminate\Support\Facades\DB;

class SurveyHandler extends ModuleHandler
{
    private ?Store $st = null;
    private function st(): Store { return $this->st ??= new Store(); }
    private function survey($request, int $id): object { return $this->st()->get($id, $this->cid($request)) ?? $this->notFound(); }
    private function qs(object $s): array { return json_decode((string) $s->questions, true) ?: []; }

    // ================================================================ القائمة
    public function index($args, $request)
    {
        $this->editor($request);
        $cid = $this->cid($request);
        Engine::seed($this->st(), $cid);
        $rows = [];
        foreach ($this->st()->all($cid) as $s) {
            $x = $this->st()->stats((int) $s->survey_id);
            $score = Engine::score(Engine::aggregate($this->qs($s), $this->st()->answers((int) $s->survey_id)));
            $open = $s->trigger_on === 'open' ? ' ' . $this->btn($this->url($request, 'open', [], [(int) $s->survey_id]), '🔗 ' . $this->t('publicLink'), false, ' target="_blank"', 'sm') : '';
            $rows[] = ['<b>' . self::e($s->title) . '</b><div class="tdb-muted">' . $this->t('aud.' . $s->audience) . ' · ' . $this->t('tr.' . $s->trigger_on) . '</div>',
                $s->active ? $this->pill('ok', $this->t('active')) : $this->pill('muted', $this->t('inactive')),
                (string) $x['invited'], (string) $x['answers'] . ($x['rate'] !== null ? ' <span class="tdb-muted">(' . $x['rate'] . '%)</span>' : ''),
                $score === null ? '—' : $this->pill($score >= 75 ? 'ok' : ($score >= 50 ? 'warn' : 'bad'), $score . '/100'),
                $this->btn($this->url($request, 'report', ['id' => $s->survey_id]), '📊 ' . $this->t('report'), true, '', 'sm') . ($this->plugin->isManager($request) ? $this->btn($this->url($request, 'edit', ['id' => $s->survey_id]), $this->t('edit'), false, '', 'sm') : '') . $open];
        }
        $h = $this->head($this->t('intro'), $this->plugin->isManager($request) ? $this->btn($this->url($request, 'edit'), '＋ ' . $this->t('new'), true) : '') . $this->flash($request)
            . $this->box($this->t('list'), $this->table([$this->t('col.survey'), $this->t('col.state'), $this->t('col.invited'), $this->t('col.answers'), $this->t('col.score'), ''], $rows))
            . '<p class="tdb-muted">' . $this->t('autoHelp') . '</p>';
        $this->show($request, $this->t('menu'), $h);
    }

    // ================================================================ التحرير
    public function edit($args, $request)
    {
        $this->manager($request, false);
        $id = (int) $request->getUserVar('id');
        $s = $id ? $this->survey($request, $id) : (object) ['survey_id' => 0, 'title' => '', 'intro' => '', 'audience' => 'author', 'trigger_on' => 'manual', 'questions' => '[]', 'active' => 1];
        $aud = []; foreach (['author', 'reviewer', 'public', 'editor'] as $a) $aud[$a] = $this->t('aud.' . $a);
        $tr = []; foreach (Store::TRIGGERS as $x) $tr[$x] = $this->t('tr.' . $x);
        $form = '<form method="post" action="' . self::e($this->url($request, 'save')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $s->survey_id . '">'
            . $this->field($this->t('f.title'), '<input class="tdb-input" name="title" required maxlength="250" value="' . self::e($s->title) . '">')
            . $this->field($this->t('f.intro'), '<textarea class="tdb-input" name="intro" rows="2">' . self::e($s->intro) . '</textarea>')
            . '<div class="tdb-grid2">' . $this->field($this->t('f.audience'), $this->select('audience', $aud, $s->audience)) . $this->field($this->t('f.trigger'), $this->select('trigger_on', $tr, $s->trigger_on), $this->t('f.triggerHelp')) . '</div>'
            . $this->field($this->t('f.questions'), '<textarea class="tdb-input" name="questions" rows="10" dir="auto" style="font-family:inherit">' . self::e(Engine::unparse($this->qs($s))) . '</textarea>', $this->t('f.syntax'))
            . '<div class="tdb-checks">' . $this->toggle('active', (bool) $s->active, $this->t('active')) . '</div>'
            . '<div class="tdb-actions"><button class="tdb-btn tdb-btn--primary">' . $this->t('save') . '</button>' . $this->btn($this->url($request, 'index'), $this->t('back')) . '</div></form>';
        $inv = '';
        if ($s->survey_id) {
            $inv = $this->box($this->t('inviteTitle'), '<form method="post" action="' . self::e($this->url($request, 'invite')) . '">' . $this->csrf($request) . '<input type="hidden" name="id" value="' . (int) $s->survey_id . '">'
                . $this->field($this->t('f.group'), $this->select('group', ['' => '—', 'authors' => $this->t('g.authors'), 'reviewers' => $this->t('g.reviewers')], ''), $this->t('f.groupHelp'))
                . $this->field($this->t('f.emails'), '<textarea class="tdb-input" name="emails" rows="3" dir="ltr" placeholder="name@example.com, الاسم"></textarea>')
                . '<button class="tdb-btn tdb-btn--primary">✉ ' . $this->t('sendInvites') . '</button></form>');
        }
        $this->show($request, $this->t('menu'), $this->box($s->survey_id ? $this->t('edit') : $this->t('new'), $form) . $inv);
    }

    public function save($args, $request)
    {
        $this->manager($request);
        $qs = Engine::parse((string) $request->getUserVar('questions'));
        $title = mb_substr($this->v($request, 'title'), 0, 250);
        if ($title === '' || !$qs) $this->back($request, 'index', [], 'errEmpty');
        $aud = in_array($this->v($request, 'audience'), ['author', 'reviewer', 'public', 'editor'], true) ? $this->v($request, 'audience') : 'author';
        $tr = in_array($this->v($request, 'trigger_on'), Store::TRIGGERS, true) ? $this->v($request, 'trigger_on') : 'manual';
        $this->st()->save($this->cid($request), (int) $request->getUserVar('id') ?: null, ['title' => $title, 'intro' => mb_substr((string) $request->getUserVar('intro'), 0, 2000),
            'audience' => $aud, 'trigger_on' => $tr, 'questions' => json_encode($qs, JSON_UNESCAPED_UNICODE), 'active' => $request->getUserVar('active') ? 1 : 0]);
        $this->back($request, 'index');
    }

    public function invite($args, $request)
    {
        $this->manager($request);
        $s = $this->survey($request, (int) $request->getUserVar('id'));
        $ctx = $request->getContext(); $cid = $this->cid($request); $n = 0;
        $since = date('Y-m-d', strtotime('-12 months'));
        if ($this->v($request, 'group') === 'reviewers') {
            foreach (DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')->join('users as u', 'u.user_id', '=', 'ra.reviewer_id')
                ->where('s.context_id', $cid)->where('ra.date_completed', '>=', $since)->select('u.user_id', 'u.email')->distinct()->limit(300)->get() as $r) {
                $u = \APP\facades\Repo::user()->get((int) $r->user_id, true);
                if ($u && Engine::send($request, $ctx, $this->st(), $s, (int) $u->getId(), (string) $u->getEmail(), $u->getFullName())) $n++;
            }
        } elseif ($this->v($request, 'group') === 'authors') {
            foreach (DB::table('edit_decisions as d')->join('submissions as s', 's.submission_id', '=', 'd.submission_id')->where('s.context_id', $cid)->where('d.date_decided', '>=', $since)
                ->whereIn('d.decision', [2, 17, 6, 8])->distinct()->limit(300)->pluck('d.submission_id') as $sid)
                foreach (Engine::authors((int) $sid) as [$uid, $em, $nm]) if (Engine::send($request, $ctx, $this->st(), $s, $uid, $em, $nm, (int) $sid)) $n++;
        }
        foreach (preg_split('/\r\n|\r|\n/', (string) $request->getUserVar('emails')) as $line) {
            [$em, $nm] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
            if ($em !== '' && Engine::send($request, $ctx, $this->st(), $s, null, $em, $nm)) $n++;
        }
        $this->back($request, 'index', ['n' => $n], 'invited');
    }

    // ================================================================ التقرير
    public function report($args, $request)
    {
        $this->editor($request);
        $s = $this->survey($request, (int) $request->getUserVar('id'));
        $qs = $this->qs($s); $rows = $this->st()->answers((int) $s->survey_id);
        $agg = Engine::aggregate($qs, $rows); $x = $this->st()->stats((int) $s->survey_id); $score = Engine::score($agg);
        $h = $this->head('<b>' . self::e($s->title) . '</b> · ' . $this->t('aud.' . $s->audience), $this->btn($this->url($request, 'csv', ['id' => $s->survey_id]), '⬇ CSV') . $this->btn($this->url($request, 'index'), '← ' . $this->t('back')))
            . $this->stats([$this->stat((string) $x['answers'], $this->t('col.answers')), $this->stat((string) $x['invited'], $this->t('col.invited')),
                $this->stat($x['rate'] === null ? '—' : $x['rate'] . '%', $this->t('rate')), $this->stat($score === null ? '—' : $score . '/100', $this->t('col.score'), $score !== null && $score >= 75 ? 'ok' : '')]);
        foreach ($agg as $g) {
            $q = $g['q']; $body = '';
            if ($q['type'] === 'rating') {
                $avg = $g['n'] ? round($g['sum'] / $g['n'], 2) : 0;
                $body .= '<p><b style="font-size:22px">' . $avg . '</b> / 5 <span class="tdb-muted">(' . $g['n'] . ')</span></p>';
                for ($v = 5; $v >= 1; $v--) $body .= $this->bar(str_repeat('★', $v), $g['dist'][$v] ?? 0, $g['n']);
            } elseif ($q['type'] === 'text') {
                $body .= $g['texts'] ? '<ul class="tdb-quotes">' . implode('', array_map(fn ($t) => '<li>' . nl2br(self::e($t)) . '</li>', array_slice(array_reverse($g['texts']), 0, 60))) . '</ul>' : $this->emptyState($this->t('noAnswers'));
            } else {
                $opts = $q['type'] === 'yesno' ? ['yes', 'no'] : $q['options'];
                foreach ($opts as $o) $body .= $this->bar($q['type'] === 'yesno' ? $this->t($o) : $o, $g['dist'][$o] ?? 0, $g['n']);
            }
            $h .= $this->box(self::e($q['label']) . ' <span class="tdb-badge tdb-badge--muted">' . $this->t('type.' . $q['type']) . '</span>', $body);
        }
        $this->show($request, $this->t('menu'), $h);
    }

    private function bar(string $label, int $n, int $total): string
    {
        $p = $total ? round(100 * $n / $total) : 0;
        return '<div class="tdb-bar"><span>' . self::e($label) . '</span><i><b style="width:' . $p . '%"></b></i><span class="tdb-muted">' . $n . ' (' . $p . '%)</span></div>';
    }

    public function csv($args, $request)
    {
        $this->editor($request);
        $s = $this->survey($request, (int) $request->getUserVar('id'));
        $qs = $this->qs($s);
        $f = fopen('php://temp', 'w+'); fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, array_merge(['#', 'date'], array_map(fn ($q) => $q['label'], $qs)));
        foreach ($this->st()->answers((int) $s->survey_id) as $r) {
            $a = json_decode((string) $r->answers, true) ?: [];
            fputcsv($f, array_merge([$r->answer_id, $r->created_at], array_map(fn ($q) => is_array($a[$q['id']] ?? null) ? implode('; ', $a[$q['id']]) : (string) ($a[$q['id']] ?? ''), $qs)));
        }
        rewind($f);
        $this->download('survey-' . (int) $s->survey_id . '.csv', 'text/csv; charset=utf-8', stream_get_contents($f));
    }

    // ================================================================ التعبئة العامة
    public function fill($args, $request)
    {
        $inv = $this->st()->byToken((string) ($args[0] ?? ''));
        $s = $inv ? $this->st()->get((int) $inv->survey_id, $this->cid($request)) : null;
        if (!$inv || !$s) { http_response_code(404); $this->showPublic($request, $this->t('menu'), '<div class="tdb-alert tdb-alert--bad">' . $this->t('badLink') . '</div>'); return; }
        if (!$inv->opened_at) DB::table(Store::I)->where('invite_id', $inv->invite_id)->update(['opened_at' => date('Y-m-d H:i:s')]);
        if ($inv->completed_at) { $this->showPublic($request, $s->title, '<div class="tdb-alert tdb-alert--ok">' . $this->t('already') . '</div>'); return; }
        $this->formPage($request, $s, $this->url($request, 'fill', [], [$inv->token]), (int) $inv->invite_id);
    }

    public function open($args, $request)
    {
        $s = $this->st()->get((int) ($args[0] ?? 0), $this->cid($request));
        if (!$s || $s->trigger_on !== 'open' || !$s->active) { http_response_code(404); $this->showPublic($request, $this->t('menu'), '<div class="tdb-alert tdb-alert--bad">' . $this->t('badLink') . '</div>'); return; }
        $this->formPage($request, $s, $this->url($request, 'open', [], [(int) $s->survey_id]), null);
    }

    private function formPage($request, object $s, string $action, ?int $inviteId): void
    {
        $qs = $this->qs($s); $err = [];
        if ($request->isPost()) {
            if (!$request->checkCSRF()) { http_response_code(400); exit; }
            $ans = [];
            foreach ($qs as $q) {
                $v = $request->getUserVar($q['id']);
                if ($q['type'] === 'rating') $v = in_array((int) $v, [1, 2, 3, 4, 5], true) ? (int) $v : null;
                elseif ($q['type'] === 'yesno') $v = in_array($v, ['yes', 'no'], true) ? $v : null;
                elseif ($q['type'] === 'choice') $v = in_array($v, $q['options'], true) ? $v : null;
                elseif ($q['type'] === 'multi') $v = array_values(array_intersect((array) $v, $q['options'])) ?: null;
                else $v = trim(mb_substr((string) $v, 0, 3000)) ?: null;
                if ($q['required'] && $v === null) $err[] = $q['id'];
                $ans[$q['id']] = $v;
            }
            if (!$err) {
                if ($inviteId === null) { $k = 'tdbSurvey' . $s->survey_id; if (!empty($_COOKIE[$k])) { $this->showPublic($request, $s->title, '<div class="tdb-alert tdb-alert--ok">' . $this->t('already') . '</div>'); return; } setcookie($k, '1', time() + 86400 * 180, '/'); }
                $this->st()->addAnswer((int) $s->survey_id, $inviteId, $ans);
                $this->showPublic($request, $s->title, '<div class="tdb-alert tdb-alert--ok">' . $this->t('thanks') . '</div>');
                return;
            }
        }
        $h = '<div class="tdb-survey">' . ($s->intro ? '<p class="tdb-lead">' . nl2br(self::e($s->intro)) . '</p>' : '') . ($err ? '<div class="tdb-alert tdb-alert--bad">' . $this->t('required') . '</div>' : '')
            . '<form method="post" action="' . self::e($action) . '">' . $this->csrf($request);
        foreach ($qs as $i => $q) {
            $name = $q['id']; $cur = $request->getUserVar($name);
            $c = '';
            if ($q['type'] === 'rating') { $c = '<div class="tdb-rate">'; for ($v = 1; $v <= 5; $v++) $c .= '<label><input type="radio" name="' . $name . '" value="' . $v . '"' . ((int) $cur === $v ? ' checked' : '') . '><span>' . $v . '</span></label>'; $c .= '</div><div class="tdb-rate__legend"><span>' . $this->t('low') . '</span><span>' . $this->t('high') . '</span></div>'; }
            elseif ($q['type'] === 'yesno') foreach (['yes', 'no'] as $o) $c .= '<label class="tdb-opt"><input type="radio" name="' . $name . '" value="' . $o . '"' . ($cur === $o ? ' checked' : '') . '> ' . $this->t($o) . '</label>';
            elseif ($q['type'] === 'choice') foreach ($q['options'] as $o) $c .= '<label class="tdb-opt"><input type="radio" name="' . $name . '" value="' . self::e($o) . '"' . ($cur === $o ? ' checked' : '') . '> ' . self::e($o) . '</label>';
            elseif ($q['type'] === 'multi') foreach ($q['options'] as $o) $c .= '<label class="tdb-opt"><input type="checkbox" name="' . $name . '[]" value="' . self::e($o) . '"' . (in_array($o, (array) $cur, true) ? ' checked' : '') . '> ' . self::e($o) . '</label>';
            else $c = '<textarea class="tdb-input" name="' . $name . '" rows="3" style="width:100%">' . self::e((string) $cur) . '</textarea>';
            $h .= '<fieldset class="tdb-q' . (in_array($name, $err, true) ? ' is-err' : '') . '"><legend><span class="tdb-q__n">' . ($i + 1) . '</span> ' . self::e($q['label']) . ($q['required'] ? ' <b class="tdb-q__req">*</b>' : '') . '</legend>' . $c . '</fieldset>';
        }
        $h .= '<button class="tdb-btn tdb-btn--primary">' . $this->t('submit') . '</button></form><p class="tdb-muted">' . $this->t('privacy') . '</p></div>'
            . '<style>.tdb-survey{max-width:760px}.tdb-q{border:1px solid #e5e1d9;border-radius:12px;padding:14px 16px;margin:0 0 12px;background:#fff}.tdb-q.is-err{border-color:#a82424;background:#fdf6f6}.tdb-q legend{font-weight:700;padding:0 6px}.tdb-q__n{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:50%;background:#fbf6ea;color:#7a5b2a;font-size:12px}.tdb-q__req{color:#a82424}'
            . '.tdb-rate{display:flex;gap:8px;flex-wrap:wrap}.tdb-rate label{cursor:pointer}.tdb-rate input{position:absolute;opacity:0}.tdb-rate span{display:grid;place-items:center;width:46px;height:46px;border:1px solid #d6d0c4;border-radius:12px;font-weight:700;font-size:16px;background:#fff}.tdb-rate input:checked+span{background:#5a3a1e;color:#fff;border-color:#5a3a1e}.tdb-rate input:focus-visible+span{box-shadow:0 0 0 3px rgba(184,145,63,.4)}'
            . '.tdb-rate__legend{display:flex;justify-content:space-between;max-width:270px;font-size:12px;color:#667085;margin-top:4px}.tdb-opt{display:flex;gap:8px;align-items:center;padding:6px 0}</style>';
        $this->showPublic($request, $s->title, $h);
    }
}
