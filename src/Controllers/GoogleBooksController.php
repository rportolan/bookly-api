<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\GoogleBooksCacheRepository;
use App\Repositories\BookRepository;
use App\Services\GoogleBooksClient;

final class GoogleBooksController
{
    public function search(): void
    {
        Auth::requireAuth();

        $q = trim((string)Request::query('q', ''));
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = (int)Request::query('limit', (string)Env::get('GOOGLE_BOOKS_MAX_RESULTS', '10'));
        $limit = max(1, min(20, $limit));

        $lang = Env::get('GOOGLE_BOOKS_LANG', 'fr') ?? 'fr';
        $country = Env::get('GOOGLE_BOOKS_COUNTRY', 'FR') ?? 'FR';

        $cacheTtl = (int)Env::get('GOOGLE_BOOKS_CACHE_TTL_SECONDS', '604800'); // 7 jours

        $cacheKey = $this->makeCacheKey($q, $limit, $lang, $country);

        $cacheRepo = new GoogleBooksCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            Response::ok($this->normalizeSearchResponse($payload, true));
            return;
        }

        $client = new GoogleBooksClient();
        $raw = $client->searchVolumes($q, $limit, $lang, $country);

        $cacheRepo->upsert($cacheKey, $q, json_encode($raw, JSON_UNESCAPED_UNICODE), $cacheTtl);

        Response::ok($this->normalizeSearchResponse($raw, false));
    }

    public function import(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $volumeId = trim((string)($body['googleVolumeId'] ?? ''));
        if ($volumeId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'googleVolumeId'], 'Missing googleVolumeId');
        }

        $lang = Env::get('GOOGLE_BOOKS_LANG', 'fr') ?? 'fr';
        $country = Env::get('GOOGLE_BOOKS_COUNTRY', 'FR') ?? 'FR';

        $client = new GoogleBooksClient();
        $volume = $client->getVolume($volumeId, $lang, $country);

        $payload = $this->mapGoogleVolumeToBookPayload($volume);

        // overrides user-related
        $payload['status'] = $body['status'] ?? 'À lire';
        $payload['progressPages'] = isset($body['progressPages']) ? (int)$body['progressPages'] : 0;

        $repo = new BookRepository();

        $catalogBookId = $repo->upsertCatalogBookFromGoogle($payload);
        $row = $repo->createUserBookIfNotExists($userId, $catalogBookId, $payload);

        Response::created($this->mapUserBookRow($row));
    }

    private function makeCacheKey(string $q, int $limit, string $lang, string $country): string
    {
        $norm = $this->normalizeQuery($q);
        return hash('sha256', json_encode([
            'q' => $norm,
            'limit' => $limit,
            'lang' => strtolower($lang),
            'country' => strtoupper($country),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function normalizeQuery(string $q): string
    {
        $q = trim(mb_strtolower($q));
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        return $q;
    }

    private function normalizeSearchResponse(array $raw, bool $cacheHit): array
    {
        $items = [];
        foreach (($raw['items'] ?? []) as $it) {
            $items[] = $this->normalizeVolumeItem($it);
        }

        return [
            'meta' => [
                'cacheHit' => $cacheHit,
                'totalItems' => (int)($raw['totalItems'] ?? count($items)),
            ],
            'items' => $items,
        ];
    }

    private function normalizeVolumeItem(array $it): array
    {
        $id = (string)($it['id'] ?? '');
        $info = $it['volumeInfo'] ?? [];

        $title = (string)($info['title'] ?? '');
        $authors = $info['authors'] ?? [];
        if (!is_array($authors)) $authors = [];
        $author = count($authors) ? (string)$authors[0] : '';

        $publisher = $info['publisher'] ?? null;
        $pageCount = isset($info['pageCount']) ? (int)$info['pageCount'] : 0;

        $imageLinks = $info['imageLinks'] ?? [];
        $cover = $imageLinks['thumbnail'] ?? ($imageLinks['smallThumbnail'] ?? null);

        $identifiers = $info['industryIdentifiers'] ?? [];
        [$isbn10, $isbn13] = $this->extractIsbns($identifiers);

        return [
            'googleVolumeId' => $id,
            'title' => $title,
            'author' => $author,
            'authors' => array_values(array_map('strval', $authors)),
            'publisher' => $publisher,
            'pages' => $pageCount,
            'coverUrl' => $cover,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $info['publishedDate'] ?? null,
            'description' => $info['description'] ?? null,
            'language' => $info['language'] ?? null,
        ];
    }

    private function extractIsbns($industryIdentifiers): array
    {
        $isbn10 = null;
        $isbn13 = null;

        if (!is_array($industryIdentifiers)) return [$isbn10, $isbn13];

        foreach ($industryIdentifiers as $id) {
            if (!is_array($id)) continue;
            $type = (string)($id['type'] ?? '');
            $identifier = (string)($id['identifier'] ?? '');
            if ($type === 'ISBN_10') $isbn10 = $identifier;
            if ($type === 'ISBN_13') $isbn13 = $identifier;
        }

        return [$isbn10, $isbn13];
    }

    private function mapGoogleVolumeToBookPayload(array $volume): array
    {
        $info = $volume['volumeInfo'] ?? [];

        $authors = $info['authors'] ?? [];
        if (!is_array($authors)) $authors = [];

        $imageLinks = $info['imageLinks'] ?? [];
        $cover = $imageLinks['thumbnail'] ?? ($imageLinks['smallThumbnail'] ?? null);

        [$isbn10, $isbn13] = $this->extractIsbns($info['industryIdentifiers'] ?? []);

        $genre = null;
        if (isset($info['categories']) && is_array($info['categories']) && count($info['categories']) > 0) {
            $genre = (string)$info['categories'][0];
        }

        return [
            'googleVolumeId' => (string)($volume['id'] ?? ''),
            'title' => (string)($info['title'] ?? ''),
            'author' => count($authors) ? (string)$authors[0] : '',
            'genre' => $genre,
            'pages' => isset($info['pageCount']) ? (int)$info['pageCount'] : 0,
            'publisher' => $info['publisher'] ?? null,
            'coverUrl' => $cover,
            'summary' => $info['description'] ?? null,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
        ];
    }

    private function mapUserBookRow(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'bookId' => (int)$r['bookId'],
            'title' => (string)($r['title'] ?? ''),
            'author' => (string)($r['author'] ?? ''),
            'genre' => $r['genre'] ?? null,
            'pages' => (int)($r['pages'] ?? 0),
            'progressPages' => (int)($r['progress_pages'] ?? 0),
            'status' => (string)($r['status'] ?? 'À lire'),
            'startedAt' => $r['started_at'] ?? null,
            'finishedAt' => $r['finished_at'] ?? null,
            'publisher' => $r['publisher'] ?? null,
            'rating' => isset($r['rating']) && $r['rating'] !== null ? (float)$r['rating'] : null,
            'summary' => $r['summary'] ?? null,
            'analysisWork' => $r['analysis_work'] ?? null,
            'coverUrl' => $r['cover_url'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}
