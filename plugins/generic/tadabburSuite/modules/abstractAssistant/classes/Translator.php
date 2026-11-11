<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/abstractAssistant/classes/Translator.php
 * عميل Claude Messages API لترجمة العنوان والملخص والكلمات المفتاحية أو تدقيقها لغويًا.
 * يُرسل البيانات الوصفية فقط (لا ملف البحث)، ويُطلب الرد بصيغة JSON.
 */
namespace APP\plugins\generic\tadabburSuite\m\abstractAssistant\classes;

use APP\core\Application;

class Translator
{
    public const LANG = ['ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'tr' => 'Turkish', 'ur' => 'Urdu', 'ms' => 'Malay', 'id' => 'Indonesian', 'fa' => 'Persian', 'de' => 'German', 'es' => 'Spanish'];

    public function __construct(private string $apiKey, private string $model, private string $endpoint, private string $journal, private string $field, private array $glossary = []) {}

    public static function langName(string $locale): string
    {
        return self::LANG[substr($locale, 0, 2)] ?? $locale;
    }

    private function system(): string
    {
        $g = '';
        if ($this->glossary) {
            $g = "\n\nMandatory house glossary (always use these renderings):\n";
            foreach ($this->glossary as $a => $b) $g .= "- $a = $b\n";
        }
        return "You are a senior academic editor and translator for the peer-reviewed journal \"{$this->journal}\" ({$this->field}). "
            . "You produce publication-ready metadata that meets the standards of international indexes (Scopus, Web of Science).\n"
            . "Principles:\n"
            . "- Be faithful: never add claims, results or information absent from the source; never omit substantive content.\n"
            . "- Academic register, clear and concise; keep the abstract's structure (purpose, method, findings, conclusion) and similar length.\n"
            . "- Quranic verses: in English, render the meaning in quotation marks followed by the reference in the form (Q 2:255); in Arabic, keep the Quranic text exactly as in the source.\n"
            . "- Arabic technical terms in English: use ALA-LC transliteration with diacritics on first use, optionally followed by a short gloss, e.g. tadabbur (reflective contemplation), tafsīr.\n"
            . "- Personal names of classical scholars: standard ALA-LC forms (al-Ṭabarī, Ibn Kathīr). Hijri dates: add AH.\n"
            . "- Keywords: 3 to 7 precise terms; English keywords in Title Case without trailing punctuation.\n"
            . "- Output JSON only, no commentary outside the JSON." . $g;
    }

    /**
     * @param array{title:string, subtitle:string, abstract:string, keywords:string[]} $src
     * @param array{title:string, subtitle:string, abstract:string, keywords:string[]}|null $existing النص الموجود في اللغة الهدف (للتدقيق)
     * @return array{data:array, model:string, in:int, out:int}
     */
    public function run(string $mode, string $from, string $to, array $src, ?array $existing = null): array
    {
        $fmt = '{"title": string, "subtitle": string, "abstract": string, "keywords": [string], "notes": [string]}';
        if ($mode === 'polish') {
            $task = "Language-edit the following " . self::langName($to) . " metadata so it reads as polished, idiomatic academic " . self::langName($to) . ". "
                . "Correct grammar, terminology, transliteration and clarity without changing the meaning. Use the " . self::langName($from) . " original (also given) to check accuracy and fix mistranslations. "
                . "In \"notes\" list each substantive change or problem found (in Arabic), briefly.\n\n"
                . "=== " . self::langName($to) . " version to edit ===\n" . json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                . "\n\n=== " . self::langName($from) . " original ===\n" . json_encode($src, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $task = "Translate the following article metadata from " . self::langName($from) . " into " . self::langName($to) . ". "
                . "In \"notes\" list (in Arabic) any terms, verses or names the editor should double-check, briefly. Leave \"subtitle\" empty if there is none.\n\n"
                . json_encode($src, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        $task .= "\n\nReturn exactly this JSON shape: $fmt";

        $res = Application::get()->getHttpClient()->request('POST', $this->endpoint, [
            'headers' => ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
            'json' => ['model' => $this->model, 'max_tokens' => 4000, 'system' => $this->system(), 'messages' => [['role' => 'user', 'content' => $task]]],
            'timeout' => 120, 'http_errors' => false,
        ]);
        $body = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() !== 200) {
            $msg = (string) ($body['error']['message'] ?? ('HTTP ' . $res->getStatusCode()));
            throw new \RuntimeException(mb_substr($msg, 0, 300));
        }
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
        $data = self::parse($text);
        return ['data' => $data, 'model' => (string) ($body['model'] ?? $this->model), 'in' => (int) ($body['usage']['input_tokens'] ?? 0), 'out' => (int) ($body['usage']['output_tokens'] ?? 0)];
    }

    public static function parse(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s === false || $e === false || $e < $s) throw new \RuntimeException('Unexpected AI response.');
        $d = json_decode(substr($text, $s, $e - $s + 1), true);
        if (!is_array($d)) throw new \RuntimeException('Invalid JSON from AI.');
        $kw = array_values(array_filter(array_map(fn ($k) => trim(strip_tags((string) $k), " \t\n.;،,"), (array) ($d['keywords'] ?? []))));
        return [
            'title' => trim(strip_tags((string) ($d['title'] ?? ''))),
            'subtitle' => trim(strip_tags((string) ($d['subtitle'] ?? ''))),
            'abstract' => trim((string) ($d['abstract'] ?? '')),
            'keywords' => array_slice($kw, 0, 10),
            'notes' => array_values(array_filter(array_map(fn ($n) => trim(strip_tags((string) $n)), (array) ($d['notes'] ?? [])))),
        ];
    }
}
