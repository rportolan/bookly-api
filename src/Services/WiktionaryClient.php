<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

final class WiktionaryClient
{
    private string $baseUrl = 'https://fr.wiktionary.org/w/api.php';

    /**
     * @return array{term:string, definition:string}
     */
    public function getDefinitionFr(string $term): array
    {
        $t = trim($term);
        if ($t === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'term'], 'term is required');
        }

        // MediaWiki Action API: parse wikitext
        $params = [
            'action' => 'parse',
            'format' => 'json',
            'formatversion' => '2',
            'prop' => 'wikitext',
            'page' => $t,
            'redirects' => '1',
        ];

        $url = $this->baseUrl . '?' . http_build_query($params);

        $json = $this->getJson($url);

        // Si page inexistante
        if (isset($json['error'])) {
            // exemple: {"error":{"code":"missingtitle","info":"The page you specified doesn't exist"}}
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

        $def = $this->extractFirstFrenchDefinition($wikitext);

        if ($def === null || $def === '') {
            throw new HttpException(404, 'DICTIONARY_NOT_FOUND', ['term' => $t], 'Definition not found');
        }

        return [
            'term' => $t,
            'definition' => $def,
        ];
    }

    private function extractFirstFrenchDefinition(string $wikitext): ?string
    {
        // 1) Isoler la section "Français"
        // Sur fr.wiktionary, c'est généralement: "== {{langue|fr}} ==" ou "== Français =="
        $start = null;

        // Cherche l'en-tête FR
        if (preg_match('/^==\s*(\{\{langue\|fr\}\}|Français)\s*==\s*$/mi', $wikitext, $m, PREG_OFFSET_CAPTURE)) {
            $start = (int)$m[0][1];
        }
        if ($start === null) return null;

        $after = substr($wikitext, $start);

        // Coupe à la prochaine langue (prochain "== ... ==")
        $endPos = null;
        if (preg_match('/^\s*==\s*[^=].*?\s*==\s*$/m', $after, $m2, PREG_OFFSET_CAPTURE, 10)) {
            // On a matché le premier header, puis on cherche le suivant: on prend le 2e header
            // Plus simple: on cherche tous les headers et on prend le 2e
        }

        if (preg_match_all('/^\s*==\s*[^=].*?\s*==\s*$/m', $after, $all, PREG_OFFSET_CAPTURE) && count($all[0]) >= 2) {
            $endPos = (int)$all[0][1][1]; // position du 2e header dans $after
        }

        $frSection = $endPos !== null ? substr($after, 0, $endPos) : $after;

        // 2) Trouver la première définition: une ligne qui commence par "# "
        $lines = preg_split("/\r\n|\n|\r/", $frSection) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);

            // On évite #* (exemples) et on veut juste # ...
            if (preg_match('/^#(?![*:])\s+(.+)$/u', $line, $m)) {
                $raw = $m[1];

                // 3) Nettoyage léger du wikicode
                $clean = $this->cleanupWikicode($raw);

                if ($clean !== '') {
                    return $clean;
                }
            }
        }

        return null;
    }

    private function cleanupWikicode(string $s): string
    {
        $t = $s;

        // retire <ref>...</ref>
        $t = preg_replace('#<ref[^>]*>.*?</ref>#si', '', $t) ?? $t;
        $t = preg_replace('#<ref[^/]*/>#si', '', $t) ?? $t;

        // retire les templates {{...}} (simple, non récursif)
        // (suffisant pour une V1)
        $t = preg_replace('/\{\{[^{}]*\}\}/u', '', $t) ?? $t;

        // liens wiki [[mot|texte]] ou [[mot]]
        $t = preg_replace('/\[\[[^\]|]+\|([^\]]+)\]\]/u', '$1', $t) ?? $t;
        $t = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $t) ?? $t;

        // italique/bold wiki '' '' ''' '''
        $t = str_replace(["'''", "''"], '', $t);

        // HTML entities
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // espaces
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);

        // enlève ponctuation résiduelle bizarre
        $t = trim($t, " \t\n\r\0\x0B-–—");

        return $t;
    }

    private function getJson(string $url): array
    {
        $timeout = (int)Env::get('DICTIONARY_TIMEOUT_SECONDS', '6');
        $timeout = max(2, min(20, $timeout));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: Bookly-API/1.0 (dictionary feature)',
                ],
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new HttpException(
                    502,
                    'DICTIONARY_UNREACHABLE',
                    ['curl_errno' => $errno, 'curl_error' => $err],
                    'Dictionary provider unreachable'
                );
            }

            return $this->decodeAndValidate($raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "Accept: application/json\r\nUser-Agent: Bookly-API/1.0 (dictionary feature)\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new HttpException(502, 'DICTIONARY_UNREACHABLE', [], 'Dictionary provider unreachable');
        }

        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string)$h, $m) ) {
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
            throw new HttpException(
                502,
                'DICTIONARY_BAD_RESPONSE',
                ['raw' => mb_substr($raw, 0, 800)],
                'Dictionary provider bad response'
            );
        }

        return $json;
    }
}