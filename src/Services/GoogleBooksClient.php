<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

final class GoogleBooksClient
{
    private string $baseUrl = 'https://www.googleapis.com/books/v1';

    public function searchVolumes(
        string $query,
        int $maxResults = 10,
        string $lang = 'fr',
        string $country = 'FR'
    ): array {
        $q = trim($query);
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $maxResults = max(1, min(20, $maxResults));

        $params = [
            'q' => $q,
            'maxResults' => (string) $maxResults,
            'printType' => 'books',
            'orderBy' => 'relevance',
        ];

        if ($lang) {
            $params['langRestrict'] = strtolower($lang);
        }
        if ($country) {
            $params['country'] = strtoupper($country);
        }

        $key = Env::get('GOOGLE_BOOKS_API_KEY', null);
        if ($key) {
            $params['key'] = $key;
        }

        $url = $this->baseUrl . '/volumes?' . http_build_query($params);

        return $this->getJson($url);
    }

    public function searchByIsbn(
        string $isbn,
        int $maxResults = 10,
        string $lang = 'fr',
        string $country = 'FR'
    ): array {
        $isbn = preg_replace('/[^0-9Xx]/', '', trim($isbn)) ?? '';
        if ($isbn === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'isbn'], 'Invalid isbn');
        }

        return $this->searchVolumes('isbn:' . $isbn, $maxResults, $lang, $country);
    }

    public function getVolume(string $volumeId, string $lang = 'fr', string $country = 'FR'): array
    {
        $id = trim($volumeId);
        if ($id === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'googleVolumeId'], 'Invalid googleVolumeId');
        }

        $params = [];
        $key = Env::get('GOOGLE_BOOKS_API_KEY', null);
        if ($key) {
            $params['key'] = $key;
        }
        if ($country) {
            $params['country'] = strtoupper($country);
        }

        $url = $this->baseUrl . '/volumes/' . rawurlencode($id);
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->getJson($url);
    }

    private function getJson(string $url): array
    {
        $timeout = (int) Env::get('GOOGLE_BOOKS_TIMEOUT_SECONDS', '6');
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
                ],
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new HttpException(
                    502,
                    'GOOGLE_BOOKS_UNREACHABLE',
                    ['curl_errno' => $errno, 'curl_error' => $err],
                    'Google Books unreachable'
                );
            }

            return $this->decodeAndValidate($raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new HttpException(502, 'GOOGLE_BOOKS_UNREACHABLE', [], 'Google Books unreachable');
        }

        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
                    $status = (int) $m[1];
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

            if (isset($json['error']['code'])) {
                $details['googleCode'] = $json['error']['code'];
            }
            if (isset($json['error']['message'])) {
                $details['googleMessage'] = $json['error']['message'];
            }

            throw new HttpException(502, 'GOOGLE_BOOKS_ERROR', $details, 'Google Books error');
        }

        if (!is_array($json)) {
            throw new HttpException(
                502,
                'GOOGLE_BOOKS_BAD_RESPONSE',
                ['raw' => mb_substr($raw, 0, 800)],
                'Google Books bad response'
            );
        }

        return $json;
    }
}