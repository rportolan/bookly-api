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
use App\Repositories\ProgressRepository;
use App\Services\GoogleBooksClient;
use App\Services\ProgressService;
use App\Services\TextSanitizer;

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
        $payload['status'] = $body['status'] ?? 'À lire';
        $payload['progressPages'] = isset($body['progressPages']) ? (int)$body['progressPages'] : 0;

        $repo = new BookRepository();

        $catalogBookId = $repo->upsertCatalogBookFromGoogle($payload);
        $isNewUserBook = !$repo->userBookExists($userId, $catalogBookId);

        $row = $repo->createUserBookIfNotExists($userId, $catalogBookId, $payload);

        $response = $this->mapUserBookRow($row);

        $rewardPayload = null;

        if ($isNewUserBook) {
            try {
                $userBookId = (int)($row['id'] ?? 0);

                $pr = new ProgressRepository();
                $already = $pr->hasEventMeta($userId, 'BOOK_CREATED', 'userBookId', $userBookId);

                if (!$already) {
                    $rewardPayload = (new ProgressService())->award($userId, 'BOOK_CREATED', 0, [
                        'userBookId' => $userBookId,
                        'bookId'     => $catalogBookId,
                        'title'      => (string)($row['title'] ?? ''),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award BOOK_CREATED (google import) failed: ' . $e->getMessage());
            }
        }

        // Important : on renvoie EXACTEMENT la forme attendue par handleLevelUpResponse
        $response['progress'] = is_array($rewardPayload) ? ($rewardPayload['progress'] ?? null) : null;
        $response['levelUp'] = is_array($rewardPayload)
            ? ($rewardPayload['levelUp'] ?? ['happened' => false])
            : ['happened' => false];
        $response['cardUnlock'] = is_array($rewardPayload)
            ? ($rewardPayload['cardUnlock'] ?? ['happened' => false, 'cards' => []])
            : ['happened' => false, 'cards' => []];
        $response['awardedXp'] = is_array($rewardPayload) ? (int)($rewardPayload['awardedXp'] ?? 0) : 0;
        $response['awardType'] = is_array($rewardPayload) ? ($rewardPayload['awardType'] ?? 'BOOK_CREATED') : 'BOOK_CREATED';

        Response::created($response);
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

        $genre = $this->simplifyGenre($info['categories'] ?? null);
        $desc = TextSanitizer::htmlToText($info['description'] ?? null);

        return [
            'googleVolumeId' => $id,
            'title' => $title,
            'author' => $author,
            'authors' => array_values(array_map('strval', $authors)),
            'publisher' => $publisher,
            'pages' => $pageCount,
            'coverUrl' => $cover ? str_replace('http://', 'https://', $cover) : null,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $info['publishedDate'] ?? null,
            'description' => $desc,
            'language' => $info['language'] ?? null,
            'genre' => $genre,
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

        $genre = $this->simplifyGenre($info['categories'] ?? null);
        $summary = TextSanitizer::htmlToText($info['description'] ?? null);

        return [
            'googleVolumeId' => (string)($volume['id'] ?? ''),
            'title' => (string)($info['title'] ?? ''),
            'author' => count($authors) ? (string)$authors[0] : '',
            'genre' => $genre,
            'pages' => isset($info['pageCount']) ? (int)$info['pageCount'] : 0,
            'publisher' => $info['publisher'] ?? null,
            'coverUrl' => $cover ? str_replace('http://', 'https://', $cover) : null,
            'summary' => $summary,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
        ];
    }

    private function simplifyGenre(mixed $categories): ?string
    {
        if (!is_array($categories) || count($categories) === 0) return null;

        $raw = null;
        foreach ($categories as $c) {
            $s = trim((string)$c);
            if ($s !== '') { $raw = $s; break; }
        }
        if (!$raw) return null;

        $parts = preg_split('/\s*\/\s*|\s*>\s*|\s*-\s*/', $raw) ?: [$raw];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));

        if (count($parts) === 0) return null;

        $lower = array_map(fn($p) => mb_strtolower($p), $parts);

        $generic = [
            'fiction', 'nonfiction', 'non-fiction', 'general', 'général', 'books', 'literature', 'littérature',
        ];

        $idxFiction = array_search('fiction', $lower, true);
        if ($idxFiction !== false && isset($parts[$idxFiction + 1])) {
            return $this->capGenre($parts[$idxFiction + 1]);
        }

        foreach ($parts as $p) {
            if (!in_array(mb_strtolower($p), $generic, true)) {
                return $this->capGenre($p);
            }
        }

        return $this->capGenre($parts[count($parts) - 1]);
    }

    private function capGenre(string $g): ?string
    {
        $g = trim($g);
        if ($g === '') return null;

        if (mb_strlen($g) > 60) {
            $g = mb_substr($g, 0, 60);
        }

        return $g;
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