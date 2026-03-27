<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

final class OpenLibraryClient
{
    private string $baseUrl = 'https://openlibrary.org';

    public function searchBooks(string $query, int $limit = 10): array
    {
        $q = trim($query);
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = max(1, min(20, $limit));

        $params = [
            'q' => $q,
            'limit' => (string) $limit,
        ];

        $url = $this->baseUrl . '/search.json?' . http_build_query($params);

        return $this->getJson($url);
    }

    public function getEdition(string $editionId): array
    {
        $editionId = trim($editionId);
        if ($editionId === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'openLibraryEditionId'],
                'Invalid openLibraryEditionId'
            );
        }

        $url = $this->baseUrl . '/books/' . rawurlencode($editionId) . '.json';
        return $this->getJson($url);
    }

    public function getWork(string $workId): array
    {
        $workId = trim($workId);
        if ($workId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'workId'], 'Invalid workId');
        }

        $url = $this->baseUrl . '/works/' . rawurlencode($workId) . '.json';
        return $this->getJson($url);
    }

    public function getAuthor(string $authorId): array
    {
        $authorId = trim($authorId);
        if ($authorId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'authorId'], 'Invalid authorId');
        }

        $url = $this->baseUrl . '/authors/' . rawurlencode($authorId) . '.json';
        return $this->getJson($url);
    }

    public function coverUrlFromEdition(string $editionId, string $size = 'L'): string
    {
        $size = strtoupper(trim($size));
        if (!in_array($size, ['S', 'M', 'L'], true)) {
            $size = 'L';
        }

        return 'https://covers.openlibrary.org/b/olid/' . rawurlencode($editionId) . '-' . $size . '.jpg';
    }

    public function coverUrlFromCoverId(int $coverId, string $size = 'L'): string
    {
        $size = strtoupper(trim($size));
        if (!in_array($size, ['S', 'M', 'L'], true)) {
            $size = 'L';
        }

        return 'https://covers.openlibrary.org/b/id/' . $coverId . '-' . $size . '.jpg';
    }

    private function getJson(string $url): array
    {
        $timeout = (int) Env::get('OPEN_LIBRARY_TIMEOUT_SECONDS', '6');
        $timeout = max(2, min(20, $timeout));

        $userAgent = Env::get('OPEN_LIBRARY_USER_AGENT', 'Bookly/1.0');

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: ' . $userAgent,
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
                    'OPEN_LIBRARY_UNREACHABLE',
                    ['curl_errno' => $errno, 'curl_error' => $err],
                    'Open Library unreachable'
                );
            }

            return $this->decodeAndValidate($raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "Accept: application/json\r\nUser-Agent: {$userAgent}\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new HttpException(502, 'OPEN_LIBRARY_UNREACHABLE', [], 'Open Library unreachable');
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

            throw new HttpException(
                502,
                'OPEN_LIBRARY_ERROR',
                $details,
                'Open Library error'
            );
        }

        if (!is_array($json)) {
            throw new HttpException(
                502,
                'OPEN_LIBRARY_BAD_RESPONSE',
                ['raw' => mb_substr($raw, 0, 800)],
                'Open Library bad response'
            );
        }

        return $json;
    }
}