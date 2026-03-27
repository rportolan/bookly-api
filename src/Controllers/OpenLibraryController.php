<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BookRepository;
use App\Repositories\OpenLibraryCacheRepository;
use App\Repositories\ProgressRepository;
use App\Services\OpenLibraryClient;
use App\Services\ProgressService;

final class OpenLibraryController
{
    public function search(): void
    {
        Auth::requireAuth();

        $q = trim((string) Request::query('q', ''));
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = (int) Request::query('limit', (string) Env::get('OPEN_LIBRARY_MAX_RESULTS', '10'));
        $limit = max(1, min(20, $limit));

        $cacheTtl = (int) (Env::get('OPEN_LIBRARY_CACHE_TTL_SECONDS', '604800'));
        $cacheKey = $this->makeCacheKey($q, $limit);

        $cacheRepo = new OpenLibraryCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            Response::ok($payload);
            return;
        }

        $client = new OpenLibraryClient();
        $queries = $this->buildQueryVariants($q);

        $items = [];
        $seen = [];

        foreach ($queries as $query) {
            try {
                $raw = $this->looksLikeIsbn($q)
                    ? $client->searchByIsbn($this->normalizeIsbn($q))
                    : $client->searchBooks($query, $limit);

                foreach (($raw['docs'] ?? []) as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }

                    $normalized = $this->normalizeDocItem($doc);
                    if ($normalized === null) {
                        continue;
                    }

                    $key = $this->openLibraryItemDedupKey($normalized);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $items[] = $normalized;
                    }

                    if (count($items) >= $limit) {
                        break 2;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][OpenLibrary search] query failed: ' . $query . ' :: ' . $e->getMessage());
            }
        }

        $response = [
            'meta' => [
                'cacheHit' => false,
                'totalItems' => count($items),
            ],
            'items' => array_slice($items, 0, $limit),
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

        $editionId = trim((string) ($body['openLibraryEditionId'] ?? ''));
        if ($editionId === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'openLibraryEditionId'],
                'Missing openLibraryEditionId'
            );
        }

        $client = new OpenLibraryClient();
        $edition = $client->getEdition($editionId);
        $payload = $this->mapEditionToBookPayload($client, $edition);

        $payload['status'] = $body['status'] ?? 'À lire';
        $payload['progressPages'] = isset($body['progressPages']) ? (int) $body['progressPages'] : 0;

        $repo = new BookRepository();

        $catalogBookId = $repo->upsertCatalogBookFromOpenLibrary($payload);
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
                error_log('[BOOKLY][XP] award BOOK_CREATED (open library import) failed: ' . $e->getMessage());
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

    private function openLibraryItemDedupKey(array $item): string
    {
        $isbn13 = trim((string) ($item['isbn13'] ?? ''));
        if ($isbn13 !== '') {
            return 'isbn13:' . $isbn13;
        }

        $editionId = trim((string) ($item['openLibraryEditionId'] ?? ''));
        if ($editionId !== '') {
            return 'olid:' . $editionId;
        }

        return 'fallback:' . mb_strtolower(trim((string) ($item['title'] ?? ''))) . '|' .
            mb_strtolower(trim((string) ($item['author'] ?? '')));
    }

    private function makeCacheKey(string $q, int $limit): string
    {
        $norm = $this->normalizeQuery($q);

        return hash('sha256', json_encode([
            'q' => $norm,
            'limit' => $limit,
            'strategy' => 'multi_try_v1',
        ], JSON_UNESCAPED_UNICODE));
    }

    private function normalizeQuery(string $q): string
    {
        $q = trim(mb_strtolower($q));
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        return $q;
    }

    private function normalizeDocItem(array $doc): ?array
    {
        $title = trim((string) ($doc['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $editionId = $this->pickEditionId($doc);
        if ($editionId === null || $editionId === '') {
            return null;
        }

        $authorNames = $doc['author_name'] ?? [];
        if (!is_array($authorNames)) {
            $authorNames = [];
        }

        $publishers = $doc['publisher'] ?? [];
        if (!is_array($publishers)) {
            $publishers = [];
        }

        $isbns = $doc['isbn'] ?? [];
        if (!is_array($isbns)) {
            $isbns = [];
        }

        [$isbn10, $isbn13] = $this->extractIsbns($isbns);

        $workId = $this->extractWorkIdFromDoc($doc);
        $coverUrl = $this->buildCoverUrlForDoc($doc, $editionId);

        return [
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => $title,
            'author' => count($authorNames) ? trim((string) $authorNames[0]) : '',
            'authors' => array_values(array_filter(array_map(
                fn($a) => trim((string) $a),
                $authorNames
            ), fn($a) => $a !== '')),
            'publisher' => count($publishers) ? trim((string) $publishers[0]) : null,
            'pages' => isset($doc['number_of_pages_median']) && is_numeric($doc['number_of_pages_median'])
                ? (int) $doc['number_of_pages_median']
                : 0,
            'coverUrl' => $coverUrl,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $doc['first_publish_year'] ?? null,
            'description' => null,
            'language' => $this->normalizeLanguage($doc['language'] ?? null),
            'genre' => $this->simplifyGenre($doc['subject'] ?? null),
        ];
    }

    private function pickEditionId(array $doc): ?string
    {
        $editionKeys = $doc['edition_key'] ?? null;
        if (is_array($editionKeys)) {
            foreach ($editionKeys as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    return $key;
                }
            }
        }

        $coverEditionKey = trim((string) ($doc['cover_edition_key'] ?? ''));
        if ($coverEditionKey !== '') {
            return $coverEditionKey;
        }

        $lendingEdition = trim((string) ($doc['lending_edition_s'] ?? ''));
        if ($lendingEdition !== '') {
            return $lendingEdition;
        }

        return null;
    }

    private function extractWorkIdFromDoc(array $doc): ?string
    {
        $key = trim((string) ($doc['key'] ?? ''));
        if ($key !== '' && str_starts_with($key, '/works/')) {
            return $this->extractOlid($key, '/works/');
        }

        $seed = $doc['seed'] ?? null;
        if (is_array($seed)) {
            foreach ($seed as $s) {
                $s = trim((string) $s);
                if (str_starts_with($s, '/works/')) {
                    return $this->extractOlid($s, '/works/');
                }
            }
        }

        return null;
    }

    private function buildCoverUrlForDoc(array $doc, string $editionId): ?string
    {
        $client = new OpenLibraryClient();

        if (isset($doc['cover_i']) && is_numeric($doc['cover_i'])) {
            return $client->coverUrlFromCoverId((int) $doc['cover_i'], 'L');
        }

        if ($editionId !== '') {
            return $client->coverUrlFromEdition($editionId, 'L');
        }

        return null;
    }

    private function normalizeLanguage(mixed $languages): ?string
    {
        if (!is_array($languages) || count($languages) === 0) {
            return null;
        }

        foreach ($languages as $lang) {
            $s = trim((string) $lang);
            if ($s !== '') {
                return $s;
            }
        }

        return null;
    }

    private function extractIsbns(array $isbns): array
    {
        $isbn10 = null;
        $isbn13 = null;

        foreach ($isbns as $isbn) {
            $isbn = preg_replace('/[^0-9Xx]/', '', (string) $isbn) ?? '';
            $len = strlen($isbn);

            if ($len === 10 && $isbn10 === null) {
                $isbn10 = $isbn;
            }

            if ($len === 13 && $isbn13 === null) {
                $isbn13 = $isbn;
            }

            if ($isbn10 !== null && $isbn13 !== null) {
                break;
            }
        }

        return [$isbn10, $isbn13];
    }

    private function mapEditionToBookPayload(OpenLibraryClient $client, array $edition): array
    {
        $editionId = $this->extractOlid($edition['key'] ?? null, '/books/');
        if ($editionId === null || $editionId === '') {
            throw new HttpException(
                502,
                'OPEN_LIBRARY_BAD_RESPONSE',
                ['reason' => 'missing edition key'],
                'Open Library bad response'
            );
        }

        $workId = null;
        $work = null;
        if (isset($edition['works']) && is_array($edition['works']) && count($edition['works']) > 0) {
            $workKey = $edition['works'][0]['key'] ?? null;
            $workId = $this->extractOlid($workKey, '/works/');

            if ($workId) {
                try {
                    $work = $client->getWork($workId);
                } catch (\Throwable $e) {
                    error_log('[BOOKLY][OpenLibrary] getWork failed: ' . $e->getMessage());
                }
            }
        }

        $authors = $this->resolveEditionAuthors($client, $edition);

        $publisher = null;
        if (isset($edition['publishers']) && is_array($edition['publishers']) && count($edition['publishers']) > 0) {
            $publisher = trim((string) $edition['publishers'][0]) ?: null;
        }

        $isbn10 = null;
        if (isset($edition['isbn_10']) && is_array($edition['isbn_10']) && count($edition['isbn_10']) > 0) {
            $isbn10 = trim((string) $edition['isbn_10'][0]) ?: null;
        }

        $isbn13 = null;
        if (isset($edition['isbn_13']) && is_array($edition['isbn_13']) && count($edition['isbn_13']) > 0) {
            $isbn13 = trim((string) $edition['isbn_13'][0]) ?: null;
        }

        $genre = null;
        if (is_array($work)) {
            $genre = $this->simplifyGenre($work['subjects'] ?? null);
        }

        $summary = $this->extractDescription($edition);
        if ($summary === null && is_array($work)) {
            $summary = $this->extractDescription($work);
        }

        $pages = 0;
        if (isset($edition['number_of_pages']) && is_numeric($edition['number_of_pages'])) {
            $pages = (int) $edition['number_of_pages'];
        }

        $coverUrl = null;
        if (isset($edition['covers']) && is_array($edition['covers']) && count($edition['covers']) > 0 && is_numeric($edition['covers'][0])) {
            $coverUrl = $client->coverUrlFromCoverId((int) $edition['covers'][0], 'L');
        } else {
            $coverUrl = $client->coverUrlFromEdition($editionId, 'L');
        }

        return [
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => (string) ($edition['title'] ?? ''),
            'author' => count($authors) ? (string) $authors[0] : '',
            'genre' => $genre,
            'pages' => $pages,
            'publisher' => $publisher,
            'coverUrl' => $coverUrl,
            'summary' => $summary,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
        ];
    }

    private function resolveEditionAuthors(OpenLibraryClient $client, array $edition): array
    {
        $names = [];
        $authors = $edition['authors'] ?? [];

        if (!is_array($authors)) {
            return $names;
        }

        foreach ($authors as $authorRef) {
            if (!is_array($authorRef)) {
                continue;
            }

            $key = $authorRef['key'] ?? ($authorRef['author']['key'] ?? null);
            $authorId = $this->extractOlid($key, '/authors/');
            if (!$authorId) {
                continue;
            }

            try {
                $author = $client->getAuthor($authorId);
                $name = trim((string) ($author['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][OpenLibrary] getAuthor failed: ' . $e->getMessage());
            }
        }

        return array_values(array_unique($names));
    }

    private function extractDescription(array $data): ?string
    {
        $desc = $data['description'] ?? null;

        if (is_string($desc)) {
            $desc = trim($desc);
            return $desc !== '' ? $desc : null;
        }

        if (is_array($desc)) {
            $value = trim((string) ($desc['value'] ?? ''));
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function extractOlid(mixed $value, string $prefix): ?string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (str_starts_with($s, $prefix)) {
            $s = substr($s, strlen($prefix));
        }

        $s = trim($s, '/');
        return $s !== '' ? $s : null;
    }

    private function simplifyGenre(mixed $subjects): ?string
    {
        if (!is_array($subjects) || count($subjects) === 0) {
            return null;
        }

        foreach ($subjects as $subject) {
            $s = trim((string) $subject);
            if ($s === '') {
                continue;
            }

            if (mb_strlen($s) > 60) {
                $s = mb_substr($s, 0, 60);
            }

            return $s;
        }

        return null;
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