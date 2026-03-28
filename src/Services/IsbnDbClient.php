<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

final class IsbnDbClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Env::get('ISBNDB_BASE_URL', 'https://api2.isbndb.com'), '/');
    }

    public function searchBooks(string $query, int $pageSize = 10, int $page = 1, ?string $column = null): array
    {
        $q = trim($query);
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $pageSize = max(1, min(30, $pageSize));
        $page = max(1, $page);

        $params = [
            'page' => (string) $page,
            'pageSize' => (string) $pageSize,
        ];

        if ($column !== null && trim($column) !== '') {
            $params['column'] = trim($column);
        }

        $url = $this->baseUrl . '/books/' . rawurlencode($q) . '?' . http_build_query($params);
        return $this->getJson($url);
    }

    public function getBook(string $isbnOrBookId): array
    {
        $id = trim($isbnOrBookId);
        if ($id === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'isbnOrBookId'], 'Missing isbnOrBookId');
        }

        $endpoint = $this->looksLikeIsbn($id) ? '/book/' : '/books/';
        $url = $this->baseUrl . $endpoint . rawurlencode($id);

        return $this->getJson($url);
    }

    public function stats(): array
    {
        return $this->getJson($this->baseUrl . '/stats');
    }

    private function looksLikeIsbn(string $value): bool
    {
        $normalized = preg_replace('/[^0-9Xx]/', '', $value) ?? '';
        $len = strlen($normalized);
        return $len === 10 || $len === 13;
    }

    private function getJson(string $url): array
    {
        $apiKey = trim((string) Env::get('ISBNDB_API_KEY', ''));
        if ($apiKey === '') {
            throw new HttpException(500, 'ISBNDB_MISSING_KEY', [], 'ISBNDB_API_KEY is missing');
        }

        $timeout = (int) Env::get('ISBNDB_TIMEOUT_SECONDS', '8');
        $timeout = max(2, min(20, $timeout));

        $headers = [
            'Accept: application/json',
            'Authorization: ' . $apiKey,
            'x-api-key: ' . $apiKey,
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new HttpException(
                    502,
                    'ISBNDB_UNREACHABLE',
                    ['curl_errno' => $errno, 'curl_error' => $err],
                    'ISBNdb unreachable'
                );
            }

            return $this->decodeAndValidate($raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new HttpException(502, 'ISBNDB_UNREACHABLE', [], 'ISBNdb unreachable');
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
            throw new HttpException(502, 'ISBNDB_ERROR', $details, 'ISBNdb error');
        }

        if (!is_array($json)) {
            throw new HttpException(
                502,
                'ISBNDB_BAD_RESPONSE',
                ['raw' => mb_substr($raw, 0, 800)],
                'ISBNdb bad response'
            );
        }

        return $json;
    }
}
