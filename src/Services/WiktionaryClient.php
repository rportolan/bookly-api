<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

final class WiktionaryClient
{
    private string $baseUrl = 'https://fr.wiktionary.org/w/api.php';

    /**
     * Returns an array of word-type groups for the French section of a Wiktionary entry.
     *
     * @return array{term:string, groups:array<array{wordType:string, gender:string|null, definitions:array<array{text:string, example:string|null}>}>}
     */
    public function getDefinitionsFr(string $term): array
    {
        $t = trim($term);
        if ($t === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'term'], 'term is required');
        }

        $params = [
            'action'        => 'parse',
            'format'        => 'json',
            'formatversion' => '2',
            'prop'          => 'wikitext',
            'page'          => $t,
            'redirects'     => '1',
        ];

        $url  = $this->baseUrl . '?' . http_build_query($params);
        $json = $this->getJson($url);

        if (isset($json['error'])) {
            $code = (string)($json['error']['code'] ?? 'unknown');
            if ($code === 'missingtitle' || $code === 'missingtitle-ns') {
                throw new HttpException(404, 'DICTIONARY_NOT_FOUND', ['term' => $t], 'Definition not found');
            }
            throw new HttpException(502, 'DICTIONARY_ERROR', $json, 'Dictionary provider error');
        }

        $wikitext = (string)($json['parse']['wikitext'] ?? '');
        if ($wikitext === '') {
            throw new HttpException(404, 'DICTIONARY_NOT_FOUND', ['term' => $t], 'Definition not found');
        }

        $groups = $this->extractFrenchGroups($wikitext);

        if (empty($groups)) {
            throw new HttpException(404, 'DICTIONARY_NOT_FOUND', ['term' => $t], 'Definition not found');
        }

        return ['term' => $t, 'groups' => $groups];
    }

    /**
     * Parse the French section into groups per word type.
     * Each group has: wordType, gender, definitions[]{text, example}
     */
    private function extractFrenchGroups(string $wikitext): array
    {
        // Isolate the French section (== {{langue|fr}} == or == Français ==)
        if (!preg_match('/^==\s*(\{\{langue\|fr\}\}|Fran[çc]ais)\s*==\s*$/mi', $wikitext, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $start = (int)$m[0][1];
        $after = substr($wikitext, $start);

        // Cut at the next top-level language section (== ... ==) that is not the first
        if (preg_match_all('/^==\s*[^=].*?==\s*$/m', $after, $all, PREG_OFFSET_CAPTURE) && count($all[0]) >= 2) {
            $after = substr($after, 0, (int)$all[0][1][1]);
        }

        $lines  = preg_split("/\r\n|\n|\r/", $after) ?: [];
        $groups = [];

        $currentType   = null;
        $currentGender = null;
        $currentDefs   = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // === {{S|word_type|fr}} === or === {{S|word_type|fr|num=2}} ===
            if (preg_match('/^===\s*\{\{S\|([^|}\s]+)/u', $trimmed, $sm)) {
                // Save previous group
                if ($currentType !== null && !empty($currentDefs)) {
                    $groups[] = [
                        'wordType'    => $currentType,
                        'gender'      => $currentGender,
                        'definitions' => $currentDefs,
                    ];
                }
                $currentType   = $this->normalizeWordType($sm[1]);
                $currentGender = null;
                $currentDefs   = [];
                continue;
            }

            if ($currentType === null) {
                continue;
            }

            // Gender from {{m}}, {{f}}, {{mf}}, {{n}} on a line starting with "'''"
            if ($currentGender === null && preg_match('/\{\{(mf?|f|n)\}\}/u', $trimmed, $gm)) {
                $currentGender = $this->normalizeGender($gm[1]);
            }

            // Definition line: # text (not #: #* ## etc.)
            if (preg_match('/^#(?![#*:])\s*(.+)$/u', $trimmed, $dm)) {
                $text = $this->cleanupWikicode($dm[1]);
                if ($text !== '') {
                    $currentDefs[] = ['text' => $text];
                }
                continue;
            }
        }

        // Flush last group
        if ($currentType !== null && !empty($currentDefs)) {
            $groups[] = [
                'wordType'    => $currentType,
                'gender'      => $currentGender,
                'definitions' => $currentDefs,
            ];
        }

        return $groups;
    }

    private function normalizeWordType(string $raw): string
    {
        $map = [
            'nom'          => 'nom',
            'nom-pr'       => 'nom propre',
            'verb'         => 'verbe',
            'verbe'        => 'verbe',
            'adj'          => 'adjectif',
            'adjectif'     => 'adjectif',
            'adv'          => 'adverbe',
            'adverbe'      => 'adverbe',
            'prep'         => 'préposition',
            'préposition'  => 'préposition',
            'conj'         => 'conjonction',
            'conjonction'  => 'conjonction',
            'inter'        => 'interjection',
            'interjection' => 'interjection',
            'pron'         => 'pronom',
            'pronom'       => 'pronom',
            'art'          => 'article',
            'article'      => 'article',
            'loc-nom'      => 'locution nominale',
            'loc-verb'     => 'locution verbale',
            'loc-adj'      => 'locution adjectivale',
            'loc-adv'      => 'locution adverbiale',
        ];

        $lower = mb_strtolower($raw);
        return $map[$lower] ?? $lower;
    }

    private function normalizeGender(string $raw): string
    {
        return match ($raw) {
            'm'  => 'masculin',
            'f'  => 'féminin',
            'mf' => 'masculin/féminin',
            'n'  => 'neutre',
            default => $raw,
        };
    }

    private function cleanupWikicode(string $s): string
    {
        $t = $s;

        $t = preg_replace('#<ref[^>]*>.*?</ref>#si', '', $t) ?? $t;
        $t = preg_replace('#<ref[^/]*/>#si', '', $t) ?? $t;

        // Remove templates but preserve their visible text argument if present: {{term|text}}
        // Strip {{lang|...}} style templates entirely
        $t = preg_replace('/\{\{[^{}]*\}\}/u', '', $t) ?? $t;

        // Wiki links [[word|display]] → display, [[word]] → word
        $t = preg_replace('/\[\[[^\]|]+\|([^\]]+)\]\]/u', '$1', $t) ?? $t;
        $t = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $t) ?? $t;

        // Bold/italic
        $t = str_replace(["'''", "''"], '', $t);

        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t, " \t\n\r\0\x0B-–—");

        return trim($t);
    }

    private function getJson(string $url): array
    {
        $timeout = (int)Env::get('DICTIONARY_TIMEOUT_SECONDS', '6');
        $timeout = max(2, min(20, $timeout));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'User-Agent: Bookly-API/1.0 (dictionary feature)',
                ],
            ]);

            $raw   = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err   = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new HttpException(502, 'DICTIONARY_UNREACHABLE', ['curl_errno' => $errno, 'curl_error' => $err], 'Dictionary provider unreachable');
            }

            return $this->decodeAndValidate($raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => "Accept: application/json\r\nUser-Agent: Bookly-API/1.0 (dictionary feature)\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new HttpException(502, 'DICTIONARY_UNREACHABLE', [], 'Dictionary provider unreachable');
        }

        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string)$h, $m)) {
                    $status = (int)$m[1];
                    break;
                }
            }
        }

        return $this->decodeAndValidate($raw, $status);
    }

    private function decodeAndValidate(string $raw, int $status): array
    {
        $json = json_decode($raw, true);

        if ($status >= 400) {
            $details = is_array($json) ? $json : ['raw' => mb_substr($raw, 0, 800)];
            throw new HttpException(502, 'DICTIONARY_ERROR', $details, 'Dictionary provider error');
        }

        if (!is_array($json)) {
            throw new HttpException(502, 'DICTIONARY_BAD_RESPONSE', ['raw' => mb_substr($raw, 0, 800)], 'Dictionary provider bad response');
        }

        return $json;
    }
}
