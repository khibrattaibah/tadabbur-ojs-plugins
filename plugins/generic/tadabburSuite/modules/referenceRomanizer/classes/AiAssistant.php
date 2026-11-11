<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * اقتراح الرومنة والترجمة عبر واجهة Claude (Anthropic Messages API) — اختياري، بمفتاح يضعه مدير المجلة.
 * لا تُرسل إلا نصوص المراجع العربية، دون أي بيانات شخصية أو سرية.
 */
namespace APP\plugins\generic\tadabburSuite\m\referenceRomanizer\classes;

use APP\core\Application;

class AiAssistant
{
    public function __construct(private string $apiKey, private string $model, private string $endpoint = 'https://api.anthropic.com/v1/messages', private bool $simple = false) {}

    /**
     * @param string[] $refs
     * @return array<int, array{romanized:string, translation:string}>
     */
    public function suggest(array $refs): array
    {
        $list = [];
        foreach (array_values($refs) as $i => $r) $list[] = ($i + 1) . '. ' . $r;
        $style = $this->simple ? 'simplified Latin transliteration without diacritics (e.g. al-Tabari, Jami\' al-Bayan)'
            : 'ALA-LC romanization with diacritics (e.g. al-Ṭabarī, Jāmiʻ al-Bayān, Muʼassasat al-Risālah)';
        $prompt = "You are a bibliographic expert in Arabic and Islamic studies. For each Arabic reference below, return:\n"
            . "1) \"romanized\": the full reference transliterated using $style. Keep the original order and punctuation, convert Hijri/Gregorian years (هـ = AH, م = CE), ط = ed., ج = vol., ص = p., د.ت = n.d., use English exonyms for well-known cities (Beirut, Cairo, Riyadh). Do not invent information.\n"
            . "2) \"translation\": an English translation of the work's title only (title of book/article/thesis), in title case.\n"
            . "Answer with JSON only: an array of objects {\"i\": number, \"romanized\": string, \"translation\": string}, one per reference, same numbering.\n\nReferences:\n" . implode("\n", $list);
        $client = Application::get()->getHttpClient();
        $res = $client->request('POST', $this->endpoint, [
            'headers' => ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
            'json' => ['model' => $this->model, 'max_tokens' => 8000, 'messages' => [['role' => 'user', 'content' => $prompt]]],
            'timeout' => 120,
        ]);
        $body = json_decode((string) $res->getBody(), true);
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
        if (!preg_match('/\[[\s\S]*\]/u', $text, $m)) throw new \RuntimeException('Unexpected AI response.');
        $arr = json_decode($m[0], true);
        if (!is_array($arr)) throw new \RuntimeException('Invalid JSON from AI.');
        $out = [];
        foreach ($arr as $o) {
            $i = (int) ($o['i'] ?? 0) - 1;
            if ($i >= 0) $out[$i] = ['romanized' => trim((string) ($o['romanized'] ?? '')), 'translation' => trim((string) ($o['translation'] ?? ''))];
        }
        return $out;
    }
}
