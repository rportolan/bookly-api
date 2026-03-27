<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BookRepository;
use App\Repositories\GoogleBooksCacheRepository;
use App\Repositories\ProgressRepository;
use App\Services\GoogleBooksClient;
use App\Services\ProgressService;
use App\Services\TextSanitizer;

final class GoogleBooksController
{
    public function search(): void
    {
        Auth::requireAuth();

        $q = trim((string) Request::query('q', ''));
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = (int) Request::query('limit', (string) Env::get('GOOGLE_BOOKS_MAX_RESULTS', '10'));
        $limit = max(1, min(20, $limit));

        $lang = Env::get('GOOGLE_BOOKS_LANG', 'fr') ?? 'fr';
        $country = Env::get('GOOGLE_BOOKS_COUNTRY', 'FR') ?? 'FR';

        $cacheTtl = (int) (Env::get('GOOGLE_BOOKS_CACHE_TTL_SECONDS', '604800'));
        $cacheKey = $this->makeCacheKey($q, $limit, $lang, $country);

        $cacheRepo = new GoogleBooksCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            Response::ok($payload);
            return;
        }

        $client = new GoogleBooksClient();
        $rawQueries = $this->buildQueryVariants($q);

        $normalizedItems = [];
        $seen = [];

        foreach ($rawQueries as $query) {
            try {
                $raw = $this->looksLikeIsbn($q)
                    ? $client->searchByIsbn($this->normalizeIsbn($q), $limit, $lang, $country)
                    : $client->searchVolumes($query, $limit, $lang, $country);

                foreach (($raw['items'] ?? []) as $it) {
                    $normalized = $this->normalizeVolumeItem($it);
                    $key = $this->googleItemDedupKey($normalized);

                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $normalizedItems[] = $normalized;
                    }

                    if (count($normalizedItems) >= $limit) {
                        break 2;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][GoogleBooks search] query failed: ' . $query . ' :: ' . $e->getMessage());
            }
        }

        $response = [
            'meta' => [
                'cacheHit' => false,
                'totalItems' => count($normalizedItems),
            ],
            'items' => array_slice($normalizedItems, 0, $limit),
        ];

        $cacheRepo->upsert(
            $cacheKey,
            $q,
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $cacheTtl
        );

        Response::ok($response);
    }

    public function import(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $volumeId = trim((string) ($body['googleVolumeId'] ?? ''));
        if ($volumeId === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'googleVolumeId'],
                'Missing googleVolumeId'
            );
        }

        $lang = Env::get('GOOGLE_BOOKS_LANG', 'fr') ?? 'fr';
        $country = Env::get('GOOGLE_BOOKS_COUNTRY', 'FR') ?? 'FR';

        $client = new GoogleBooksClient();
        $volume = $client->getVolume($volumeId, $lang, $country);

        $payload = $this->mapGoogleVolumeToBookPayload($volume);
        $payload['status'] = $body['status'] ?? 'À lire';
        $payload['progressPages'] = isset($body['progressPages']) ? (int) $body['progressPages'] : 0;

        $repo = new BookRepository();

        $catalogBookId = $repo->upsertCatalogBookFromGoogle($payload);
        $isNewUserBook = !$repo->userBookExists($userId, $catalogBookId);

        $row = $repo->createUserBookIfNotExists($userId, $catalogBookId, $payload);

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($userId);

        if ($isNewUserBook) {
            try {
                $userBookId = (int) ($row['id'] ?? 0);

                $pr = new ProgressRepository();
                $already = $pr->hasEventMeta($userId, 'BOOK_CREATED', 'userBookId', $userBookId);

                if (!$already) {
                    $progress = $progressService->award($userId, 'BOOK_CREATED', 0, [
                        'userBookId' => $userBookId,
                        'bookId' => $catalogBookId,
                        'title' => (string) ($row['title'] ?? ''),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award BOOK_CREATED (google import) failed: ' . $e->getMessage());
                $progress = $progressService->snapshot($userId);
            }
        }

        Response::created([
            ...$this->mapUserBookRow($row),
            'progress' => $this->onlyProgressSnapshot($progress),
            'levelUp' => $progress['levelUp'] ?? $this->emptyLevelUp(),
            'cardUnlock' => $progress['cardUnlock'] ?? $this->emptyCardUnlock(),
            'awardedXp' => (int) ($progress['awardedXp'] ?? 0),
            'awardType' => (string) ($progress['awardType'] ?? 'BOOK_CREATED'),
        ]);
    }

    private function buildQueryVariants(string $q): array
    {
        $base = trim($q);
        $clean = preg_replace('/\s+/', ' ', $base) ?? $base;
        $clean = trim($clean);

        if ($this->looksLikeIsbn($clean)) {
            return [$this->normalizeIsbn($clean)];
        }

        $variants = [];
        $variants[] = $clean;

        $normalizedTitle = str_replace('-', ' ', $clean);
        if ($normalizedTitle !== $clean) {
            $variants[] = $normalizedTitle;
        }

        if (!str_contains(mb_strtolower($clean), 'folio')) {
            $variants[] = $clean . ' folio';
        }

        if (!str_contains(mb_strtolower($clean), 'gallimard')) {
            $variants[] = $clean . ' gallimard';
        }

        $authorGuess = $this->guessAuthorFromQuery($clean);
        if ($authorGuess !== null) {
            $variants[] = $clean . ' ' . $authorGuess;
        }

        return array_values(array_unique(array_filter(array_map('trim', $variants))));
    }

    private function guessAuthorFromQuery(string $q): ?string
    {
        $lower = mb_strtolower($q);

        $map = [
            'bel ami' => 'maupassant',
            'bel-ami' => 'maupassant',
            'etranger' => 'camus',
            'l étranger' => 'camus',
            'l\'étranger' => 'camus',
            'madame bovary' => 'flaubert',
            'germinal' => 'zola',
        ];

        foreach ($map as $needle => $author) {
            if (str_contains($lower, $needle)) {
                return $author;
            }
        }

        return null;
    }

    private function looksLikeIsbn(string $q): bool
    {
        $isbn = $this->normalizeIsbn($q);
        $len = strlen($isbn);
        return $len === 10 || $len === 13;
    }

    private function normalizeIsbn(string $q): string
    {
        return preg_replace('/[^0-9Xx]/', '', trim($q)) ?? '';
    }

    private function googleItemDedupKey(array $item): string
    {
        $isbn13 = trim((string) ($item['isbn13'] ?? ''));
        if ($isbn13 !== '') {
            return 'isbn13:' . $isbn13;
        }

        $googleId = trim((string) ($item['googleVolumeId'] ?? ''));
        if ($googleId !== '') {
            return 'gid:' . $googleId;
        }

        return 'fallback:' . mb_strtolower(trim((string) ($item['title'] ?? ''))) . '|' .
            mb_strtolower(trim((string) ($item['author'] ?? '')));
    }

    private function makeCacheKey(string $q, int $limit, string $lang, string $country): string
    {
        $norm = $this->normalizeQuery($q);

        return hash('sha256', json_encode([
            'q' => $norm,
            'limit' => $limit,
            'lang' => strtolower($lang),
            'country' => strtoupper($country),
            'strategy' => 'multi_try_v1',
        ], JSON_UNESCAPED_UNICODE));
    }

    private function normalizeQuery(string $q): string
    {
        $q = trim(mb_strtolower($q));
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        return $q;
    }

    private function normalizeVolumeItem(array $it): array
    {
        $id = (string) ($it['id'] ?? '');
        $info = $it['volumeInfo'] ?? [];

        $title = (string) ($info['title'] ?? '');

        $authors = $info['authors'] ?? [];
        if (!is_array($authors)) {
            $authors = [];
        }
        $author = count($authors) ? (string) $authors[0] : '';

        $publisher = $info['publisher'] ?? null;
        $pageCount = isset($info['pageCount']) ? (int) $info['pageCount'] : 0;

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

        if (!is_array($industryIdentifiers)) {
            return [$isbn10, $isbn13];
        }

        foreach ($industryIdentifiers as $id) {
            if (!is_array($id)) {
                continue;
            }

            $type = (string) ($id['type'] ?? '');
            $identifier = (string) ($id['identifier'] ?? '');

            if ($type === 'ISBN_10') {
                $isbn10 = $identifier;
            }

            if ($type === 'ISBN_13') {
                $isbn13 = $identifier;
            }
        }

        return [$isbn10, $isbn13];
    }

    private function mapGoogleVolumeToBookPayload(array $volume): array
    {
        $info = $volume['volumeInfo'] ?? [];

        $authors = $info['authors'] ?? [];
        if (!is_array($authors)) {
            $authors = [];
        }

        $imageLinks = $info['imageLinks'] ?? [];
        $cover = $imageLinks['thumbnail'] ?? ($imageLinks['smallThumbnail'] ?? null);

        [$isbn10, $isbn13] = $this->extractIsbns($info['industryIdentifiers'] ?? []);

        $genre = $this->simplifyGenre($info['categories'] ?? null);
        $summary = TextSanitizer::htmlToText($info['description'] ?? null);

        return [
            'googleVolumeId' => (string) ($volume['id'] ?? ''),
            'title' => (string) ($info['title'] ?? ''),
            'author' => count($authors) ? (string) $authors[0] : '',
            'genre' => $genre,
            'pages' => isset($info['pageCount']) ? (int) $info['pageCount'] : 0,
            'publisher' => $info['publisher'] ?? null,
            'coverUrl' => $cover ? str_replace('http://', 'https://', $cover) : null,
            'summary' => $summary,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
        ];
    }

    private function simplifyGenre(mixed $categories): ?string
    {
        if (!is_array($categories) || count($categories) === 0) {
            return null;
        }

        $raw = null;
        foreach ($categories as $c) {
            $s = trim((string) $c);
            if ($s !== '') {
                $raw = $s;
                break;
            }
        }

        if (!$raw) {
            return null;
        }

        $parts = preg_split('/\s*\/\s*|\s*>\s*|\s*-\s*/', $raw) ?: [$raw];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($x) => $x !== ''));

        if (count($parts) === 0) {
            return null;
        }

        $lower = array_map(fn ($p) => mb_strtolower($p), $parts);

        $generic = [
            'fiction',
            'nonfiction',
            'non-fiction',
            'general',
            'général',
            'books',
            'literature',
            'littérature',
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
        if ($g === '') {
            return null;
        }

        if (mb_strlen($g) > 60) {
            $g = mb_substr($g, 0, 60);
        }

        return $g;
    }

    private function onlyProgressSnapshot(array $progress): array
    {
        return [
            'xp' => (int) ($progress['xp'] ?? 0),
            'level' => (int) ($progress['level'] ?? 1),
            'title' => (string) ($progress['title'] ?? 'Lecteur novice'),
            'progressPct' => (int) ($progress['progressPct'] ?? 0),
            'xpToNext' => (int) ($progress['xpToNext'] ?? 0),
            'levelXp' => (int) ($progress['levelXp'] ?? 0),
            'levelXpSpan' => (int) ($progress['levelXpSpan'] ?? 1),
        ];
    }

    private function emptyLevelUp(): array
    {
        return [
            'happened' => false,
            'previousLevel' => 1,
            'newLevel' => 1,
            'previousTitle' => 'Lecteur novice',
            'newTitle' => 'Lecteur novice',
        ];
    }

    private function emptyCardUnlock(): array
    {
        return [
            'happened' => false,
            'cards' => [],
        ];
    }

    private function mapUserBookRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'bookId' => (int) $r['bookId'],
            'title' => (string) ($r['title'] ?? ''),
            'author' => (string) ($r['author'] ?? ''),
            'genre' => $r['genre'] ?? null,
            'pages' => (int) ($r['pages'] ?? 0),
            'progressPages' => (int) ($r['progress_pages'] ?? 0),
            'status' => (string) ($r['status'] ?? 'À lire'),
            'startedAt' => $r['started_at'] ?? null,
            'finishedAt' => $r['finished_at'] ?? null,
            'publisher' => $r['publisher'] ?? null,
            'rating' => isset($r['rating']) && $r['rating'] !== null ? (float) $r['rating'] : null,
            'summary' => $r['summary'] ?? null,
            'analysisWork' => $r['analysis_work'] ?? null,
            'coverUrl' => $r['cover_url'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}